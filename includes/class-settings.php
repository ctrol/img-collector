<?php
/**
 * 设置管理类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.1
 */

if (!defined('ABSPATH')) exit;

class Img_Collector_Settings {
    private static $instance = null;
    private $option_name = 'img_collector_settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        register_setting('img_collector_group', $this->option_name);
    }

    public function render_settings_page() {
        $settings = get_option($this->option_name, array());
        ?>
        <div class="wrap">
            <h1>远程图片采集器设置</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('img_collector_group'); ?>
                
                <h2 class="nav-tab-wrapper">
                    <a href="#" class="nav-tab nav-tab-active" onclick="showTab('general');return false;">常规设置</a>
                    <a href="#" class="nav-tab" onclick="showTab('image');return false;">图片处理</a>
                    <a href="#" class="nav-tab" onclick="showTab('server');return false;">服务器优化</a>
                    <a href="#" class="nav-tab" onclick="showTab('proxy');return false;">代理设置</a>
                    <a href="#" class="nav-tab" onclick="showTab('watermark');return false;">水印设置</a>
                    <a href="#" class="nav-tab" onclick="showTab('logs');return false;">日志管理</a>
                </h2>
                
                <!-- 常规设置 -->
                <div id="tab-general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th>采集模式</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[collect_mode]">
                                    <option value="manual" <?php selected($settings['collect_mode'] ?? 'manual', 'manual'); ?>>手动采集</option>
                                    <option value="auto" <?php selected($settings['collect_mode'] ?? '', 'auto'); ?>>自动采集</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>默认采集方法</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[default_collect_method]">
                                    <option value="server" <?php selected($settings['default_collect_method'] ?? 'server', 'server'); ?>>服务器采集</option>
                                    <option value="proxy" <?php selected($settings['default_collect_method'] ?? '', 'proxy'); ?>>代理采集</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>设置特色图片</th>
                            <td>
                                <label><input type="checkbox" name="<?php echo $this->option_name; ?>[set_featured_image]" value="1" <?php checked($settings['set_featured_image'] ?? false); ?>> 将采集的第一张图片设置为文章特色图片</label>
                            </td>
                        </tr>
                        <tr>
                            <th>内容更新方式</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[content_update_mode]">
                                    <option value="replace" <?php selected($settings['content_update_mode'] ?? 'replace', 'replace'); ?>>替换模式</option>
                                    <option value="keep_both" <?php selected($settings['content_update_mode'] ?? '', 'keep_both'); ?>>保留双份</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 图片处理 -->
                <div id="tab-image" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>最大宽度</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[max_width]" value="<?php echo esc_attr($settings['max_width'] ?? 0); ?>" class="small-text"> px (0表示不限制)</td>
                        </tr>
                        <tr>
                            <th>图片质量</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[image_quality]" value="<?php echo esc_attr($settings['image_quality'] ?? 85); ?>" min="10" max="100" class="small-text"> %</td>
                        </tr>
                        <tr>
                            <th>DOM属性清洗</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo $this->option_name; ?>[dom_attr_clean_enabled]" value="1" <?php checked($settings['dom_attr_clean_enabled'] ?? true); ?>>
                                    启用DOM属性清洗
                                </label>
                                <p class="description">启用后，图片采集后img标签内仅保留src和alt属性，其他属性全部清除</p>
                            </td>
                        </tr>
                        <tr>
                            <th>图片alt属性</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[dom_attr_alt_option]">
                                    <?php foreach (array('keep' => '保留原alt属性', 'custom' => '自定义alt属性', 'empty' => '清空alt属性') as $value => $label) : ?>
                                    <option value="<?php echo $value; ?>" <?php selected($settings['dom_attr_alt_option'] ?? 'keep', $value); ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>自定义alt文本</th>
                            <td>
                                <input type="text" name="<?php echo $this->option_name; ?>[dom_attr_custom_alt]" value="<?php echo esc_attr($settings['dom_attr_custom_alt'] ?? ''); ?>" class="regular-text">
                                <p class="description">当选择"自定义alt属性"时使用此文本</p>
                            </td>
                        </tr>
                        <tr>
                            <th>图片对齐方式</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[dom_attr_align]">
                                    <?php foreach (array('none' => '默认', 'left' => '左对齐', 'center' => '居中', 'right' => '右对齐') as $value => $label) : ?>
                                    <option value="<?php echo $value; ?>" <?php selected($settings['dom_attr_align'] ?? 'none', $value); ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">设置图片的对齐方式</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 服务器优化 -->
                <div id="tab-server" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>最大并发数</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[max_concurrent]" value="<?php echo esc_attr($settings['max_concurrent'] ?? 3); ?>" min="1" max="10" class="small-text"></td>
                        </tr>
                        <tr>
                            <th>超时时间</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[timeout]" value="<?php echo esc_attr($settings['timeout'] ?? 30); ?>" min="5" max="300" class="small-text"> 秒</td>
                        </tr>
                    </table>
                </div>
                
                <!-- 代理设置 -->
                <div id="tab-proxy" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>启用代理</th>
                            <td><label><input type="checkbox" name="<?php echo $this->option_name; ?>[proxy_enabled]" value="1" <?php checked($settings['proxy_enabled'] ?? false); ?>> 启用代理服务器</label></td>
                        </tr>
                        <tr>
                            <th>代理主机</th>
                            <td><input type="text" name="<?php echo $this->option_name; ?>[proxy_host]" value="<?php echo esc_attr($settings['proxy_host'] ?? ''); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>代理端口</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[proxy_port]" value="<?php echo esc_attr($settings['proxy_port'] ?? ''); ?>" class="small-text"></td>
                        </tr>
                    </table>
                </div>
                
                <!-- 水印设置 -->
                <div id="tab-watermark" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>启用水印</th>
                            <td><label><input type="checkbox" name="<?php echo $this->option_name; ?>[watermark_enabled]" value="1" <?php checked($settings['watermark_enabled'] ?? false); ?>> 启用水印功能 (需要Imagick扩展)</label></td>
                        </tr>
                        <tr>
                            <th>水印文字</th>
                            <td><input type="text" name="<?php echo $this->option_name; ?>[watermark_text]" value="<?php echo esc_attr($settings['watermark_text'] ?? ''); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>字体</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[watermark_font]">
                                    <?php 
                                    // 内置字体列表
                                    $built_in_fonts = array(
                                        'Arial' => 'Arial',
                                        'Arial Black' => 'Arial Black',
                                        'Comic Sans MS' => 'Comic Sans MS',
                                        'Courier New' => 'Courier New',
                                        'Georgia' => 'Georgia',
                                        'Impact' => 'Impact',
                                        'Tahoma' => 'Tahoma',
                                        'Times New Roman' => 'Times New Roman',
                                        'Trebuchet MS' => 'Trebuchet MS',
                                        'Verdana' => 'Verdana',
                                        'Microsoft YaHei' => '微软雅黑',
                                        'SimHei' => '黑体',
                                        'SimSun' => '宋体',
                                        'KaiTi' => '楷体',
                                    );
                                    
                                    // 扫描fonts目录中的自定义字体
                                    $custom_fonts = array();
                                    $fonts_dir = IMG_COLLECTOR_PATH . 'fonts';
                                    if (is_dir($fonts_dir)) {
                                        $font_files = glob($fonts_dir . '/*.{ttf,ttc,otf,woff,woff2}', GLOB_BRACE);
                                        foreach ($font_files as $font_file) {
                                            $font_name = basename($font_file);
                                            $custom_fonts[$font_name] = '[自定义] ' . $font_name;
                                        }
                                    }
                                    
                                    // 合并字体列表（内置字体在前，自定义字体在后）
                                    $all_fonts = array_merge($built_in_fonts, $custom_fonts);
                                    
                                    foreach ($all_fonts as $font => $label) : ?>
                                    <option value="<?php echo esc_attr($font); ?>" <?php selected($settings['watermark_font'] ?? 'Arial', $font); ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">需要服务器支持GD库和FreeType。如需使用自定义字体，请将TTF/TTC/OTF字体文件上传到插件的fonts目录。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>字体大小</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[watermark_font_size]" value="<?php echo esc_attr($settings['watermark_font_size'] ?? 24); ?>" min="10" max="100" class="small-text"> px</td>
                        </tr>
                        <tr>
                            <th>字体颜色</th>
                            <td>
                                <input type="color" id="watermark-font-color-picker" value="<?php echo esc_attr($settings['watermark_font_color'] ?? '#FFFFFF'); ?>" class="small-text" style="width: 60px; height: 30px;">
                                <input type="text" name="<?php echo $this->option_name; ?>[watermark_font_color]" id="watermark-font-color-input" value="<?php echo esc_attr($settings['watermark_font_color'] ?? '#FFFFFF'); ?>" class="small-text" style="width: 80px; margin-left: 10px;">
                            </td>
                        </tr>
                        <tr>
                            <th>水印位置</th>
                            <td>
                                <select name="<?php echo $this->option_name; ?>[watermark_position]">
                                    <?php foreach (array('top-left' => '左上', 'top-center' => '上中', 'top-right' => '右上', 'middle-left' => '左中', 'middle-center' => '居中', 'middle-right' => '右中', 'bottom-left' => '左下', 'bottom-center' => '下中', 'bottom-right' => '右下') as $pos => $label) : ?>
                                    <option value="<?php echo $pos; ?>" <?php selected($settings['watermark_position'] ?? 'bottom-right', $pos); ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>透明度</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[watermark_opacity]" value="<?php echo esc_attr($settings['watermark_opacity'] ?? 50); ?>" min="0" max="100" class="small-text"> %</td>
                        </tr>
                    </table>
                </div>
                
                <!-- 日志管理 -->
                <div id="tab-logs" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th>启用日志</th>
                            <td><label><input type="checkbox" name="<?php echo $this->option_name; ?>[enable_log]" value="1" <?php checked($settings['enable_log'] ?? true); ?>> 启用日志记录</label></td>
                        </tr>
                        <tr>
                            <th>日志保留天数</th>
                            <td><input type="number" name="<?php echo $this->option_name; ?>[log_retention]" value="<?php echo esc_attr($settings['log_retention'] ?? 30); ?>" min="1" max="365" class="small-text"> 天</td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="保存设置">
                </p>
            </form>
        </div>
        
        <script>
        function showTab(tabName) {
            var contents = document.querySelectorAll('.tab-content');
            for (var i = 0; i < contents.length; i++) {
                contents[i].style.display = 'none';
            }
            document.getElementById('tab-' + tabName).style.display = 'block';
            
            var tabs = document.querySelectorAll('.nav-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('nav-tab-active');
            }
            event.target.classList.add('nav-tab-active');
        }
        
        // 同步颜色选择器和文本输入框
        document.addEventListener('DOMContentLoaded', function() {
            var colorPicker = document.getElementById('watermark-font-color-picker');
            var colorInput = document.getElementById('watermark-font-color-input');
            
            if (colorPicker && colorInput) {
                // 颜色选择器变化时更新文本输入框
                colorPicker.addEventListener('input', function() {
                    colorInput.value = colorPicker.value;
                });
                
                // 文本输入框变化时更新颜色选择器
                colorInput.addEventListener('input', function() {
                    var value = colorInput.value;
                    // 验证颜色值
                    if (/^#[0-9A-Fa-f]{6}$/.test(value) || /^#[0-9A-Fa-f]{3}$/.test(value)) {
                        colorPicker.value = value;
                    }
                });
            }
        });
        </script>
        <?php
    }
}
