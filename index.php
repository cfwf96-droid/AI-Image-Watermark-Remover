<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL & ~E_DEPRECATED);

// 生成绝对HTTP路径
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$absoluteWebPath = $protocol . $host . $basePath;

$pythonCmd = 'C:\Python\Python312\python.exe';

// 目录配置
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
$processedDir = __DIR__ . DIRECTORY_SEPARATOR . 'processed' . DIRECTORY_SEPARATOR;
$webUploadDir = $absoluteWebPath . '/uploads/';
$webProcessedDir = $absoluteWebPath . '/processed/';

// 目录初始化&权限设置（仅创建目录，不清理文件）
!is_dir($uploadDir) && (mkdir($uploadDir, 0777, true) && chmod($uploadDir, 0777));
!is_dir($processedDir) && (mkdir($processedDir, 0777, true) && chmod($processedDir, 0777));

$uploadedFiles = [];
$processResults = [];
$error = '';
$success = '';
$manualResult = '';

// ========== 新增：仅当用户主动请求时才清理文件 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean_files'])) {
    // 清理上传目录
    array_map('unlink', array_filter((array) glob($uploadDir . '*')));
    // 清理处理后目录
    array_map('unlink', array_filter((array) glob($processedDir . '*')));
    $success = '✅ 所有文件已成功清理 / All files cleaned successfully!';
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images'])) {
    $images = $_FILES['images'];
    $fileCount = is_array($images['name']) ? count($images['name']) : 0;
    
    if ($fileCount > 10) {
        $error = '上传数量超过上限（最多10张）/ Upload limit exceeded (max 10 files)';
    } else {
        $allowedExts = ['jpg', 'jpeg', 'bmp', 'png'];
        for ($i = 0; $i < $fileCount; $i++) {
            // 防呆：检查每个文件项是否存在
            if (!isset($images['name'][$i]) || !isset($images['tmp_name'][$i]) || !isset($images['error'][$i])) {
                continue;
            }
            
            $fileName = $images['name'][$i];
            $fileTmp = $images['tmp_name'][$i];
            $fileError = $images['error'][$i];
            
            if ($fileError !== UPLOAD_ERR_OK) {
                $error = "文件 {$fileName} 上传失败 / Error code: {$fileError}";
                continue;
            }
            
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                $error = "文件 {$fileName} 格式不支持 / Only jpg/bmp/png allowed";
                continue;
            }
            
            $uniqueName = uniqid('img_') . '.' . $ext;
            $uploadPath = $uploadDir . $uniqueName;
            
            if (move_uploaded_file($fileTmp, $uploadPath)) {
                chmod($uploadPath, 0777);
                // 确保数组键名正确赋值（核心：防止后续引用未定义）
                $uploadedFiles[$uniqueName] = [
                    'original' => $uploadPath,
                    'original_name' => $fileName, // 确保此键名存在
                    'ext' => $ext,
                    'web_path' => $webUploadDir . $uniqueName
                ];
            } else {
                $error = "文件 {$fileName} 保存失败 / Failed to save file";
            }
        }
        
        // 调用Python处理
        if (!empty($uploadedFiles)) {
            $pythonCmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'python' : 'python3';
            
            foreach ($uploadedFiles as $uniqueName => $fileInfo) {
                $pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'python_scripts' . DIRECTORY_SEPARATOR . 'watermark_handler.py';
                $originalPath = escapeshellarg(str_replace('\\', '/', $fileInfo['original']));
                $processedFileName = str_replace('.' . $fileInfo['ext'], '_RmWaterMark.' . $fileInfo['ext'], $uniqueName);
                $processedPath = $processedDir . $processedFileName;
                $processedPathArg = escapeshellarg(str_replace('\\', '/', $processedPath));
                
                $command = "{$pythonCmd} {$pythonScript} detect_and_remove {$originalPath} {$processedPathArg}";
                $output = shell_exec($command . ' 2>&1') ?: '';
                $trimmedOutput = trim($output);
                $result = $trimmedOutput ? json_decode($trimmedOutput, true) : null;
                
                // 确保processResults数组键名完整且正确
                $processResults[$uniqueName] = [
                    'original_name' => $fileInfo['original_name'], // 必赋值
                    'original_path' => $fileInfo['original'],     // 必赋值（修正拼写错误的核心）
                    'original_web_path' => $fileInfo['web_path'], // 必赋值
                    'processed_path' => '',
                    'processed_web_path' => '',
                    'has_watermark' => false,
                    'message' => '待处理 / Pending'
                ];
                
                if ($result && isset($result['has_watermark'])) {
                    $processResults[$uniqueName]['has_watermark'] = $result['has_watermark'];
                    $processResults[$uniqueName]['processed_path'] = $result['has_watermark'] ? $processedPath : '';
                    $processResults[$uniqueName]['processed_web_path'] = $result['has_watermark'] ? $webProcessedDir . $processedFileName : '';
                    $processResults[$uniqueName]['message'] = $result['has_watermark'] 
                        ? '检测到水印并已去除 / Watermark detected and removed' 
                        : '没有水印 / No watermark';
                } else {
                    $processResults[$uniqueName]['message'] = "处理失败 / Process failed: " . ($trimmedOutput ?: 'No output');
                }
            }
            $success = '上传成功！/ Upload successful!';
        }
    }
}

