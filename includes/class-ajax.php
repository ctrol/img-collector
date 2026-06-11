<?php
/**
 * AJAX处理类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Ajax {

    /**
     * 初始化
     */
    public static function init() {
        add_action('wp_ajax_img_collector_collect', array(__CLASS__, 'handle_collect'));
        add_action('wp_ajax_img_collector_scan', array(__CLASS__, 'handle_scan'));
        add_action('wp_ajax_img_collector_batch_collect', array(__CLASS__, 'handle_batch_collect'));
        add_action('wp_ajax_img_collector_get_images', array(__CLASS__, 'handle_get_images'));
        add_action('wp_ajax_img_collector_collect_single', array(__CLASS__, 'handle_collect_single'));
    }

    /**
     * 验证请求
     */
    private static function verify_request() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'img_collector_nonce')) {
            wp_send_json_error(array('message' => __('安全验证失败', 'img-collector')));
        }

        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('权限不足', 'img-collector')));
        }
    }

    /**
     * 获取POST参数
     */
    private static function get_post($key, $default = '') {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return sanitize_text_field($_POST[$key]);
    }

    /**
     * 获取POST参数（整数）
     */
    private static function get_post_int($key, $default = 0) {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return intval($_POST[$key]);
    }

    /**
     * 处理采集请求
     */
    public static function handle_collect() {
        self::verify_request();

        $post_id = self::get_post_int('post_id', 0);
        $method = self::get_post('method', 'server');

        if ($post_id <= 0) {
            wp_send_json_error(array('message' => __('无效的文章ID', 'img-collector')));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('找不到文章', 'img-collector')));
        }

        // 加载采集器
        require_once IMG_COLLECTOR_PATH . 'includes/class-collector.php';
        $collector = Img_Collector_Core::get_instance();

        // 提取图片
        $images = $collector->extract_external_images($post->post_content, $post_id);

        if (empty($images)) {
            wp_send_json_success(array(
                'message' => __('未检测到外链图片', 'img-collector'),
                'total' => 0,
                'results' => array(),
                'updated_content' => $post->post_content,
            ));
        }

        // 执行采集
        $urls = array();
        foreach ($images as $image) {
            $urls[] = $image['url'];
        }

        $results = $collector->collect_batch($urls, $post_id, $method);

        // 更新文章内容
        $new_content = $collector->update_content_urls($post->post_content, $results);

        // 如果内容有变化，更新文章
        if ($new_content !== $post->post_content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content,
            ));
        }

        // 设置特色图片
        $settings = get_option('img_collector_settings', array());
        if (!empty($settings['set_featured_image']) && !empty($results)) {
            foreach ($results as $result) {
                if (!is_wp_error($result) && isset($result['success']) && $result['success']) {
                    $collector->set_featured_image($post_id, $result['attachment_id']);
                    break;
                }
            }
        }

        // 统计结果
        $success_count = 0;
        $failed_count = 0;
        $details = array();

        foreach ($results as $result) {
            if (is_wp_error($result)) {
                $failed_count++;
                $details[] = array(
                    'success' => false,
                    'url' => '',
                    'message' => $result->get_error_message(),
                );
            } elseif (isset($result['success']) && $result['success']) {
                $success_count++;
                $details[] = array(
                    'success' => true,
                    'url' => isset($result['original_url']) ? $result['original_url'] : '',
                    'local_url' => isset($result['local_url']) ? $result['local_url'] : '',
                    'message' => __('成功', 'img-collector'),
                );
            } else {
                $failed_count++;
                $details[] = array(
                    'success' => false,
                    'url' => '',
                    'message' => __('失败', 'img-collector'),
                );
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('采集完成: 成功 %d, 失败 %d', 'img-collector'), $success_count, $failed_count),
            'total' => count($images),
            'success' => $success_count,
            'failed' => $failed_count,
            'results' => $details,
            'updated_content' => $new_content,
        ));
    }

    /**
     * 处理扫描请求
     */
    public static function handle_scan() {
        self::verify_request();

        require_once IMG_COLLECTOR_PATH . 'includes/class-scanner.php';

        $conditions = array(
            'type' => self::get_post('scan_type', 'post'),
            'status' => self::get_post('scan_status', 'publish'),
            'id_start' => self::get_post_int('scan_id_start', 0),
            'id_end' => self::get_post_int('scan_id_end', 0),
            'date_start' => self::get_post('scan_date_start', ''),
            'date_end' => self::get_post('scan_date_end', ''),
            'category' => self::get_post_int('scan_category', 0),
            'order' => self::get_post('scan_order', 'DESC'),
        );

        $scanner = Img_Collector_Scanner::get_instance();
        $results = $scanner->scan($conditions);

        wp_send_json_success(array(
            'total' => count($results),
            'results' => $results,
        ));
    }

    /**
     * 处理批量采集请求
     */
    public static function handle_batch_collect() {
        self::verify_request();

        if (!isset($_POST['post_ids']) || !is_array($_POST['post_ids'])) {
            wp_send_json_error(array('message' => __('请选择要采集的文章', 'img-collector')));
        }

        $post_ids = array_map('intval', $_POST['post_ids']);
        $method = self::get_post('method', 'server');

        if (empty($post_ids)) {
            wp_send_json_error(array('message' => __('请选择要采集的文章', 'img-collector')));
        }

        require_once IMG_COLLECTOR_PATH . 'includes/class-scanner.php';

        $scanner = Img_Collector_Scanner::get_instance();
        $results = $scanner->batch_collect($post_ids, $method);

        wp_send_json_success(array(
            'total' => count($post_ids),
            'results' => $results,
        ));
    }

    /**
     * 获取文章中的外链图片列表
     */
    public static function handle_get_images() {
        self::verify_request();

        $post_id = self::get_post_int('post_id', 0);
        
        // 优先使用前端发送的内容（未保存的草稿）
        $content = '';
        if (isset($_POST['content'])) {
            $content = wp_kses_post($_POST['content']);
        }
        
        // 如果没有前端内容，从数据库获取
        if (empty($content) && $post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                $content = $post->post_content;
            }
        }
        
        if (empty($content)) {
            wp_send_json_success(array(
                'images' => array(),
                'total' => 0,
            ));
        }

        // 加载采集器
        require_once IMG_COLLECTOR_PATH . 'includes/class-collector.php';
        $collector = Img_Collector_Core::get_instance();

        // 提取图片
        $images = $collector->extract_external_images($content, $post_id);

        $result = array();
        foreach ($images as $image) {
            $result[] = array(
                'url' => $image['url'],
                'alt' => isset($image['alt']) ? $image['alt'] : '',
                'original_tag' => isset($image['original_tag']) ? $image['original_tag'] : '',
            );
        }

        wp_send_json_success(array(
            'images' => $result,
            'total' => count($result),
        ));
    }

    /**
     * 采集单张图片
     */
    public static function handle_collect_single() {
        self::verify_request();

        $url = self::get_post('url', '');
        $post_id = self::get_post_int('post_id', 0);
        $method = self::get_post('method', 'server');

        if (empty($url)) {
            wp_send_json_error(array('message' => __('图片URL不能为空', 'img-collector')));
        }

        // 加载采集器
        require_once IMG_COLLECTOR_PATH . 'includes/class-collector.php';
        $collector = Img_Collector_Core::get_instance();

        $result = $collector->collect_single($url, $post_id, $method);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // 更新文章内容
        $post = get_post($post_id);
        if ($post) {
            $new_content = str_replace($url, $result['local_url'], $post->post_content);
            if ($new_content !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $new_content,
                ));
            }
        }

        wp_send_json_success(array(
            'original_url' => $url,
            'local_url' => $result['local_url'],
            'attachment_id' => $result['attachment_id'],
        ));
    }
}
