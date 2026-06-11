<?php
/**
 * 远程图片采集器 - 卸载脚本
 *
 * 当插件被卸载时，清理所有相关数据
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 防止通过非WordPress卸载流程访问
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 删除选项
delete_option('img_collector_settings');
delete_option('img_collector_version');

// 删除数据库表
$table_logs = $wpdb->prefix . 'img_collector_logs';
$table_tasks = $wpdb->prefix . 'img_collector_tasks';

$wpdb->query("DROP TABLE IF EXISTS $table_logs");
$wpdb->query("DROP TABLE IF EXISTS $table_tasks");

// 清理临时文件
$upload_dir = wp_upload_dir();
$temp_dir = $upload_dir['basedir'] . '/img-collector-temp';
$backup_dir = $upload_dir['basedir'] . '/img-collector-original';

// 删除临时目录
if (is_dir($temp_dir)) {
    $files = glob($temp_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($temp_dir);
}

// 备份目录保留（用户可能需要恢复原图）
// 但如果用户确认要完全卸载，可以删除
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($backup_dir);
}

// 清理文章meta（如果有）
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'img_collector_%'");

// 清理用户meta（如果有）
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'img_collector_%'");