// 处理手工去水印（AI修复）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_remove'])) {
    // 防呆：检查所有必要参数
    $imgPath = isset($_POST['img_path']) ? $_POST['img_path'] : '';
    $x1 = isset($_POST['x1']) ? $_POST['x1'] : 0;
    $y1 = isset($_POST['y1']) ? $_POST['y1'] : 0;
    $x2 = isset($_POST['x2']) ? $_POST['x2'] : 0;
    $y2 = isset($_POST['y2']) ? $_POST['y2'] : 0;
    $uniqueName = isset($_POST['unique_name']) ? $_POST['unique_name'] : '';
    
    // 路径安全验证
    if (!empty($imgPath) && (strpos($imgPath, $uploadDir) === 0 || strpos($imgPath, $processedDir) === 0)) {
        $ext = pathinfo($imgPath, PATHINFO_EXTENSION);
        $processedFileName = basename($imgPath, '.' . $ext) . '_AI_Removed.' . $ext;
        $processedPath = $processedDir . $processedFileName;
        
        // Python调用（绝对路径+参数校验）
        $pythonCmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'python' : 'python3';
        $pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'python_scripts' . DIRECTORY_SEPARATOR . 'watermark_handler.py';
        $imgPathArg = escapeshellarg(str_replace('\\', '/', $imgPath));
        $processedPathArg = escapeshellarg(str_replace('\\', '/', $processedPath));
        
        // 确保参数为数字
        $x1 = is_numeric($x1) ? $x1 : 0;
        $y1 = is_numeric($y1) ? $y1 : 0;
        $x2 = is_numeric($x2) ? $x2 : 0;
        $y2 = is_numeric($y2) ? $y2 : 0;
        
        // 调用AI手工去除（矩形选区）
        $command = "{$pythonCmd} {$pythonScript} ai_manual_remove {$imgPathArg} {$processedPathArg} {$x1} {$y1} {$x2} {$y2}";
        $output = shell_exec($command . ' 2>&1') ?: '';
        $result = json_decode(trim($output), true);
        
        if ($result && $result['success']) {
            $webProcessedPath = $webProcessedDir . $processedFileName;
            $manualResult = "<div class='alert alert-success'>✅ AI手工去水印完成！<a href='?download=1&file=" . urlencode($processedPath) . "' class='text-blue-600 underline'>点击下载修复后图片</a></div>";
            // 更新结果列表（确保键名存在）
            if (isset($processResults[$uniqueName])) {
                $processResults[$uniqueName]['processed_path'] = $processedPath;
                $processResults[$uniqueName]['processed_web_path'] = $webProcessedPath;
                $processResults[$uniqueName]['message'] = 'AI手工去水印已完成 / AI Manual removal completed';
            }
        } else {
            $errorMsg = isset($result['error']) ? $result['error'] : $output;
            $manualResult = "<div class='alert alert-error'>❌ 手工去水印失败：{$errorMsg}</div>";
        }
    } else {
        $manualResult = "<div class='alert alert-error'>❌ 非法路径 / Invalid path</div>";
    }
}

