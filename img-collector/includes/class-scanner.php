<?php
/**
 * 全局扫描类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Scanner {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 每批次处理数量
     */
    private $batch_size = 50;

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
    private function __construct() {}

    /**
     * 渲染扫描页面
     */
    public function render_scan_page() {
        ?>
        <div class="wrap img-collector-scan">
            <h1><?php _e('全局图片扫描', 'img-collector'); ?></h1>

            <div class="scan-form">
                <h2><?php _e('扫描条件设置', 'img-collector'); ?></h2>
                <form id="scan-form">
                    <table class="form-table">
                        <tr>
                            <th><?php _e('内容类型', 'img-collector'); ?></th>
                            <td>
                                <select name="scan_type" id="scan_type">
                                    <option value="post"><?php _e('文章', 'img-collector'); ?></option>
                                    <option value="page"><?php _e('页面', 'img-collector'); ?></option>
                                    <option value="all"><?php _e('全部', 'img-collector'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('文章状态', 'img-collector'); ?></th>
                            <td>
                                <select name="scan_status" id="scan_status">
                                    <option value="publish"><?php _e('已发布', 'img-collector'); ?></option>
                                    <option value="draft"><?php _e('草稿', 'img-collector'); ?></option>
                                    <option value="all"><?php _e('全部', 'img-collector'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('ID范围', 'img-collector'); ?></th>
                            <td>
                                <input type="number" name="scan_id_start" id="scan_id_start" min="0" placeholder="<?php _e('起始ID', 'img-collector'); ?>" class="small-text">
                                <input type="number" name="scan_id_end" id="scan_id_end" min="0" placeholder="<?php _e('结束ID', 'img-collector'); ?>" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('时间范围', 'img-collector'); ?></th>
                            <td>
                                <input type="date" name="scan_date_start" id="scan_date_start" placeholder="<?php _e('起始日期', 'img-collector'); ?>">
                                <input type="date" name="scan_date_end" id="scan_date_end" placeholder="<?php _e('结束日期', 'img-collector'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('分类', 'img-collector'); ?></th>
                            <td>
                                <?php
                                $categories = get_categories(array('hide_empty' => false));
                                ?>
                                <select name="scan_category" id="scan_category">
                                    <option value="0"><?php _e('全部分类', 'img-collector'); ?></option>
                                    <?php foreach ($categories as $cat) { ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                    <?php } ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('排序方式', 'img-collector'); ?></th>
                            <td>
                                <select name="scan_order" id="scan_order">
                                    <option value="DESC"><?php _e('ID降序', 'img-collector'); ?></option>
                                    <option value="ASC"><?php _e('ID升序', 'img-collector'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="button" class="button button-primary" id="start-scan"><?php _e('开始扫描', 'img-collector'); ?></button>
                    </p>
                </form>
            </div>

            <div class="scan-results" id="scan-results" style="display:none;">
                <h2><?php _e('扫描结果', 'img-collector'); ?></h2>
                <div class="scan-stats" id="scan-stats"></div>
                <div class="scan-progress" id="scan-progress"></div>
                <table class="widefat striped" id="results-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th><?php _e('文章ID', 'img-collector'); ?></th>
                            <th><?php _e('文章标题', 'img-collector'); ?></th>
                            <th><?php _e('外链图片数', 'img-collector'); ?></th>
                            <th><?php _e('状态', 'img-collector'); ?></th>
                            <th><?php _e('操作', 'img-collector'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="results-body">
                    </tbody>
                </table>
                <p class="submit">
                    <button type="button" class="button button-primary" id="batch-collect"><?php _e('批量采集选中项', 'img-collector'); ?></button>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * 执行扫描
     *
     * @param array $conditions 扫描条件
     * @return array 扫描结果
     */
    public function scan($conditions) {
        global $wpdb;

        // 构建查询
        $args = array(
            'post_type' => $conditions['type'] === 'all' ? array('post', 'page') : $conditions['type'],
            'post_status' => $conditions['status'] === 'all' ? array('publish', 'draft', 'pending') : $conditions['status'],
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => $conditions['order'],
        );

        // ID范围 - 修复逻辑
        if (!empty($conditions['id_start']) && !empty($conditions['id_end'])) {
            // 两个ID都指定时
            $args['post__in'] = range(intval($conditions['id_start']), intval($conditions['id_end']));
        } elseif (!empty($conditions['id_start'])) {
            // 只指定起始ID
            $args['post__in'] = range(intval($conditions['id_start']), PHP_INT_MAX);
        } elseif (!empty($conditions['id_end'])) {
            // 只指定结束ID - 使用post__not_in
            $args['post__not_in'] = range(1, intval($conditions['id_end']) - 1);
        }

        // 时间范围
        if (!empty($conditions['date_start'])) {
            $args['date_query'][] = array(
                'after' => $conditions['date_start'],
                'inclusive' => true,
            );
        }

        if (!empty($conditions['date_end'])) {
            $args['date_query'][] = array(
                'before' => $conditions['date_end'],
                'inclusive' => true,
            );
        }

        // 分类
        if ($conditions['category'] > 0) {
            $args['category__in'] = array($conditions['category']);
        }

        // 获取文章
        $posts = get_posts($args);

        $results = array();
        $collector = Img_Collector_Core::get_instance();

        foreach ($posts as $post) {
            // 检查文章内容中的外链图片
            $images = $collector->extract_external_images($post->post_content, $post->ID);

            if (!empty($images)) {
                $results[] = array(
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'image_count' => count($images),
                    'images' => $images,
                    'status' => 'pending',
                );
            }
        }

        return $results;
    }

    /**
     * 批量采集
     *
     * @param array $post_ids 文章ID列表
     * @param string $method 采集方法
     * @return array 采集结果
     */
    public function batch_collect($post_ids, $method = 'server') {
        $results = array();
        $collector = Img_Collector_Core::get_instance();
        $settings = get_option('img_collector_settings', array());

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            // 提取外链图片
            $images = $collector->extract_external_images($post->post_content, $post_id);

            if (empty($images)) {
                $results[] = array(
                    'post_id' => $post_id,
                    'success' => true,
                    'message' => __('无外链图片', 'img-collector'),
                );
                continue;
            }

            // 执行采集
            $urls = array();
            foreach ($images as $image) {
                $urls[] = $image['url'];
            }

            $collect_results = $collector->collect_batch($urls, $post_id, $method);

            // 更新文章内容
            $new_content = $collector->update_content_urls($post->post_content, $collect_results);

            if ($new_content !== $post->post_content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $new_content,
                ));
            }

            // 设置特色图片
            if ($settings['set_featured_image'] && !empty($collect_results)) {
                foreach ($collect_results as $result) {
                    if (!is_wp_error($result) && $result['success']) {
                        $collector->set_featured_image($post_id, $result['attachment_id']);
                        break;
                    }
                }
            }

            // 统计结果
            $success_count = 0;
            $failed_count = 0;

            foreach ($collect_results as $result) {
                if (is_wp_error($result) || !$result['success']) {
                    $failed_count++;
                } else {
                    $success_count++;
                }
            }

            $results[] = array(
                'post_id' => $post_id,
                'success' => $failed_count === 0,
                'message' => sprintf(__('成功: %d, 失败: %d', 'img-collector'), $success_count, $failed_count),
                'details' => $collect_results,
            );

            // 避免服务器过载
            usleep(500000);
        }

        return $results;
    }

    /**
     * 获取扫描任务状态
     *
     * @param int $task_id 任务ID
     * @return array|null 任务状态
     */
    public function get_task_status($task_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'img_collector_tasks';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return null;
        }

        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            intval($task_id)
        ), ARRAY_A);

        return $task;
    }

    /**
     * 清除运行中的任务
     */
    public function clear_running_tasks() {
        global $wpdb;

        $table = $wpdb->prefix . 'img_collector_tasks';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->update(
            $table,
            array('status' => 'paused'),
            array('status' => 'running'),
            array('%s'),
            array('%s')
        );
    }

    /**
     * 获取历史采集日志
     *
     * @param int $post_id 文章ID
     * @return array 日志列表
     */
    public function get_collection_logs($post_id = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'img_collector_logs';

        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }

        if ($post_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE post_id = %d ORDER BY collected_at DESC",
                intval($post_id)
            ), ARRAY_A);
        }

        return $wpdb->get_results(
            "SELECT * FROM $table ORDER BY collected_at DESC LIMIT 100",
            ARRAY_A
        );
    }
}