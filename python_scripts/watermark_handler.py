import sys
import json
import cv2
import numpy as np
from PIL import Image
import io

def detect_and_remove_watermark(input_path, output_path):
    """
    检测并去除水印（保留原始清晰度）
    """
    try:
        # 读取图片（使用cv2读取，保留原始分辨率和通道）
        img = cv2.imread(input_path, cv2.IMREAD_UNCHANGED)
        if img is None:
            return {"success": False, "has_watermark": False, "error": "无法读取图片 / Failed to read image"}
        
        # 水印检测逻辑（示例：可根据实际需求优化）
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if len(img.shape) == 3 else img
        # 边缘检测（调整参数减少误判）
        edges = cv2.Canny(gray, 50, 150)
        # 计算边缘密度（判断是否有水印）
        edge_density = np.sum(edges) / (img.shape[0] * img.shape[1])
        has_watermark = edge_density > 0.01  # 阈值可根据实际情况调整
        
        if has_watermark:
            # 水印去除逻辑（保留原始清晰度）
            # 方法1：inpainting 修复（适合半透明水印）
            mask = edges
            # 膨胀mask增强修复区域
            kernel = np.ones((3,3), np.uint8)
            mask = cv2.dilate(mask, kernel, iterations=1)
            # 使用INPAINT_NS算法（更精细，保留细节）
            result = cv2.inpaint(img, mask, 3, cv2.INPAINT_NS)
            
            # 保存结果（无损/高质量）
            if input_path.lower().endswith(('png', 'bmp')):
                # PNG/BMP 无损保存
                cv2.imwrite(output_path, result, [cv2.IMWRITE_PNG_COMPRESSION, 0])
            else:
                # JPG 高质量保存（质量100）
                cv2.imwrite(output_path, result, [cv2.IMWRITE_JPEG_QUALITY, 100])
            
            return {"success": True, "has_watermark": True, "message": "水印已去除 / Watermark removed"}
        else:
            # 无水印时直接复制原图（避免二次压缩）
            if input_path.lower().endswith(('png', 'bmp')):
                cv2.imwrite(output_path, img, [cv2.IMWRITE_PNG_COMPRESSION, 0])
            else:
                cv2.imwrite(output_path, img, [cv2.IMWRITE_JPEG_QUALITY, 100])
            return {"success": True, "has_watermark": False, "message": "无水印 / No watermark"}
    
    except Exception as e:
        return {"success": False, "has_watermark": False, "error": f"处理失败 / Process failed: {str(e)}"}

def ai_manual_remove_watermark(input_path, output_path, x1, y1, x2, y2):
    """
    AI手工去除指定区域水印（保留原始清晰度）
    """
    try:
        # 转换坐标为整数
        x1, y1, x2, y2 = int(x1), int(y1), int(x2), int(y2)
        if x1 >= x2 or y1 >= y2:
            return {"success": False, "error": "无效的选区范围 / Invalid selection range"}
        
        # 读取图片（保留原始分辨率）
        img = cv2.imread(input_path, cv2.IMREAD_UNCHANGED)
        if img is None:
            return {"success": False, "error": "无法读取图片 / Failed to read image"}
        
        # 确保选区在图片范围内
        h, w = img.shape[:2]
        x1 = max(0, x1)
        y1 = max(0, y1)
        x2 = min(w, x2)
        y2 = min(h, y2)
        
        # 创建mask（仅修复选中区域）
        mask = np.zeros(img.shape[:2], np.uint8)
        mask[y1:y2, x1:x2] = 255
        
        # 使用高级inpainting算法（保留细节）
        result = cv2.inpaint(img, mask, 3, cv2.INPAINT_NS)
        
        # 保存结果（无损/高质量）
        if input_path.lower().endswith(('png', 'bmp')):
            cv2.imwrite(output_path, result, [cv2.IMWRITE_PNG_COMPRESSION, 0])
        else:
            cv2.imwrite(output_path, result, [cv2.IMWRITE_JPEG_QUALITY, 100])
        
        return {"success": True, "message": "AI修复完成 / AI repair completed"}
    
    except Exception as e:
        return {"success": False, "error": f"AI修复失败 / AI repair failed: {str(e)}"}

def main():
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "参数不足 / Insufficient parameters"}))
        return
    
    command = sys.argv[1]
    
    if command == "detect_and_remove":
        if len(sys.argv) != 4:
            print(json.dumps({"success": False, "error": "参数错误 / Parameter error"}))
            return
        input_path = sys.argv[2]
        output_path = sys.argv[3]
        result = detect_and_remove_watermark(input_path, output_path)
        print(json.dumps(result))
    
    elif command == "ai_manual_remove":
        if len(sys.argv) != 8:
            print(json.dumps({"success": False, "error": "参数错误 / Parameter error"}))
            return
        input_path = sys.argv[2]
        output_path = sys.argv[3]
        x1 = sys.argv[4]
        y1 = sys.argv[5]
        x2 = sys.argv[6]
        y2 = sys.argv[7]
        result = ai_manual_remove_watermark(input_path, output_path, x1, y1, x2, y2)
        print(json.dumps(result))
    
    else:
        print(json.dumps({"success": False, "error": "未知命令 / Unknown command"}))

if __name__ == "__main__":
    main()