// 处理下载（仅保留单文件下载，删除批量下载逻辑）
if (isset($_GET['download'])) {
    $file = urldecode($_GET['file']);
    if (file_exists($file) && (strpos($file, $uploadDir) === 0 || strpos($file, $processedDir) === 0)) {
        $fileName = basename($file);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache');
        readfile($file);
        exit;
    } else {
        $error = '文件不存在 / File not found';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI图片水印去除工具 / AI Watermark Remover</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Microsoft Yahei", Arial, sans-serif; }
        body { background: #f8f9fa; padding: 20px; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; color: #333; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .upload-box { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .file-input { margin: 15px 0; padding: 10px; border: 1px solid #ddd; border-radius: 5px; width: 100%; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; color: white; font-size: 16px; transition: all 0.2s; }
        .btn-blue { background: #007bff; }
        .btn-blue:hover { background: #0069d9; }
        .btn-green { background: #28a745; }
        .btn-green:hover { background: #218838; }
        .btn-orange { background: #fd7e14; }
        .btn-orange:hover { background: #e06800; }
        .btn-gray { background: #6c757d; }
        .btn-gray:hover { background: #5a6268; }
        .btn-red { background: #dc3545; }
        .btn-red:hover { background: #c82333; }
        .result-box { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .result-item { border: 1px solid #eee; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .preview-img { max-height: 250px; max-width: 100%; border-radius: 6px; margin: 15px 0; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 15px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        
        /* 模态框样式 */
        #manual-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 90%;
            width: 900px;
            max-height: 90vh;
            overflow: auto;
        }
        #canvas-container {
            position: relative;
            width: 100%;
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
        }
        #manual-canvas {
            width: 100%;
            display: block;
            background: #f9f9f9;
        }
        .selection-info {
            margin: 15px 0;
            padding: 10px;
            background: #e9f5ff;
            border-radius: 5px;
            font-size: 14px;
            color: #007bff;
        }
        .brush-control { margin: 20px 0; }
        .btn-group { margin-top: 25px; display: flex; gap: 15px; justify-content: flex-end; }
        .status { margin: 15px 0; padding: 12px; border-radius: 6px; font-size: 14px; }
        .status-loading { background: #e9f5ff; color: #007bff; }
        .status-success { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        .clean-btn-container { margin: 20px 0; text-align: right; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>AI图片水印去除工具 / AI Image Watermark Remover</h1>
            <p>支持批量上传 | 自动检测+AI手工去除 | 支持JPG/BMP/PNG</p>
        </div>

        <!-- 强制HTTP访问提示 -->
        <div class="alert alert-warning">
            <strong>⚠️ 重要提示：</strong> 请使用完毕后及时按“清理所有文件”删除您上传与处理后的图片文件，注意保护隐私，自负其责。上传照片一次最多10张，请注意图片格式，祝使用愉快！
        </div>

        <!-- 消息提示 -->
        <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($manualResult): echo $manualResult; endif; ?>

        <!-- 上传区域 -->
        <div class="upload-box">
            <h2>📤 上传图片 / Upload Images</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.bmp,.png" class="file-input" />
                <button type="submit" class="btn btn-blue">上传并自动处理 / Upload & Auto Process</button>
            </form>
            
            <!-- ========== 新增：手动清理文件按钮 ========== -->
            <div class="clean-btn-container">
                <form method="post" onsubmit="return confirm('⚠️ 确认清理所有上传/处理后的文件吗？\nConfirm to delete all uploaded/processed files?');">
                    <button type="submit" name="clean_files" class="btn btn-red">🗑️ 清理所有文件 / Clean All Files</button>
                </form>
            </div>
        </div>

        <!-- 处理结果 -->
        <?php if (!empty($processResults)): ?>
        <div class="result-box">
            <h2>📋 处理结果 / Process Results</h2>
            
            <?php foreach ($processResults as $uniqueName => $result): ?>
                <?php 
                // 防呆：确保所有必要键名存在，避免Undefined警告
                $originalName = isset($result['original_name']) ? $result['original_name'] : '未知文件 / Unknown file';
                $message = isset($result['message']) ? $result['message'] : '无结果 / No result';
                $originalWebPath = isset($result['original_web_path']) ? $result['original_web_path'] : '';
                $processedWebPath = isset($result['processed_web_path']) ? $result['processed_web_path'] : '';
                $processedPath = isset($result['processed_path']) ? $result['processed_path'] : '';
                $originalPath = isset($result['original_path']) ? $result['original_path'] : '';
                ?>
            <div class="result-item">
                <div><strong>文件名：</strong><?php echo htmlspecialchars($originalName); ?></div>
                <div><strong>处理结果：</strong><?php echo htmlspecialchars($message); ?></div>
                
                <!-- 图片预览（修复HTML语法错误） -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0;">
                    <div style="flex: 1; min-width: 200px;">
                        <p style="font-size: 14px; color: #666;">原图 / Original:</p>
                        <?php if (!empty($originalWebPath)): ?>
                            <img src="<?php echo htmlspecialchars($originalWebPath); ?>" alt="原图" class="preview-img" />
                        <?php else: ?>
                            <p style="color: #666; font-size: 14px;">原图路径无效 / Original image path invalid</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($processedWebPath)): ?>
                        <div style="flex: 1; min-width: 200px;">
                            <p style="font-size: 14px; color: #28a745;">修复后 / Processed:</p>
                            <img src="<?php echo htmlspecialchars($processedWebPath); ?>" alt="处理后" class="preview-img" style="border: 2px solid #28a745;" />
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- 操作按钮（修复数组键名+HTML语法错误） -->
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if (!empty($processedPath)): ?>
                        <a href="?download=1&file=<?php echo urlencode($processedPath); ?>" class="btn btn-green">📥 下载 / Download</a>
                    <?php endif; ?>
                    <?php if (!empty($originalPath)): ?>
                        <button onclick="openManualModal('<?php echo htmlspecialchars($originalWebPath); ?>', '<?php echo addslashes($originalPath); ?>', '<?php echo htmlspecialchars($uniqueName); ?>')" class="btn btn-orange">
                            ✏️ AI手工消除 / AI Manual Remove
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- AI手工去水印模态框 -->
    <div id="manual-modal">
        <div class="modal-content">
            <h2>✏️ AI手工去除水印 / AI Manual Watermark Removal</h2>
            <div id="status" class="status status-loading">正在加载图片... / Loading image...</div>
            
            <!-- 选区信息 -->
            <div id="selection-info" class="selection-info" style="display: none;">
                📌 选区范围：X1=<span id="x1-val">0</span>, Y1=<span id="y1-val">0</span> | X2=<span id="x2-val">0</span>, Y2=<span id="y2-val">0</span>
            </div>
            
            <form id="manual-form" method="post">
                <input type="hidden" id="img-path" name="img_path">
                <input type="hidden" id="unique-name" name="unique_name">
                <input type="hidden" id="x1" name="x1" value="0">
                <input type="hidden" id="y1" name="y1" value="0">
                <input type="hidden" id="x2" name="x2" value="0">
                <input type="hidden" id="y2" name="y2" value="0">
                <input type="hidden" name="manual_remove" value="1">
                
                <!-- 操作提示 -->
                <div style="margin: 15px 0; font-size: 14px; color: #666;">
                    📝 操作说明：按住鼠标左键在图片上拖动，框选需要去除的水印区域
                </div>
                
                <!-- 画布容器 -->
                <div id="canvas-container">
                    <canvas id="manual-canvas"></canvas>
                </div>
                
                <!-- 操作按钮 -->
                <div class="btn-group">
                    <button type="button" onclick="clearSelection()" class="btn btn-gray">🗑️ 清空选区 / Clear Selection</button>
                    <button type="button" onclick="closeManualModal()" class="btn btn-gray">❌ 取消 / Cancel</button>
                    <button type="button" onclick="submitManualRemove()" class="btn btn-orange">✅ AI修复选中区域 / AI Repair Selected Area</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 全局变量
        let canvas, ctx, img;
        let isDrawing = false;
        let canvasScale = 1; // 画布缩放比例
        let startX = 0, startY = 0; // 选区起始坐标
        let endX = 0, endY = 0;     // 选区结束坐标
        let imgNaturalWidth = 0, imgNaturalHeight = 0;

        // 计算Canvas实际坐标（解决缩放偏移）
        function getCanvasCoordinates(e) {
            const rect = canvas.getBoundingClientRect();
            const x = Math.round((e.clientX - rect.left) / canvasScale);
            const y = Math.round((e.clientY - rect.top) / canvasScale);
            return { x, y };
        }

        // 绘制选区（矩形）
        function drawSelection() {
            // 清空画布并重新绘制原图
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0);
            
            // 绘制矩形选区
            if (startX !== endX && startY !== endY) {
                // 计算选区的实际坐标（确保左上到右下）
                const x = Math.min(startX, endX);
                const y = Math.min(startY, endY);
                const width = Math.abs(endX - startX);
                const height = Math.abs(endY - startY);
                
                // 绘制半透明红色选区
                ctx.fillStyle = 'rgba(255, 0, 0, 0.2)';
                ctx.fillRect(x, y, width, height);
                // 绘制选区边框
                ctx.strokeStyle = 'rgba(255, 0, 0, 0.8)';
                ctx.lineWidth = 2;
                ctx.strokeRect(x, y, width, height);
                
                // 更新选区信息显示
                document.getElementById('x1-val').textContent = x;
                document.getElementById('y1-val').textContent = y;
                document.getElementById('x2-val').textContent = x + width;
                document.getElementById('y2-val').textContent = y + height;
                // 保存到隐藏表单
                document.getElementById('x1').value = x;
                document.getElementById('y1').value = y;
                document.getElementById('x2').value = x + width;
                document.getElementById('y2').value = y + height;
                // 显示选区信息
                document.getElementById('selection-info').style.display = 'block';
            }
        }

        // 清空选区
        function clearSelection() {
            startX = 0;
            startY = 0;
            endX = 0;
            endY = 0;
            // 重置显示
            document.getElementById('x1-val').textContent = 0;
            document.getElementById('y1-val').textContent = 0;
            document.getElementById('x2-val').textContent = 0;
            document.getElementById('y2-val').textContent = 0;
            document.getElementById('selection-info').style.display = 'none';
            // 重置表单值
            document.getElementById('x1').value = 0;
            document.getElementById('y1').value = 0;
            document.getElementById('x2').value = 0;
            document.getElementById('y2').value = 0;
            // 重绘画布
            if (ctx && img) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0);
            }
        }

        // 打开手工去水印模态框
        function openManualModal(imgWebPath, imgServerPath, uniqueName) {
            // 显示模态框
            const modal = document.getElementById('manual-modal');
            modal.style.display = 'flex';
            
            // 初始化状态
            const status = document.getElementById('status');
            status.className = 'status status-loading';
            status.textContent = '正在加载图片... / Loading image...';
            
            // 清空之前的选区
            clearSelection();
            
            // 设置表单隐藏值
            document.getElementById('img-path').value = imgServerPath;
            document.getElementById('unique-name').value = uniqueName;
            
            // 获取画布元素
            canvas = document.getElementById('manual-canvas');
            ctx = canvas.getContext('2d');
            if (!ctx) {
                status.className = 'status status-error';
                status.textContent = '❌ 错误：浏览器不支持Canvas / Canvas not supported';
                return;
            }

            // 加载图片（解决跨域+缩放）
            img = new Image();
            img.crossOrigin = 'anonymous';
            
            // 图片加载成功
            img.onload = function() {
                // 记录图片原始尺寸
                imgNaturalWidth = img.width;
                imgNaturalHeight = img.height;
                
                // 计算画布缩放比例（适配容器宽度）
                const container = document.getElementById('canvas-container');
                const containerWidth = container.clientWidth;
                canvasScale = containerWidth / img.width;
                
                // 设置画布尺寸（原始尺寸）
                canvas.width = img.width;
                canvas.height = img.height;
                
                // 绘制原图
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0);
                
                // 更新状态
                status.className = 'status status-success';
                status.textContent = '✅ 图片加载完成！按住鼠标左键拖动框选水印区域 / Image loaded! Drag to select watermark area.';
                
                // 绑定鼠标事件（核心）
                canvas.addEventListener('mousedown', function(e) {
                    isDrawing = true;
                    const coords = getCanvasCoordinates(e);
                    startX = coords.x;
                    startY = coords.y;
                    endX = coords.x;
                    endY = coords.y;
                    drawSelection();
                });

                canvas.addEventListener('mousemove', function(e) {
                    if (!isDrawing) return;
                    const coords = getCanvasCoordinates(e);
                    endX = coords.x;
                    endY = coords.y;
                    drawSelection();
                });

                canvas.addEventListener('mouseup', function() {
                    isDrawing = false;
                });

                canvas.addEventListener('mouseleave', function() {
                    isDrawing = false;
                });
            };
            
            // 图片加载失败
            img.onerror = function() {
                status.className = 'status status-error';
                status.textContent = '❌ 图片加载失败！请检查路径：' + imgWebPath;
                console.error('图片加载失败', imgWebPath);
            };
            
            // 防止缓存
            img.src = imgWebPath + '?t=' + Date.now();
        }

        // 关闭模态框
        function closeManualModal() {
            document.getElementById('manual-modal').style.display = 'none';
            isDrawing = false;
            clearSelection();
        }

        // 提交AI手工去水印
        function submitManualRemove() {
            // 获取选区坐标
            const x1 = parseInt(document.getElementById('x1').value);
            const y1 = parseInt(document.getElementById('y1').value);
            const x2 = parseInt(document.getElementById('x2').value);
            const y2 = parseInt(document.getElementById('y2').value);
            
            // 验证选区
            if (x1 === x2 || y1 === y2) {
                alert('❌ 请先框选需要去除的水印区域！\nPlease select the watermark area first!');
                return;
            }
            
            // 确认提交
            if (confirm('✅ 确认使用AI修复选中的区域吗？\nConfirm AI repair for selected area?')) {
                // 提交表单
                document.getElementById('manual-form').submit();
            }
        }
    </script>
</body>
</html>
