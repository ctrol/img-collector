<?php
/**
 * 过滤规则类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Filter {

    /**
     * 单例实例
     */
    private static $instance = null;

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
     * 应用所有过滤规则
     *
     * @param array $images 图片列表
     * @param int $post_id 文章ID
     * @return array 过滤后的图片列表
     */
    public function apply_filters($images, $post_id = 0) {
        $settings = get_option('img_collector_settings', array());

        // 顺序过滤
        if (!empty($settings['filter_order_enabled'])) {
            $images = $this->filter_by_order($images, $settings);
        }

        // 尺寸过滤（需要获取图片尺寸信息）
        if (!empty($settings['filter_size_enabled'])) {
            $images = $this->filter_by_size($images, $settings);
        }

        // 格式过滤
        if (!empty($settings['filter_format_enabled'])) {
            $images = $this->filter_by_format($images, $settings);
        }

        // 域名过滤
        if (!empty($settings['filter_domain_enabled'])) {
            $images = $this->filter_by_domain($images, $settings);
        }

        return $images;
    }

    /**
     * 顺序过滤
     *
     * @param array $images 图片列表
     * @param array $settings 设置
     * @return array 过滤后的列表
     */
    private function filter_by_order($images, $settings) {
        $start = isset($settings['filter_order_start']) ? intval($settings['filter_order_start']) : 1;
        $end = isset($settings['filter_order_end']) ? intval($settings['filter_order_end']) : -1;

        // 计算实际索引
        $total = count($images);
        $start_index = max(0, $start - 1);

        if ($end === -1) {
            $end_index = $total;
        } else {
            $end_index = min($total, $end);
        }

        // 确保索引有效
        if ($start_index >= $end_index) {
            return array();
        }

        return array_slice($images, $start_index, $end_index - $start_index);
    }

    /**
     * 尺寸过滤
     *
     * @param array $images 图片列表
     * @param array $settings 设置
     * @return array 过滤后的列表
     */
    private function filter_by_size($images, $settings) {
        $min_width = isset($settings['filter_min_width']) ? intval($settings['filter_min_width']) : 100;
        $min_height = isset($settings['filter_min_height']) ? intval($settings['filter_min_height']) : 100;

        $filtered = array();

        foreach ($images as $image) {
            $url = isset($image['url']) ? $image['url'] : '';
            if (empty($url)) {
                continue;
            }

            // 尝试从URL参数获取尺寸
            $size = $this->get_image_size_from_url($url);

            if ($size) {
                if ($size['width'] >= $min_width && $size['height'] >= $min_height) {
                    $filtered[] = $image;
                }
            } else {
                // 无法获取尺寸时保留图片
                $filtered[] = $image;
            }
        }

        return $filtered;
    }

    /**
     * 从URL获取图片尺寸（尝试方法）
     *
     * @param string $url 图片URL
     * @return array|null 尺寸信息
     */
    private function get_image_size_from_url($url) {
        // 尝试从URL参数中获取
        $parsed = parse_url($url, PHP_URL_QUERY);
        if ($parsed) {
            $params = array();
            parse_str($parsed, $params);

            if (isset($params['w']) && isset($params['h'])) {
                return array(
                    'width' => intval($params['w']),
                    'height' => intval($params['h']),
                );
            }
        }

        // 尝试从文件名获取（如 image-800x600.jpg）
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $filename = basename($path);
            if (preg_match('/-(\d+)x(\d+)\./', $filename, $matches)) {
                return array(
                    'width' => intval($matches[1]),
                    'height' => intval($matches[2]),
                );
            }
        }

        // 无法获取，返回null
        return null;
    }

    /**
     * 格式过滤
     *
     * @param array $images 图片列表
     * @param array $settings 设置
     * @return array 过滤后的列表
     */
    private function filter_by_format($images, $settings) {
        $formats = isset($settings['filter_formats']) ? $settings['filter_formats'] : array();
        if (empty($formats) || !is_array($formats)) {
            return $images;
        }

        $filtered = array();

        foreach ($images as $image) {
            $url = isset($image['url']) ? $image['url'] : '';
            if (empty($url)) {
                continue;
            }
            
            $extension = $this->get_extension_from_url($url);

            // 过滤掉在列表中的格式（即保留不在列表中的格式）
            if (in_array(strtolower($extension), $formats)) {
                $filtered[] = $image;
            }
        }

        return $filtered;
    }

    /**
     * 域名过滤
     *
     * @param array $images 图片列表
     * @param array $settings 设置
     * @return array 过滤后的列表
     */
    private function filter_by_domain($images, $settings) {
        $domains = isset($settings['filter_domains']) ? $settings['filter_domains'] : array();
        if (empty($domains) || !is_array($domains)) {
            return $images;
        }

        $filtered = array();

        foreach ($images as $image) {
            $url = isset($image['url']) ? $image['url'] : '';
            if (empty($url)) {
                continue;
            }
            
            $host = parse_url($url, PHP_URL_HOST);

            if (!$host) {
                $filtered[] = $image;
                continue;
            }

            $should_filter = false;

            foreach ($domains as $filter_domain) {
                $filter_domain = trim($filter_domain);

                if (empty($filter_domain)) {
                    continue;
                }

                // 支持通配符
                if (strpos($filter_domain, '*') !== false) {
                    $pattern = str_replace('*', '', $filter_domain);
                    if (strpos($host, $pattern) !== false) {
                        $should_filter = true;
                        break;
                    }
                } else {
                    if ($host === $filter_domain) {
                        $should_filter = true;
                        break;
                    }
                }
            }

            if (!$should_filter) {
                $filtered[] = $image;
            }
        }

        return $filtered;
    }

    /**
     * 从URL获取扩展名
     *
     * @param string $url URL
     * @return string 扩展名
     */
    private function get_extension_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return '';
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return strtolower($extension);
    }

    /**
     * 检查URL是否应该被过滤
     *
     * @param string $url 图片URL
     * @return bool 是否应该过滤
     */
    public function should_filter_url($url) {
        $settings = get_option('img_collector_settings', array());

        // 域名过滤检查
        if (!empty($settings['filter_domain_enabled'])) {
            $domains = isset($settings['filter_domains']) ? $settings['filter_domains'] : array();
            $host = parse_url($url, PHP_URL_HOST);

            if ($host && is_array($domains)) {
                foreach ($domains as $filter_domain) {
                    $filter_domain = trim($filter_domain);
                    if (empty($filter_domain)) {
                        continue;
                    }

                    if (strpos($filter_domain, '*') !== false) {
                        $pattern = str_replace('*', '', $filter_domain);
                        if (strpos($host, $pattern) !== false) {
                            return true;
                        }
                    } elseif ($host === $filter_domain) {
                        return true;
                    }
                }
            }
        }

        // 格式过滤检查
        if (!empty($settings['filter_format_enabled'])) {
            $formats = isset($settings['filter_formats']) ? $settings['filter_formats'] : array();
            $extension = $this->get_extension_from_url($url);

            if (is_array($formats) && in_array(strtolower($extension), $formats)) {
                return true;
            }
        }

        return false;
    }
}