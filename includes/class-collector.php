<?php
/**
 * 核心图片采集引擎类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Core {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 支持的图片格式
     */
    private $supported_formats = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp');

    /**
     * 最大并发数
     */
    private $max_concurrent = 3;

    /**
     * 超时时间（秒）
     */
    private $timeout = 30;

    /**
     * 错误信息
     */
    private $last_error = '';

    /**
     * 文件名唯一性检查最大尝试次数
     */
    private $max_filename_attempts = 100;

    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $settings = get_option('img_collector_settings', array());
        if (isset($settings['max_concurrent'])) {
            $this->max_concurrent = intval($settings['max_concurrent']);
        }
        if (isset($settings['timeout'])) {
            $this->timeout = intval($settings['timeout']);
        }
    }

    /**
     * 获取请求延迟时间（毫秒）
     */
    private function get_request_delay() {
        $settings = get_option('img_collector_settings', array());
        return isset($settings['request_delay']) ? intval($settings['request_delay']) : 500;
    }

    /**
     * 获取图片质量
     */
    private function get_image_quality() {
        $settings = get_option('img_collector_settings', array());
        return isset($settings['image_quality']) ? intval($settings['image_quality']) : 85;
    }

    /**
     * 检查是否转换为WEBP
     */
    private function should_convert_webp() {
        $settings = get_option('img_collector_settings', array());
        return !empty($settings['convert_webp']);
    }

    /**
     * 从文章内容中提取外链图片URL
     *
     * @param string $content 文章内容
     * @param int $post_id 文章ID
     * @return array 图片URL列表
     */
    public function extract_external_images($content, $post_id = 0) {
        if (empty($content)) {
            return array();
        }

        $images = array();
        $site_url = get_site_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);

        // 匹配所有img标签
        $pattern = '/<img[^>]+>/i';
        preg_match_all($pattern, $content, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $index => $img_tag) {
                // 提取src属性
                $src_pattern = '/src=["\']([^"\']+)["\']/i';
                if (!preg_match($src_pattern, $img_tag, $src_matches)) {
                    continue;
                }
                
                $url = $this->normalize_url($src_matches[1]);

                // 检查是否为外链图片
                $url_host = parse_url($url, PHP_URL_HOST);
                if (!$url_host || $url_host === $site_host) {
                    continue;
                }
                
                // 提取alt属性
                $alt_pattern = '/alt=["\']([^"\']+)["\']/i';
                $alt = '';
                if (preg_match($alt_pattern, $img_tag, $alt_matches)) {
                    $alt = $alt_matches[1];
                }

                $images[] = array(
                    'url' => $url,
                    'alt' => $alt,
                    'index' => $index,
                    'is_external' => true,
                    'original_tag' => $img_tag,
                );
            }
        }

        // 应用过滤规则
        $filter = Img_Collector_Filter::get_instance();
        $images = $filter->apply_filters($images, $post_id);

        return $images;
    }

    /**
     * 规范化URL
     *
     * @param string $url 原始URL
     * @return string 规范化后的URL
     */
    private function normalize_url($url) {
        // 处理相对URL
        if (strpos($url, 'http') !== 0) {
            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
            } elseif (strpos($url, '/') === 0) {
                $url = get_site_url() . $url;
            }
        }

        // 移除URL参数中的特殊字符
        $url = esc_url_raw($url);

        return $url;
    }

    /**
     * 采集单张图片
     *
     * @param string $url 图片URL
     * @param int $post_id 关联文章ID
     * @param string $method 采集方法 (server/proxy/browser)
     * @return array|WP_Error 采集结果
     */
    public function collect_single($url, $post_id = 0, $method = 'server') {
        // 验证URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('无效的图片URL', 'img-collector'));
        }

        // 检查图片格式
        $extension = $this->get_extension_from_url($url);
        if (!in_array(strtolower($extension), $this->supported_formats)) {
            return new WP_Error('unsupported_format', __('不支持的图片格式', 'img-collector'));
        }

        // 获取设置
        $settings = get_option('img_collector_settings', array());

        // 下载图片
        $image_data = $this->download_image($url, $method, $settings);

        if (is_wp_error($image_data)) {
            $this->log_collection($post_id, $url, '', 'failed', $image_data->get_error_message());
            return $image_data;
        }

        // 处理图片（压缩、转换等）
        $processed_data = $this->process_image($image_data, $settings);

        // 生成文件名
        $filename = $this->generate_filename($url, $post_id, $settings);

        // 保存到媒体库
        $attachment_id = $this->save_to_media_library($processed_data, $filename, $post_id, $settings);

        if (is_wp_error($attachment_id)) {
            $this->log_collection($post_id, $url, '', 'failed', $attachment_id->get_error_message());
            return $attachment_id;
        }

        // 获取本地URL
        $local_url = wp_get_attachment_url($attachment_id);

        // 应用水印
        if (!empty($settings['watermark_enabled'])) {
            $watermark = Img_Collector_Watermark::get_instance();
            $watermark_result = $watermark->apply_watermark($attachment_id, $settings);
            
            // 如果水印应用失败，记录错误（但不中断流程）
            if (is_wp_error($watermark_result)) {
                $this->log_collection($post_id, $url, $local_url, 'watermark_failed', $watermark_result->get_error_message());
            }
        }

        // 记录日志
        $this->log_collection($post_id, $url, $local_url, 'success', '');

        return array(
            'success' => true,
            'original_url' => $url,
            'local_url' => $local_url,
            'attachment_id' => $attachment_id,
        );
    }

    /**
     * 批量采集图片
     *
     * @param array $urls 图片URL列表
     * @param int $post_id 关联文章ID
     * @param string $method 采集方法
     * @return array 采集结果列表
     */
    public function collect_batch($urls, $post_id = 0, $method = 'server') {
        $results = array();
        $settings = get_option('img_collector_settings', array());

        // 去重处理
        if (!empty($settings['filter_dedup'])) {
            $urls = $this->deduplicate_urls($urls);
        }

        foreach ($urls as $url_data) {
            $url = is_array($url_data) ? $url_data['url'] : $url_data;

            // 检查是否已采集过
            if (!empty($settings['filter_dedup'])) {
                $existing = $this->get_existing_collection($url);
                if ($existing) {
                    $results[] = array(
                        'success' => true,
                        'original_url' => $url,
                        'local_url' => $existing['local_url'],
                        'attachment_id' => $existing['attachment_id'],
                        'is_duplicate' => true,
                    );
                    continue;
                }
            }

            $result = $this->collect_single($url, $post_id, $method);
            $results[] = $result;

            // 避免服务器过载，添加延迟
            $delay = $this->get_request_delay();
            if ($delay > 0) {
                usleep($delay * 1000); // 转换为微秒
            }
        }

        return $results;
    }

    /**
     * 下载图片
     *
     * @param string $url 图片URL
     * @param string $method 采集方法
     * @param array $settings 设置
     * @return string|WP_Error 图片数据或错误
     */
    private function download_image($url, $method, $settings) {
        $args = array(
            'timeout' => $this->timeout,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => array(
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                'Referer' => $url,
            ),
        );

        // 代理设置
        if ($method === 'proxy' && !empty($settings['proxy_enabled'])) {
            $proxy_host = isset($settings['proxy_host']) ? $settings['proxy_host'] : '';
            $proxy_port = isset($settings['proxy_port']) ? $settings['proxy_port'] : '';
            
            if (!empty($proxy_host) && !empty($proxy_port)) {
                $proxy_url = $proxy_host . ':' . $proxy_port;
                $args['proxy'] = $proxy_url;

                // 使用过滤器设置代理认证
                if (!empty($settings['proxy_user']) && !empty($settings['proxy_pass'])) {
                    add_filter('http_request_args', function($r, $url) use ($settings) {
                        $r['headers']['Proxy-Authorization'] = 'Basic ' . base64_encode($settings['proxy_user'] . ':' . $settings['proxy_pass']);
                        return $r;
                    }, 10, 2);
                }
            }
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('download_failed', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('http_error', sprintf(__('HTTP错误: %d', 'img-collector'), $code));
        }

        $data = wp_remote_retrieve_body($response);

        // 验证是否为有效图片数据
        if (strlen($data) < 100) {
            return new WP_Error('invalid_data', __('下载的数据无效', 'img-collector'));
        }

        // 检查图片数据头部 - 使用多种方法验证
        $mime = '';
        
        // 方法1: 使用finfo扩展（推荐）
        if (extension_loaded('fileinfo') && class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($data);
        }
        
        // 方法2: 使用getimagesizefromstring
        if (empty($mime) && function_exists('getimagesizefromstring')) {
            $size_info = @getimagesizefromstring($data);
            if ($size_info && isset($size_info['mime'])) {
                $mime = $size_info['mime'];
            }
        }
        
        // 方法3: 通过文件头判断
        if (empty($mime)) {
            $mime = $this->detect_mime_from_header($data);
        }

        $allowed_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/webp');

        if (!in_array($mime, $allowed_mimes)) {
            return new WP_Error('invalid_mime', sprintf(__('无效的图片类型: %s', 'img-collector'), $mime));
        }

        return $data;
    }

    /**
     * 通过文件头检测MIME类型
     *
     * @param string $data 图片数据
     * @return string MIME类型
     */
    private function detect_mime_from_header($data) {
        if (strlen($data) < 8) {
            return '';
        }

        // JPEG
        if (substr($data, 0, 2) === "\xFF\xD8") {
            return 'image/jpeg';
        }

        // PNG
        if (substr($data, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }

        // GIF
        if (substr($data, 0, 6) === "GIF87a" || substr($data, 0, 6) === "GIF89a") {
            return 'image/gif';
        }

        // BMP
        if (substr($data, 0, 2) === "BM") {
            return 'image/bmp';
        }

        // TIFF (little endian)
        if (substr($data, 0, 4) === "II\x2A\x00" || substr($data, 0, 4) === "MM\x00\x2A") {
            return 'image/tiff';
        }

        // WebP
        if (substr($data, 0, 4) === "RIFF" && substr($data, 8, 4) === "WEBP") {
            return 'image/webp';
        }

        return '';
    }

    /**
     * 处理图片（压缩、尺寸调整、格式转换）
     *
     * @param string $data 图片数据
     * @param array $settings 设置
     * @return string 处理后的图片数据
     */
    private function process_image($data, $settings) {
        // 创建临时文件
        $temp_file = $this->create_temp_file($data);

        if (!$temp_file) {
            return $data;
        }

        // 尝试使用WP图像编辑器
        $editor = wp_get_image_editor($temp_file);

        if (is_wp_error($editor)) {
            @unlink($temp_file);
            return $data;
        }

        // 尺寸调整
        $max_width = isset($settings['max_width']) ? intval($settings['max_width']) : 0;
        if ($max_width > 0) {
            $size = $editor->get_size();
            if ($size && isset($size['width']) && $size['width'] > $max_width) {
                $editor->resize($max_width, null, false);
            }
        }

        // 获取图片质量设置
        $quality = $this->get_image_quality();

        // WEBP转换
        if ($this->should_convert_webp()) {
            $mime = $this->get_mime_from_data($data);
            if (in_array($mime, array('image/jpeg', 'image/png', 'image/webp'))) {
                // 尝试转换为WEBP
                $editor->set_mime_type('image/webp');
            }
        }

        // 设置质量
        $editor->set_quality($quality);

        // 保存处理后的图片
        $result = $editor->save($temp_file);

        if (is_wp_error($result)) {
            @unlink($temp_file);
            return $data;
        }

        // 读取处理后的数据
        $processed_data = file_get_contents($result['path']);
        @unlink($temp_file);
        if (isset($result['path']) && $result['path'] !== $temp_file) {
            @unlink($result['path']);
        }

        return $processed_data ?: $data;
    }

    /**
     * 创建临时文件
     *
     * @param string $data 图片数据
     * @return string|false 临时文件路径
     */
    private function create_temp_file($data) {
        $upload_dir = wp_upload_dir();
        
        if (empty($upload_dir['basedir'])) {
            return false;
        }
        
        $temp_dir = $upload_dir['basedir'] . '/img-collector-temp';

        // 创建临时目录
        if (!is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $temp_file = $temp_dir . '/temp_' . uniqid() . '.tmp';

        if (file_put_contents($temp_file, $data) === false) {
            return false;
        }

        return $temp_file;
    }

    /**
     * 生成文件名
     *
     * @param string $url 原始URL
     * @param int $post_id 文章ID
     * @param array $settings 设置
     * @return string 文件名
     */
    private function generate_filename($url, $post_id, $settings) {
        $rule = isset($settings['filename_rule']) ? $settings['filename_rule'] : 'original';
        $custom_rule = isset($settings['filename_custom']) ? $settings['filename_custom'] : '%filename%-%date%';

        // 获取原始文件名
        $original_name = $this->get_filename_from_url($url);
        $extension = $this->get_extension_from_url($url);

        switch ($rule) {
            case 'original':
                $filename = $original_name;
                break;

            case 'system':
                $filename = 'img_' . uniqid();
                break;

            case 'custom':
                $filename = $this->apply_filename_rule($custom_rule, $original_name, $post_id);
                break;

            default:
                $filename = $original_name;
        }

        // 确保文件名唯一
        $filename = $this->ensure_unique_filename($filename, $extension);

        return $filename;
    }

    /**
     * 应用自定义文件名规则
     *
     * @param string $rule 规则字符串
     * @param string $original 原始文件名
     * @param int $post_id 文章ID
     * @return string 生成的文件名
     */
    private function apply_filename_rule($rule, $original, $post_id) {
        $replacements = array(
            '%filename%' => $original,
            '%date%' => date('Ymd'),
            '%time%' => date('His'),
            '%postid%' => $post_id,
            '%random%' => substr(md5(uniqid()), 0, 6),
        );

        $filename = $rule;
        foreach ($replacements as $key => $value) {
            $filename = str_replace($key, $value, $filename);
        }

        // 清理非法字符
        $filename = sanitize_file_name($filename);

        return $filename;
    }

    /**
     * 确保文件名唯一 - 修复无限循环问题
     *
     * @param string $filename 文件名
     * @param string $extension 扩展名
     * @return string 唯一的文件名
     */
    private function ensure_unique_filename($filename, $extension) {
        $upload_dir = wp_upload_dir();
        
        // 检查上传目录是否有效
        if (empty($upload_dir['path'])) {
            // 如果目录无效，返回带时间戳的文件名
            return $filename . '-' . time() . '.' . $extension;
        }
        
        $path = $upload_dir['path'];
        $base_filename = $filename;
        $counter = 1;
        
        // 构建完整路径
        $full_path = $path . '/' . $filename . '.' . $extension;

        // 限制最大尝试次数，防止无限循环
        while (file_exists($full_path) && $counter < $this->max_filename_attempts) {
            $filename = $base_filename . '-' . $counter;
            $full_path = $path . '/' . $filename . '.' . $extension;
            $counter++;
        }
        
        // 如果达到最大尝试次数，使用uniqid确保唯一
        if ($counter >= $this->max_filename_attempts) {
            $filename = $base_filename . '-' . uniqid();
        }

        return $filename . '.' . $extension;
    }

    /**
     * 从URL获取文件名
     *
     * @param string $url URL
     * @return string 文件名（不含扩展名）
     */
    private function get_filename_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);

        // 移除扩展名
        $filename = preg_replace('/\.[^.]+$/', '', $filename);

        // 清理非法字符
        $filename = sanitize_file_name($filename);

        // 如果文件名太长，截断
        if (strlen($filename) > 50) {
            $filename = substr($filename, 0, 50);
        }

        return $filename ?: 'image';
    }

    /**
     * 从URL获取扩展名
     *
     * @param string $url URL
     * @return string 扩展名
     */
    private function get_extension_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        // 处理特殊情况
        $extension = strtolower($extension);

        // jpeg统一为jpg
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // tiff统一为tif
        if ($extension === 'tiff') {
            $extension = 'tif';
        }

        // 如果没有扩展名，默认jpg
        if (empty($extension) || !in_array($extension, $this->supported_formats)) {
            $extension = 'jpg';
        }

        return $extension;
    }

    /**
     * 从数据获取MIME类型
     *
     * @param string $data 图片数据
     * @return string MIME类型
     */
    private function get_mime_from_data($data) {
        // 方法1: 使用finfo扩展
        if (extension_loaded('fileinfo') && class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            return $finfo->buffer($data);
        }
        
        // 方法2: 使用getimagesizefromstring
        if (function_exists('getimagesizefromstring')) {
            $size_info = @getimagesizefromstring($data);
            if ($size_info && isset($size_info['mime'])) {
                return $size_info['mime'];
            }
        }
        
        // 方法3: 通过文件头判断
        return $this->detect_mime_from_header($data);
    }

    /**
     * 保存到媒体库
     *
     * @param string $data 图片数据
     * @param string $filename 文件名
     * @param int $post_id 关联文章ID
     * @param array $settings 设置
     * @return int|WP_Error 附件ID或错误
     */
    private function save_to_media_library($data, $filename, $post_id, $settings) {
        $upload_dir = wp_upload_dir();
        
        // 检查上传目录是否有效
        if (empty($upload_dir['path'])) {
            return new WP_Error('upload_dir_error', __('无法获取上传目录', 'img-collector'));
        }

        // 确保上传目录存在
        if (!is_dir($upload_dir['path'])) {
            wp_mkdir_p($upload_dir['path']);
        }

        $file_path = $upload_dir['path'] . '/' . $filename;

        // 写入文件
        if (file_put_contents($file_path, $data) === false) {
            return new WP_Error('write_failed', __('无法写入文件', 'img-collector'));
        }

        // 检查文件类型
        $filetype = wp_check_filetype_and_ext($file_path, $filename);
        if (!$filetype['type']) {
            @unlink($file_path);
            return new WP_Error('invalid_type', __('无法识别文件类型', 'img-collector'));
        }

        // 准备附件数据
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $filetype['type'],
            'post_title' => $this->get_image_title($filename, $post_id, $settings),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        // 设置父文章
        if ($post_id > 0) {
            $attachment['post_parent'] = $post_id;
        }

        // 插入附件
        $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return $attachment_id;
        }

        // 生成附件元数据
        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // 设置ALT文本
        $alt_text = $this->get_image_alt($filename, $post_id, $settings);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        return $attachment_id;
    }

    /**
     * 获取图片标题 - 修复未定义键访问
     *
     * @param string $filename 文件名
     * @param int $post_id 文章ID
     * @param array $settings 设置
     * @return string 标题
     */
    private function get_image_title($filename, $post_id, $settings) {
        $source = isset($settings['title_source']) ? $settings['title_source'] : 'original';

        switch ($source) {
            case 'filename':
                return sanitize_title(pathinfo($filename, PATHINFO_FILENAME));

            case 'post_title':
                if ($post_id > 0) {
                    $post = get_post($post_id);
                    return $post ? $post->post_title : '';
                }
                return '';

            case 'original':
            default:
                return sanitize_title(pathinfo($filename, PATHINFO_FILENAME));
        }
    }

    /**
     * 获取图片ALT文本 - 修复未定义键访问
     *
     * @param string $filename 文件名
     * @param int $post_id 文章ID
     * @param array $settings 设置
     * @return string ALT文本
     */
    private function get_image_alt($filename, $post_id, $settings) {
        $source = isset($settings['alt_source']) ? $settings['alt_source'] : 'original';

        switch ($source) {
            case 'filename':
                return sanitize_title(pathinfo($filename, PATHINFO_FILENAME));

            case 'post_title':
                if ($post_id > 0) {
                    $post = get_post($post_id);
                    return $post ? $post->post_title : '';
                }
                return '';

            case 'original':
            default:
                return sanitize_title(pathinfo($filename, PATHINFO_FILENAME));
        }
    }

    /**
     * 更新文章内容中的图片链接
     *
     * @param string $content 原内容
     * @param array $results 采集结果
     * @return string 更新后的内容
     */
    public function update_content_urls($content, $results) {
        $settings = get_option('img_collector_settings', array());
        $update_mode = isset($settings['content_update_mode']) ? $settings['content_update_mode'] : 'replace';
        $dom_clean_enabled = !empty($settings['dom_attr_clean_enabled']);

        foreach ($results as $result) {
            if (is_wp_error($result) || empty($result['success'])) {
                continue;
            }

            $original_url = isset($result['original_url']) ? $result['original_url'] : '';
            $local_url = isset($result['local_url']) ? $result['local_url'] : '';

            if (empty($original_url) || empty($local_url)) {
                continue;
            }

            if ($update_mode === 'keep_both') {
                $content = str_replace(
                    $original_url,
                    $original_url . "\n\n" . $local_url,
                    $content
                );
            } else {
                // 使用DOM解析器处理，参考IMGSpider的简洁方式
                $content = $this->replace_image_urls_dom($content, $original_url, $local_url);
            }
            
            // DOM属性清洗
            if ($dom_clean_enabled && $update_mode !== 'keep_both') {
                $content = $this->clean_all_local_image_attributes($content);
            }
        }

        return $content;
    }
    
    /**
     * 直接替换微信图片标签 - 删除原有完整img标签，重新生成干净的本地图片标签
     * 
     * 微信图片标签特点：
     * - src 和 data-src 都包含微信域名（mmbiz.qpic.cn）
     * - 包含大量微信追踪属性（data-ratio, data-s, data-type, data-aistatus等）
     * - URL中包含大量HTML实体编码（&amp;等）
     * 
     * @param string $content 文章内容
     * @param string $original_url 原始URL
     * @param string $local_url 本地URL
     * @return string 处理后的内容
     */
    private function replace_image_urls_dom($content, $original_url, $local_url) {
        // 提取URL的关键部分
        $parsed = parse_url($original_url);
        $url_host = isset($parsed['host']) ? $parsed['host'] : '';
        $url_path = isset($parsed['path']) ? $parsed['path'] : '';
        
        // 如果没有host，尝试从URL中提取
        if (empty($url_host)) {
            if (preg_match('/https?:\/\/([^\/]+)/', $original_url, $matches)) {
                $url_host = $matches[1];
            }
        }
        
        // 构建多个匹配模式，确保能匹配到各种情况
        $patterns = array();
        
        // 模式1：匹配src属性包含原始URL的img标签
        if (!empty($original_url)) {
            $escaped_url = preg_quote($original_url, '/');
            $patterns[] = '/<img[^>]*src=[\'"]' . $escaped_url . '[^\'"]*[\'"][^>]*>/i';
        }
        
        // 模式2：匹配src属性包含host的img标签
        if (!empty($url_host)) {
            $escaped_host = preg_quote($url_host, '/');
            $patterns[] = '/<img[^>]*src=[\'"]?[^\'"]*' . $escaped_host . '[^\'"]*[\'"][^>]*>/i';
        }
        
        // 模式3：匹配data-src属性包含host的img标签
        if (!empty($url_host)) {
            $escaped_host = preg_quote($url_host, '/');
            $patterns[] = '/<img[^>]*data-src=[\'"]?[^\'"]*' . $escaped_host . '[^\'"]*[\'"][^>]*>/i';
        }
        
        // 模式4：匹配任何属性包含原始URL的img标签（处理复杂情况）
        if (!empty($url_path)) {
            $escaped_path = preg_quote($url_path, '/');
            $patterns[] = '/<img[^>]*=[\'"]?[^\'"]*' . $escaped_path . '[^\'"]*[\'"][^>]*>/i';
        }
        
        // 模式5：匹配任何属性包含host的img标签
        if (!empty($url_host)) {
            $escaped_host = preg_quote($url_host, '/');
            $patterns[] = '/<img[^>]*=[\'"]?[^\'"]*' . $escaped_host . '[^\'"]*[\'"][^>]*>/i';
        }
        
        // 依次应用所有模式
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '<img src="' . esc_attr($local_url) . '" />', $content);
        }
        
        return $content;
    }
    
    /**
     * 清洗图片标签的DOM属性 - 只保留src属性
     *
     * @param string $content 文章内容
     * @param string $image_url 图片URL
     * @return string 清洗后的内容
     */
    private function clean_image_attributes($content, $image_url) {
        $escaped_url = preg_quote($image_url, '/');
        $pattern = '/<img[^>]*>/i';
        
        return preg_replace_callback($pattern, function($matches) use ($escaped_url) {
            $img_tag = $matches[0];
            
            // 检查这个img标签是否包含目标URL
            if (!preg_match('/src=["\'][^"\']*' . $escaped_url . '/i', $img_tag)) {
                return $img_tag;
            }
            
            // 只提取src属性
            preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match);
            
            $src = isset($src_match[1]) ? $src_match[1] : '';
            
            // 重建img标签，只保留src属性
            $new_tag = '<img src="' . $src . '">';
            
            return $new_tag;
        }, $content);
    }
    
    /**
     * 清洗所有本地图片的DOM属性
     * 用于处理data-src和src不一致的情况
     *
     * @param string $content 文章内容
     * @return string 清洗后的内容
     */
    private function clean_all_local_image_attributes($content) {
        $site_url = get_site_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);
        
        // 匹配所有img标签
        $pattern = '/<img[^>]*>/i';
        
        return preg_replace_callback($pattern, function($matches) use ($site_host) {
            $img_tag = $matches[0];
            
            // 检查这个img标签是否包含本地URL
            if (!preg_match('/src=["\'][^"\']*' . preg_quote($site_host, '/') . '/i', $img_tag)) {
                return $img_tag;
            }
            
            // 只提取src属性
            preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match);
            
            $src = isset($src_match[1]) ? $src_match[1] : '';
            
            // 重建img标签，只保留src属性
            $new_tag = '<img src="' . $src . '">';
            
            return $new_tag;
        }, $content);
    }

    /**
     * 设置特色图片
     *
     * @param int $post_id 文章ID
     * @param int $attachment_id 附件ID
     */
    public function set_featured_image($post_id, $attachment_id) {
        $settings = get_option('img_collector_settings', array());

        if (!empty($settings['set_featured_image'])) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    /**
     * URL去重
     *
     * @param array $urls URL列表
     * @return array 唯一URL列表
     */
    private function deduplicate_urls($urls) {
        $unique = array();
        $seen = array();

        foreach ($urls as $url_data) {
            $url = is_array($url_data) ? $url_data['url'] : $url_data;
            $normalized = strtolower($url);

            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $unique[] = $url_data;
            }
        }

        return $unique;
    }

    /**
     * 获取已采集的记录
     *
     * @param string $url 原始URL
     * @return array|null 记录
     */
    private function get_existing_collection($url) {
        global $wpdb;

        $table = $wpdb->prefix . 'img_collector_logs';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return null;
        }

        $normalized_url = strtolower($url);

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT local_url FROM $table WHERE LOWER(original_url) = %s AND status = 'success' ORDER BY id DESC LIMIT 1",
            $normalized_url
        ), ARRAY_A);

        if ($result && !empty($result['local_url'])) {
            // 从本地URL获取attachment_id
            $attachment_id = $this->get_attachment_id_from_url($result['local_url']);
            return array(
                'local_url' => $result['local_url'],
                'attachment_id' => $attachment_id,
            );
        }

        return null;
    }

    /**
     * 从URL获取附件ID
     *
     * @param string $url 图片URL
     * @return int 附件ID
     */
    private function get_attachment_id_from_url($url) {
        global $wpdb;

        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
            $url
        ));

        return intval($attachment_id);
    }

    /**
     * 记录采集日志
     *
     * @param int $post_id 文章ID
     * @param string $original_url 原始URL
     * @param string $local_url 本地URL
     * @param string $status 状态
     * @param string $error 错误信息
     * @param int $attachment_id 附件ID
     */
    private function log_collection($post_id, $original_url, $local_url, $status, $error, $attachment_id = 0) {
        // 检查是否启用日志
        $settings = get_option('img_collector_settings', array());
        if (empty($settings['enable_log'])) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'img_collector_logs';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->insert($table, array(
            'post_id' => intval($post_id),
            'original_url' => $original_url,
            'local_url' => $local_url,
            'status' => $status,
            'error_message' => $error,
            'collected_at' => current_time('mysql'),
        ));

        // 清理过期日志
        $this->cleanup_old_logs();
    }

    /**
     * 清理过期日志
     */
    private function cleanup_old_logs() {
        $settings = get_option('img_collector_settings', array());
        $retention = isset($settings['log_retention']) ? intval($settings['log_retention']) : 30;

        if ($retention <= 0) {
            return; // 永久保留
        }

        global $wpdb;
        $table = $wpdb->prefix . 'img_collector_logs';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention} days"));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE collected_at < %s",
            $cutoff_date
        ));
    }

    /**
     * 获取最后的错误信息
     *
     * @return string 错误信息
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * 检查服务器是否支持图片处理
     *
     * @return array 支持情况
     */
    public function check_server_support() {
        $support = array(
            'gd' => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick') && class_exists('Imagick'),
            'curl' => extension_loaded('curl'),
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'fileinfo' => extension_loaded('fileinfo') && class_exists('finfo'),
        );

        return $support;
    }
}