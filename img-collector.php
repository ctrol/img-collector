<?php
/**
 * Plugin Name: 远程图片采集器
 * Plugin URI: https://github.com/ctrol/img-collector
 * Description: 一款用于WordPress文章远程图片自动采集本地化的插件，支持多种采集模式、过滤规则、水印功能及全局扫描。
 * Version: 1.8.2
 * Author: 老九
 * Author URI: https://github.com/laojiu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: img-collector
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.2
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('IMG_COLLECTOR_VERSION', '1.8.2');
define('IMG_COLLECTOR_PATH', plugin_dir_path(__FILE__));
define('IMG_COLLECTOR_URL', plugin_dir_url(__FILE__));
define('IMG_COLLECTOR_BASENAME', plugin_basename(__FILE__));

/**
 * 插件激活函数
 */
function img_collector_activate() {
    // 检查版本兼容性
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(IMG_COLLECTOR_BASENAME);
        wp_die(__('此插件需要PHP 7.2或更高版本。', 'img-collector'));
    }

    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        deactivate_plugins(IMG_COLLECTOR_BASENAME);
        wp_die(__('此插件需要WordPress 6.0或更高版本。', 'img-collector'));
    }

    // 创建数据库表
    img_collector_create_tables();

    // 设置默认选项
    img_collector_set_default_options();

    // 清理旧的临时文件
    img_collector_cleanup_temp_files();

    flush_rewrite_rules();
}

/**
 * 插件停用函数
 */
function img_collector_deactivate() {
    flush_rewrite_rules();
}

/**
 * 创建数据库表
 */
function img_collector_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // 采集日志表
    $table_logs = $wpdb->prefix . 'img_collector_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL DEFAULT 0,
        original_url varchar(500) NOT NULL,
        local_url varchar(500) DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'pending',
        error_message text,
        collected_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_post_id (post_id),
        KEY idx_status (status)
    ) $charset_collate;";

    // 扫描任务表
    $table_tasks = $wpdb->prefix . 'img_collector_tasks';
    $sql_tasks = "CREATE TABLE $table_tasks (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        task_type varchar(20) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        conditions text,
        progress int(11) DEFAULT 0,
        total int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_logs);
    dbDelta($sql_tasks);

    // 记录版本
    update_option('img_collector_db_version', IMG_COLLECTOR_VERSION);
}

/**
 * 设置默认选项
 */
function img_collector_set_default_options() {
    $defaults = array(
        'collect_mode' => 'manual',
        'default_collect_method' => 'server',
        'set_featured_image' => false,
        'content_update_mode' => 'replace',
        'auto_set_category' => false,
        'max_width' => 0,
        'filename_rule' => 'original',
        'filename_custom' => '%filename%-%date%',
        'title_source' => 'original',
        'alt_source' => 'original',
        'image_quality' => 85,
        'convert_webp' => false,
        'max_concurrent' => 3,
        'timeout' => 30,
        'request_delay' => 500,
        'proxy_enabled' => false,
        'proxy_host' => '',
        'proxy_port' => '',
        'proxy_user' => '',
        'proxy_pass' => '',
        'filter_order_enabled' => false,
        'filter_order_start' => 1,
        'filter_order_end' => -1,
        'filter_size_enabled' => false,
        'filter_min_width' => 100,
        'filter_min_height' => 100,
        'filter_format_enabled' => false,
        'filter_formats' => array(),
        'filter_domain_enabled' => false,
        'filter_domains' => array(),
        'filter_dedup' => true,
        'watermark_enabled' => false,
        'watermark_type' => 'text',
        'watermark_image' => 0,
        'watermark_text' => '',
        'watermark_font' => 'Arial',
        'watermark_font_size' => 24,
        'watermark_font_color' => '#FFFFFF',
        'watermark_opacity' => 50,
        'watermark_position' => 'bottom-right',
        'watermark_backup' => true,
        'enable_log' => true,
        'log_retention' => 30,
    );

    if (!get_option('img_collector_settings')) {
        add_option('img_collector_settings', $defaults);
    }
}

