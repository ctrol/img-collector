<?php
/**
 * 水印处理类
 * 
 * 使用 GD 库实现文字水印功能
 * 参考 easy-watermark 插件实现
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Watermark {

    private static $instance = null;
    
    /**
     * 最小图片尺寸
     */
    private $min_width = 100;
    private $min_height = 100;
    
    /**
     * 插件字体目录
     */
    private $fonts_dir;

    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->fonts_dir = IMG_COLLECTOR_PATH . 'fonts' . DIRECTORY_SEPARATOR;
    }

    /**
     * 检查 GD 库是否可用
     */
    public function is_supported() {
        return extension_loaded('gd') && function_exists('gd_info');
    }

    /**
     * 获取 GD 信息
     */
    public function get_gd_info() {
        if ($this->is_supported()) {
            return gd_info();
        }
        return null;
    }

    /**
     * 检查 FreeType 是否支持（用于 TTF 字体）
     */
    public function is_freetype_supported() {
        $info = $this->get_gd_info();
        if ($info && isset($info['FreeType Support'])) {
            return $info['FreeType Support'];
        }
        return false;
    }

    /**
     * 应用水印到附件
     * 
     * @param int $attachment_id 附件ID
     * @param array $settings 水印设置
     * @return bool|WP_Error
     */
    public function apply_watermark($attachment_id, $settings) {
        if (!$this->is_supported()) {
            error_log('Img_Collector_Watermark: GD库未安装');
            return new WP_Error('no_gd', __('需要GD库支持水印功能', 'img-collector'));
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            error_log('Img_Collector_Watermark: 找不到图片文件 ' . $file_path);
            return new WP_Error('file_not_found', __('找不到图片文件', 'img-collector'));
        }

        error_log('Img_Collector_Watermark: 开始应用水印，文件: ' . $file_path);
        
        return $this->apply_watermark_to_path($file_path, $settings, $attachment_id);
    }

    /**
     * 应用水印到指定路径的图片
     * 
     * @param string $file_path 图片路径
     * @param array $settings 水印设置
     * @param int $attachment_id 附件ID（可选）
     * @return bool|WP_Error
     */
    public function apply_watermark_to_path($file_path, $settings, $attachment_id = 0) {
        if (!$this->is_supported()) {
            return new WP_Error('no_gd', __('需要GD库支持水印功能', 'img-collector'));
        }

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('找不到图片文件', 'img-collector'));
        }

        // 获取图片信息
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            error_log('Img_Collector_Watermark: 无法获取图片信息');
            return new WP_Error('invalid_image', __('无法获取图片信息', 'img-collector'));
        }

        $mime_type = $image_info['mime'];
        $width = $image_info[0];
        $height = $image_info[1];

        // 检查图片尺寸
        if ($width < $this->min_width || $height < $this->min_height) {
            error_log('Img_Collector_Watermark: 图片太小，跳过水印');
            return true;
        }

        // 获取水印文字
        $text = isset($settings['watermark_text']) ? trim($settings['watermark_text']) : '';
        if (empty($text)) {
            error_log('Img_Collector_Watermark: 水印文字为空');
            return new WP_Error('no_text', __('未设置水印文字', 'img-collector'));
        }

        error_log('Img_Collector_Watermark: 图片尺寸: ' . $width . 'x' . $height . ', MIME: ' . $mime_type);

        // 创建图片资源
        $image = $this->create_image_from_file($file_path, $mime_type);
        if (is_wp_error($image)) {
            return $image;
        }

        // 应用水印
        $result = $this->add_text_watermark($image, $width, $height, $settings);
        if (is_wp_error($result)) {
            imagedestroy($image);
            return $result;
        }

        // 保存图片
        $save_result = $this->save_image($image, $file_path, $mime_type);
        imagedestroy($image);

        if (is_wp_error($save_result)) {
            return $save_result;
        }

        error_log('Img_Collector_Watermark: 水印已成功应用');

        // 重新生成缩略图
        if ($attachment_id > 0) {
            $this->regenerate_thumbnails($attachment_id, $file_path);
            error_log('Img_Collector_Watermark: 已重新生成缩略图');
        }

        return true;
    }

    /**
     * 从文件创建图片资源
     */
    private function create_image_from_file($file_path, $mime_type) {
        switch ($mime_type) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($file_path);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($file_path);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($file_path);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($file_path);
                } else {
                    return new WP_Error('unsupported_format', __('不支持WebP格式', 'img-collector'));
                }
                break;
            default:
                return new WP_Error('unsupported_format', __('不支持的图片格式', 'img-collector'));
        }

        if (!$image) {
            return new WP_Error('create_failed', __('无法创建图片资源', 'img-collector'));
        }

        return $image;
    }

    /**
     * 添加文字水印
     */
    private function add_text_watermark($image, $width, $height, $settings) {
        // 获取设置
        $text = $settings['watermark_text'];
        $font_size = isset($settings['watermark_font_size']) ? intval($settings['watermark_font_size']) : 24;
        $font_color = isset($settings['watermark_font_color']) ? $settings['watermark_font_color'] : '#FFFFFF';
        $opacity = isset($settings['watermark_opacity']) ? intval($settings['watermark_opacity']) : 50;
        $position = isset($settings['watermark_position']) ? $settings['watermark_position'] : 'bottom-right';
        $font_name = isset($settings['watermark_font']) ? $settings['watermark_font'] : 'Arial';

        // 确保文本是UTF-8编码
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        error_log('Img_Collector_Watermark: 水印文字: ' . $text);

        // 验证颜色值
        if (empty($font_color) || !preg_match('/^#[0-9A-Fa-f]{3,6}$/', $font_color)) {
            $font_color = '#FFFFFF';
        }

        // 解析颜色
        $color_rgb = $this->hex_to_rgb($font_color);
        
        // 计算透明度（GD 使用 0-127，0 完全不透明，127 完全透明）
        $alpha = 127 - intval(($opacity / 100) * 127);

        error_log('Img_Collector_Watermark: 字体: ' . $font_name . ', 字体大小: ' . $font_size . ', 颜色: ' . $font_color . ', 透明度: ' . $opacity . '%');

        // 获取字体文件路径（支持自动回退到中文字体）
        $font_file = $this->get_font_file_with_fallback($font_name, $text);
        
        // 计算水印位置
        if ($font_file && $this->is_freetype_supported()) {
            // 使用 TTF 字体
            $pos = $this->calculate_position_ttf($image, $width, $height, $text, $font_file, $font_size, $position);
            
            // 设置颜色（带透明度）
            $text_color = imagecolorallocatealpha($image, $color_rgb['r'], $color_rgb['g'], $color_rgb['b'], $alpha);
            
            // 绘制文字
            imagettftext($image, $font_size, 0, $pos['x'], $pos['y'], $text_color, $font_file, $text);
            
            error_log('Img_Collector_Watermark: 使用TTF字体: ' . $font_file . ', 位置: (' . $pos['x'] . ', ' . $pos['y'] . ')');
        } else {
            // 使用内置字体（仅支持ASCII字符）
            $font_id = 5; // 使用最大的内置字体
            $pos = $this->calculate_position_builtin($width, $height, $text, $font_id, $position);
            
            // 设置颜色（带透明度）
            $text_color = imagecolorallocatealpha($image, $color_rgb['r'], $color_rgb['g'], $color_rgb['b'], $alpha);
            
            // 绘制文字（内置字体不支持中文，只绘制ASCII部分）
            $ascii_text = $this->extract_ascii($text);
            imagestring($image, $font_id, $pos['x'], $pos['y'], $ascii_text, $text_color);
            
            error_log('Img_Collector_Watermark: 使用内置字体（仅ASCII）, 位置: (' . $pos['x'] . ', ' . $pos['y'] . ')');
        }

        return true;
    }

    /**
     * 获取字体文件路径（带自动回退）
     */
    private function get_font_file_with_fallback($font_name, $text) {
        // 首先尝试用户选择的字体
        $font_file = $this->get_font_file($font_name);
        if ($font_file) {
            return $font_file;
        }

        // 如果用户选择的字体找不到，并且文本包含中文，尝试自动查找中文字体
        if ($this->contains_chinese($text)) {
            error_log('Img_Collector_Watermark: 用户字体找不到，尝试自动查找中文字体');
            return $this->find_any_chinese_font();
        }

        // 回退到Arial
        return $this->get_font_file('Arial');
    }

    /**
     * 自动查找系统中可用的中文字体
     */
    private function find_any_chinese_font() {
        // 常见中文字体列表
        $chinese_fonts = array(
            // Windows
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/simhei.ttf',
            'C:/Windows/Fonts/simsun.ttc',
            'C:/Windows/Fonts/kaiti.ttc',
            'C:/Windows/Fonts/STSONG.TTF',
            'C:/Windows/Fonts/STHEITI.TTC',
            'C:/Windows/Fonts/STKaiti.ttf',
            'C:/Windows/Fonts/STFangsong.ttf',
            
            // Linux
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-SC.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/noto/NotoSerifCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/arphic/ukai.ttc',
            '/usr/share/fonts/truetype/arphic/bkai00mp.ttf',
            '/usr/share/fonts/truetype/arphic/gbsn00lp.ttf',
            
            // macOS
            '/Library/Fonts/Microsoft YaHei.ttf',
            '/Library/Fonts/SimHei.ttf',
            '/Library/Fonts/SimSun.ttf',
            '/Library/Fonts/KaiTi.ttf',
            '/System/Library/Fonts/STSong.ttf',
            '/System/Library/Fonts/STHeiti.ttc',
            '/System/Library/Fonts/STKaiti.ttf',
            '/System/Library/Fonts/STFangsong.ttf',
            
            // 插件目录
            $this->fonts_dir . 'msyh.ttc',
            $this->fonts_dir . 'simhei.ttf',
            $this->fonts_dir . 'simsun.ttc',
            $this->fonts_dir . 'wqy-microhei.ttc',
        );

        foreach ($chinese_fonts as $path) {
            if (file_exists($path)) {
                error_log('Img_Collector_Watermark: 自动找到中文字体: ' . $path);
                return $path;
            }
        }

        error_log('Img_Collector_Watermark: 未找到任何中文字体');
        return false;
    }

    /**
     * 检查文本是否包含中文
     */
    private function contains_chinese($text) {
        return preg_match('/[\x{4e00}-\x{9fa5}]/u', $text);
    }

    /**
     * 提取ASCII字符（用于内置字体回退）
     */
    private function extract_ascii($text) {
        return preg_replace('/[^\x00-\x7F]/', '', $text);
    }

    /**
     * 获取字体文件路径
     */
    private function get_font_file($font_name) {
        // 检查是否为自定义字体文件（文件名格式）
        if (preg_match('/\.(ttf|ttc|otf)$/i', $font_name)) {
            // 直接从插件fonts目录查找
            $plugin_font = $this->fonts_dir . $font_name;
            if (file_exists($plugin_font)) {
                error_log('Img_Collector_Watermark: 找到自定义字体: ' . $plugin_font);
                return $plugin_font;
            }
            return false;
        }

        // 字体映射表
        $font_map = array(
            'Arial' => 'arial.ttf',
            'Arial Black' => 'arialbd.ttf',
            'Comic Sans MS' => 'comic.ttf',
            'Courier New' => 'cour.ttf',
            'Georgia' => 'georgia.ttf',
            'Impact' => 'impact.ttf',
            'Tahoma' => 'tahoma.ttf',
            'Times New Roman' => 'times.ttf',
            'Trebuchet MS' => 'trebuc.ttf',
            'Verdana' => 'verdana.ttf',
            'Microsoft YaHei' => 'msyh.ttc',
            'SimHei' => 'simhei.ttf',
            'SimSun' => 'simsun.ttc',
            'KaiTi' => 'kaiti.ttc',
            'STSong' => 'stsong.ttf',
            'STHeiti' => 'stheiti.ttf',
            'WenQuanYi Micro Hei' => 'wqy-microhei.ttc',
            'WenQuanYi Zen Hei' => 'wqy-zenhei.ttc',
        );

        $font_file = isset($font_map[$font_name]) ? $font_map[$font_name] : 'arial.ttf';

        // 1. 首先检查插件 fonts 目录
        $plugin_font = $this->fonts_dir . $font_file;
        if (file_exists($plugin_font)) {
            return $plugin_font;
        }

        // 2. 检查 Windows 系统字体（中文系统）
        $windows_fonts = array(
            'C:/Windows/Fonts/' . $font_file,
            'C:/Windows/Fonts/' . strtolower($font_file),
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/simhei.ttf',
            'C:/Windows/Fonts/simsun.ttc',
            'C:/Windows/Fonts/kaiti.ttc',
            'C:/Windows/Fonts/STSONG.TTF',
            'C:/Windows/Fonts/STHEITI.TTC',
        );
        foreach ($windows_fonts as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 3. 检查 Linux 系统字体
        $linux_fonts = array(
            '/usr/share/fonts/truetype/' . $font_file,
            '/usr/share/fonts/' . $font_file,
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-SC.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/arphic/ukai.ttc',
        );
        foreach ($linux_fonts as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 4. 检查 macOS 系统字体
        $mac_fonts = array(
            '/Library/Fonts/' . $font_file,
            '/System/Library/Fonts/' . $font_file,
            '/Library/Fonts/Microsoft YaHei.ttf',
            '/Library/Fonts/SimHei.ttf',
            '/Library/Fonts/SimSun.ttf',
            '/Library/Fonts/KaiTi.ttf',
            '/System/Library/Fonts/STSong.ttf',
            '/System/Library/Fonts/STHeiti.ttc',
        );
        foreach ($mac_fonts as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 未找到字体文件
        error_log('Img_Collector_Watermark: 未找到字体文件: ' . $font_file);
        return false;
    }

    /**
     * 计算 TTF 字体水印位置
     */
    private function calculate_position_ttf($image, $width, $height, $text, $font_file, $font_size, $position) {
        // 获取文字边界框
        $bbox = imagettfbbox($font_size, 0, $font_file, $text);
        $text_width = $bbox[2] - $bbox[0];
        $text_height = $bbox[1] - $bbox[7];
        
        $padding = 20;
        $x = 0;
        $y = 0;

        switch ($position) {
            case 'top-left':
                $x = $padding;
                $y = $padding + $text_height;
                break;
            case 'top-center':
                $x = ($width - $text_width) / 2;
                $y = $padding + $text_height;
                break;
            case 'top-right':
                $x = $width - $text_width - $padding;
                $y = $padding + $text_height;
                break;
            case 'middle-left':
                $x = $padding;
                $y = ($height + $text_height) / 2;
                break;
            case 'middle-center':
                $x = ($width - $text_width) / 2;
                $y = ($height + $text_height) / 2;
                break;
            case 'middle-right':
                $x = $width - $text_width - $padding;
                $y = ($height + $text_height) / 2;
                break;
            case 'bottom-left':
                $x = $padding;
                $y = $height - $padding;
                break;
            case 'bottom-center':
                $x = ($width - $text_width) / 2;
                $y = $height - $padding;
                break;
            case 'bottom-right':
            default:
                $x = $width - $text_width - $padding;
                $y = $height - $padding;
                break;
        }

        return array('x' => intval($x), 'y' => intval($y));
    }

    /**
     * 计算内置字体水印位置
     */
    private function calculate_position_builtin($width, $height, $text, $font_id, $position) {
        $text_width = imagefontwidth($font_id) * strlen($text);
        $text_height = imagefontheight($font_id);
        
        $padding = 20;
        $x = 0;
        $y = 0;

        switch ($position) {
            case 'top-left':
                $x = $padding;
                $y = $padding;
                break;
            case 'top-center':
                $x = ($width - $text_width) / 2;
                $y = $padding;
                break;
            case 'top-right':
                $x = $width - $text_width - $padding;
                $y = $padding;
                break;
            case 'middle-left':
                $x = $padding;
                $y = ($height - $text_height) / 2;
                break;
            case 'middle-center':
                $x = ($width - $text_width) / 2;
                $y = ($height - $text_height) / 2;
                break;
            case 'middle-right':
                $x = $width - $text_width - $padding;
                $y = ($height - $text_height) / 2;
                break;
            case 'bottom-left':
                $x = $padding;
                $y = $height - $text_height - $padding;
                break;
            case 'bottom-center':
                $x = ($width - $text_width) / 2;
                $y = $height - $text_height - $padding;
                break;
            case 'bottom-right':
            default:
                $x = $width - $text_width - $padding;
                $y = $height - $text_height - $padding;
                break;
        }

        return array('x' => intval($x), 'y' => intval($y));
    }

    /**
     * 保存图片
     */
    private function save_image($image, $file_path, $mime_type) {
        $result = false;
        
        switch ($mime_type) {
            case 'image/jpeg':
                $result = imagejpeg($image, $file_path, 90);
                break;
            case 'image/png':
                $result = imagepng($image, $file_path, 9);
                break;
            case 'image/gif':
                $result = imagegif($image, $file_path);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $result = imagewebp($image, $file_path, 90);
                }
                break;
        }

        if (!$result) {
            return new WP_Error('save_failed', __('保存图片失败', 'img-collector'));
        }

        return true;
    }

    /**
     * 十六进制颜色转 RGB
     */
    private function hex_to_rgb($hex) {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        return array('r' => $r, 'g' => $g, 'b' => $b);
    }

    /**
     * 重新生成缩略图
     */
    private function regenerate_thumbnails($attachment_id, $file_path) {
        // 删除旧缩略图
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && isset($metadata['sizes'])) {
            $base_dir = dirname($file_path);
            
            foreach ($metadata['sizes'] as $size => $size_info) {
                $thumb_path = $base_dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    @unlink($thumb_path);
                }
            }
        }
        
        // 重新生成元数据
        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
}