/**
 * 清理临时文件
 */
function img_collector_cleanup_temp_files() {
    $upload_dir = wp_upload_dir();
    if (isset($upload_dir['basedir']) && is_dir($upload_dir['basedir'])) {
        $temp_dir = $upload_dir['basedir'] . '/img-collector-temp';
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file) && filemtime($file) < time() - 3600) {
                        @unlink($file);
                    }
                }
            }
        }
    }
}

// 注册激活和停用钩子
register_activation_hook(__FILE__, 'img_collector_activate');
register_deactivation_hook(__FILE__, 'img_collector_deactivate');

/**
 * 加载类文件
 */
function img_collector_load_dependencies() {
    require_once IMG_COLLECTOR_PATH . 'includes/class-collector.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-settings.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-filter.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-watermark.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-scanner.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-editor-integration.php';
    require_once IMG_COLLECTOR_PATH . 'includes/class-ajax.php';
}

/**
 * 初始化插件
 */
function img_collector_init() {
    // 加载依赖
    img_collector_load_dependencies();

    // 加载语言文件
    load_plugin_textdomain('img-collector', false, IMG_COLLECTOR_PATH . 'languages');

    // 初始化AJAX处理
    Img_Collector_Ajax::init();

    // 初始化编辑器集成
    Img_Collector_Editor_Integration::init();
}

/**
 * 添加管理菜单
 */
function img_collector_add_admin_menu() {
    // 确保依赖已加载
    if (!class_exists('Img_Collector_Settings')) {
        img_collector_load_dependencies();
    }

    // 加载设置类
    $settings = Img_Collector_Settings::get_instance();

    add_options_page(
        __('远程图片采集器设置', 'img-collector'),
        __('远程图片采集', 'img-collector'),
        'manage_options',
        'img-collector',
        array($settings, 'render_settings_page')
    );

    // 加载扫描器类
    $scanner = Img_Collector_Scanner::get_instance();

    add_menu_page(
        __('全局扫描', 'img-collector'),
        __('图片采集扫描', 'img-collector'),
        'manage_options',
        'img-collector-scan',
        array($scanner, 'render_scan_page'),
        'dashicons-images-alt2',
        30
    );
}

/**
 * 加载管理页面资源
 */
function img_collector_enqueue_admin_assets($hook) {
    // 仅在插件相关页面加载
    $valid_hooks = array(
        'settings_page_img-collector',
        'toplevel_page_img-collector-scan',
        'post.php',
        'post-new.php'
    );

    if (!in_array($hook, $valid_hooks)) {
        return;
    }

    // CSS
    wp_enqueue_style(
        'img-collector-admin',
        IMG_COLLECTOR_URL . 'assets/css/admin.css',
        array(),
        IMG_COLLECTOR_VERSION
    );

    // JavaScript
    wp_enqueue_script(
        'img-collector-admin',
        IMG_COLLECTOR_URL . 'assets/js/admin.js',
        array('jquery'),
        IMG_COLLECTOR_VERSION,
        true
    );

    // 本地化数据
    wp_localize_script('img-collector-admin', 'imgCollector', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('img_collector_nonce'),
        'i18n' => array(
            'collecting' => __('正在采集...', 'img-collector'),
            'success' => __('采集成功', 'img-collector'),
            'failed' => __('采集失败', 'img-collector'),
            'confirmScan' => __('确认开始扫描？', 'img-collector'),
            'confirmCollect' => __('确认开始批量采集？', 'img-collector'),
        ),
    ));
}

// 添加钩子
add_action('plugins_loaded', 'img_collector_init');
add_action('admin_menu', 'img_collector_add_admin_menu');
add_action('admin_enqueue_scripts', 'img_collector_enqueue_admin_assets');
