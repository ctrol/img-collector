<?php
/**
 * 编辑器集成类
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Img_Collector_Editor_Integration {

    /**
     * 初始化
     */
    public static function init() {
        // 经典编辑器集成
        add_action('media_buttons', array(__CLASS__, 'add_collect_button'), 20);
        add_action('admin_footer', array(__CLASS__, 'render_collect_modal'));

        // 自动采集钩子
        add_action('save_post', array(__CLASS__, 'auto_collect_on_save'), 20, 3);
    }

    /**
     * 添加采集按钮到编辑器工具栏
     */
    public static function add_collect_button($editor_id) {
        // 仅在内容编辑器区域显示
        if ($editor_id !== 'content') {
            return;
        }

        // 检查是否为支持的文章类型
        global $post;
        if (!$post || !in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        // 检查采集模式
        $settings = get_option('img_collector_settings', array());
        $collect_mode = isset($settings['collect_mode']) ? $settings['collect_mode'] : 'manual';

        if ($collect_mode !== 'manual') {
            return;
        }

        // 输出按钮
        $post_id = $post->ID;
        echo '<button type="button" id="img-collector-btn" class="button" data-post-id="' . esc_attr($post_id) . '">';
        echo '<span class="dashicons dashicons-download" style="line-height:1.4;"></span> ';
        echo esc_html__('保存站外图片', 'img-collector');
        echo '</button>';
    }

    /**
     * 渲染采集模态窗口
     */
    public static function render_collect_modal() {
        global $post;

        if (!$post) {
            return;
        }

        // 检查是否为文章编辑页面
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, array('post', 'page'))) {
            return;
        }

        // 获取设置
        $settings = get_option('img_collector_settings', array());
        $default_method = isset($settings['default_collect_method']) ? $settings['default_collect_method'] : 'server';
        ?>
        <style>
        #img-collector-modal-overlay {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        #img-collector-modal-box {
            position: relative;
            background-color: #fff;
            margin: 3% auto;
            padding: 0;
            width: 90%;
            max-width: 800px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        #img-collector-modal-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #img-collector-modal-header h2 {
            margin: 0;
            font-size: 18px;
            color: #fff;
        }
        #img-collector-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            color: #fff;
            padding: 0;
        }
        #img-collector-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        #img-collector-modal-footer {
            padding: 15px 20px;
            background: #f1f1f1;
            border-top: 1px solid #ddd;
            border-radius: 0 0 8px 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* 图片列表样式 */
        .img-collector-image-list {
            border: 1px solid #dcdcde;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
        }
        .img-collector-image-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f1;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }
        .img-collector-image-item:last-child {
            border-bottom: none;
        }
        .img-collector-image-item:hover {
            background: #f6f7f7;
        }
        .img-collector-image-item.pending {
            background: #fff;
        }
        .img-collector-image-item.collecting {
            background: #e5f6fd;
        }
        .img-collector-image-item.success {
            background: #e8f5e9;
        }
        .img-collector-image-item.failed {
            background: #ffebee;
        }
        .img-collector-image-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        .img-collector-image-item .img-thumb {
            width: 50px;
            height: 40px;
            object-fit: cover;
            border-radius: 3px;
            background: #f0f0f1;
            flex-shrink: 0;
        }
        .img-collector-image-item .img-info {
            flex: 1;
            min-width: 0;
        }
        .img-collector-image-item .img-url {
            font-size: 12px;
            color: #3c434a;
            word-break: break-all;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .img-collector-image-item .img-domain {
            font-size: 11px;
            color: #646970;
            margin-top: 3px;
        }
        .img-collector-image-item .img-status {
            width: 80px;
            text-align: center;
            flex-shrink: 0;
        }
        .img-collector-image-item .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 500;
        }
        .img-collector-image-item .status-badge.pending {
            background: #f0f0f1;
            color: #646970;
        }
        .img-collector-image-item .status-badge.collecting {
            background: #2271b1;
            color: #fff;
        }
        .img-collector-image-item .status-badge.success {
            background: #00a32a;
            color: #fff;
        }
        .img-collector-image-item .status-badge.failed {
            background: #d63638;
            color: #fff;
        }
        .img-collector-image-item .retry-btn {
            padding: 3px 8px;
            font-size: 11px;
            background: #f0a33e;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .img-collector-image-item .retry-btn:hover {
            background: #e08e2a;
        }
        
        /* 统计栏 */
        .img-collector-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            padding: 12px 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        .img-collector-stats .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .img-collector-stats .stat-label {
            color: #646970;
            font-size: 13px;
        }
        .img-collector-stats .stat-value {
            font-weight: 600;
            font-size: 14px;
        }
        .img-collector-stats .stat-value.pending { color: #646970; }
        .img-collector-stats .stat-value.collecting { color: #2271b1; }
        .img-collector-stats .stat-value.success { color: #00a32a; }
        .img-collector-stats .stat-value.failed { color: #d63638; }
        
        /* 全选按钮 */
        .img-collector-select-all {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        /* 采集方式选择 */
        .img-collector-method-select {
            margin-bottom: 15px;
            padding: 12px 15px;
            background: #f0f6fc;
            border-radius: 4px;
            border-left: 4px solid #2271b1;
        }
        .img-collector-method-select label {
            font-weight: 500;
            margin-right: 10px;
            color: #1d2327;
        }
        .img-collector-method-select select {
            padding: 6px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
        }
        
        /* 加载动画 */
        .img-collector-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #2271b1;
            border-radius: 50%;
            animation: img-collector-spin 1s linear infinite;
            vertical-align: middle;
        }
        @keyframes img-collector-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* 空状态 */
        .img-collector-empty {
            text-align: center;
            padding: 40px;
            color: #646970;
        }
        </style>

        <div id="img-collector-modal-overlay">
            <div id="img-collector-modal-box">
                <div id="img-collector-modal-header">
                    <h2><?php _e('远程图片采集', 'img-collector'); ?> <span id="img-count" style="font-size:14px;font-weight:normal;"></span></h2>
                    <button type="button" id="img-collector-modal-close">&times;</button>
                </div>
                <div id="img-collector-modal-body">
                    <!-- 加载中 -->
                    <div id="img-collector-loading" style="text-align:center;padding:40px;">
                        <span class="img-collector-loading" style="width:32px;height:32px;border-width:3px;"></span>
                        <p style="margin:15px 0 0;color:#646970;"><?php _e('正在检测外链图片...', 'img-collector'); ?></p>
                    </div>
                    
                    <!-- 图片列表 -->
                    <div id="img-collector-list" style="display:none;">
                        <div class="img-collector-method-select">
                            <label for="img-collector-method"><?php _e('采集方式:', 'img-collector'); ?></label>
                            <select id="img-collector-method">
                                <option value="server" <?php selected($default_method, 'server'); ?>><?php _e('服务器采集（推荐）', 'img-collector'); ?></option>
                                <option value="proxy" <?php selected($default_method, 'proxy'); ?>><?php _e('代理采集', 'img-collector'); ?></option>
                            </select>
                        </div>
                        
                        <div class="img-collector-select-all">
                            <input type="checkbox" id="select-all-images" checked>
                            <label for="select-all-images"><?php _e('全选', 'img-collector'); ?></label>
                        </div>
                        
                        <div class="img-collector-stats">
                            <div class="stat-item">
                                <span class="stat-label"><?php _e('待采集:', 'img-collector'); ?></span>
                                <span class="stat-value pending" id="stat-pending">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label"><?php _e('采集中:', 'img-collector'); ?></span>
                                <span class="stat-value collecting" id="stat-collecting">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label"><?php _e('成功:', 'img-collector'); ?></span>
                                <span class="stat-value success" id="stat-success">0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label"><?php _e('失败:', 'img-collector'); ?></span>
                                <span class="stat-value failed" id="stat-failed">0</span>
                            </div>
                        </div>
                        
                        <div class="img-collector-image-list" id="img-list"></div>
                    </div>
                    
                    <!-- 空状态 -->
                    <div id="img-collector-empty" class="img-collector-empty" style="display:none;">
                        <span class="dashicons dashicons-format-image" style="font-size:48px;color:#c3c4c7;"></span>
                        <p><?php _e('未检测到外链图片', 'img-collector'); ?></p>
                    </div>
                </div>
                <div id="img-collector-modal-footer">
                    <span id="img-collector-status"></span>
                    <div>
                        <button type="button" class="button" id="img-collector-cancel"><?php _e('关闭', 'img-collector'); ?></button>
                        <button type="button" class="button" id="img-collector-retry" style="display:none;background:#f0a33e;color:#fff;border-color:#f0a33e;"><?php _e('重试失败项', 'img-collector'); ?></button>
                        <button type="button" class="button button-primary" id="img-collector-start"><?php _e('开始采集', 'img-collector'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var modal = $('#img-collector-modal-overlay');
            var postId = 0;
            var images = [];
            var isCollecting = false;

            // 打开模态窗口
            $('#img-collector-btn').on('click', function() {
                postId = $(this).data('post-id') || 0;
                if (postId > 0) {
                    modal.show();
                    loadImages();
                }
            });

            // 关闭模态窗口
            $('#img-collector-modal-close, #img-collector-cancel').on('click', function() {
                if (!isCollecting) {
                    modal.hide();
                }
            });

            // 点击遮罩关闭
            modal.on('click', function(e) {
                if (e.target === this && !isCollecting) {
                    modal.hide();
                }
            });

            // 从编辑器获取内容
            function getEditorContent() {
                var content = '';
                
                // 优先从TinyMCE获取
                if (typeof tinymce !== 'undefined') {
                    var editor = tinymce.get('content');
                    if (editor && !editor.isHidden()) {
                        content = editor.getContent();
                    }
                }
                
                // 如果TinyMCE没有内容，从textarea获取
                if (!content) {
                    var contentEditor = document.getElementById('content');
                    if (contentEditor) {
                        content = contentEditor.value;
                    }
                }
                
                return content;
            }
            
            // 加载图片列表
            function loadImages() {
                $('#img-collector-loading').show();
                $('#img-collector-list').hide();
                $('#img-collector-empty').hide();
                $('#img-collector-start').prop('disabled', false).text('<?php _e('开始采集', 'img-collector'); ?>');
                $('#img-collector-retry').hide();
                $('#img-collector-status').empty();
                images = [];
                
                // 获取编辑器内容
                var editorContent = getEditorContent();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'img_collector_get_images',
                        nonce: '<?php echo wp_create_nonce('img_collector_nonce'); ?>',
                        post_id: postId,
                        content: editorContent
                    },
                    success: function(response) {
                        $('#img-collector-loading').hide();
                        
                        if (response.success && response.data.images && response.data.images.length > 0) {
                            images = response.data.images.map(function(img, index) {
                                return {
                                    index: index,
                                    url: img.url,
                                    alt: img.alt || '',
                                    originalTag: img.original_tag || '',
                                    domain: extractDomain(img.url),
                                    status: 'pending',
                                    localUrl: ''
                                };
                            });
                            
                            $('#img-count').text('(' + images.length + ' <?php _e('张', 'img-collector'); ?>)');
                            renderImageList();
                            updateStats();
                            $('#img-collector-list').show();
                        } else {
                            $('#img-count').text('(0)');
                            $('#img-collector-empty').show();
                        }
                    },
                    error: function() {
                        $('#img-collector-loading').hide();
                        $('#img-collector-empty').html('<p style="color:#d63638;"><?php _e('加载失败', 'img-collector'); ?></p>').show();
                    }
                });
            }

            // 提取域名
            function extractDomain(url) {
                try {
                    var a = document.createElement('a');
                    a.href = url;
                    return a.hostname;
                } catch(e) {
                    return '';
                }
            }

            // 渲染图片列表
            function renderImageList() {
                var html = '';
                images.forEach(function(img) {
                    var checked = img.status === 'pending' ? 'checked' : '';
                    var disabled = img.status !== 'pending' && img.status !== 'failed' ? 'disabled' : '';
                    var statusClass = img.status;
                    var statusText = '';
                    var retryBtn = '';
                    
                    switch(img.status) {
                        case 'pending':
                            statusText = '<span class="status-badge pending"><?php _e('待采集', 'img-collector'); ?></span>';
                            break;
                        case 'collecting':
                            statusText = '<span class="status-badge collecting"><span class="img-collector-loading" style="width:12px;height:12px;border-width:2px;"></span></span>';
                            break;
                        case 'success':
                            statusText = '<span class="status-badge success"><?php _e('已采集', 'img-collector'); ?></span>';
                            break;
                        case 'failed':
                            statusText = '<span class="status-badge failed"><?php _e('失败', 'img-collector'); ?></span>';
                            retryBtn = '<button class="retry-btn" data-index="' + img.index + '"><?php _e('重试', 'img-collector'); ?></button>';
                            break;
                    }
                    
                    html += '<div class="img-collector-image-item ' + statusClass + '" data-index="' + img.index + '">';
                    html += '<input type="checkbox" class="img-checkbox" data-index="' + img.index + '" ' + checked + ' ' + disabled + '>';
                    var thumbUrl = img.localUrl ? img.localUrl : img.url;
                    html += '<img class="img-thumb" src="' + escapeHtml(thumbUrl) + '" onerror="this.style.display=\'none\'">';
                    html += '<div class="img-info">';
                    html += '<div class="img-url">' + escapeHtml(img.url) + '</div>';
                    html += '<div class="img-domain">' + escapeHtml(img.domain) + '</div>';
                    if (img.localUrl) {
                        html += '<div style="color:#00a32a;font-size:11px;margin-top:3px;">→ ' + escapeHtml(img.localUrl) + '</div>';
                    }
                    html += '</div>';
                    html += '<div class="img-status">' + statusText + '</div>';
                    html += retryBtn;
                    html += '</div>';
                });
                
                $('#img-list').html(html);
                
                // 绑定复选框事件
                $('.img-checkbox').off('change').on('change', function() {
                    var index = $(this).data('index');
                    images[index].status = $(this).prop('checked') ? 'pending' : 'skipped';
                    updateStats();
                });
                
                // 绑定重试按钮
                $('.retry-btn').off('click').on('click', function() {
                    var index = $(this).data('index');
                    images[index].status = 'pending';
                    images[index].localUrl = '';
                    renderImageList();
                    updateStats();
                });
            }

            // 更新统计
            function updateStats() {
                var pending = images.filter(function(img) { return img.status === 'pending'; }).length;
                var collecting = images.filter(function(img) { return img.status === 'collecting'; }).length;
                var success = images.filter(function(img) { return img.status === 'success'; }).length;
                var failed = images.filter(function(img) { return img.status === 'failed'; }).length;
                
                $('#stat-pending').text(pending);
                $('#stat-collecting').text(collecting);
                $('#stat-success').text(success);
                $('#stat-failed').text(failed);
                
                // 显示重试按钮
                if (failed > 0 && !isCollecting) {
                    $('#img-collector-retry').show();
                } else {
                    $('#img-collector-retry').hide();
                }
            }

            // 全选/取消全选
            $('#select-all-images').on('change', function() {
                var checked = $(this).prop('checked');
                images.forEach(function(img) {
                    if (img.status === 'pending' || img.status === 'skipped') {
                        img.status = checked ? 'pending' : 'skipped';
                    }
                });
                renderImageList();
                updateStats();
            });

            // 替换编辑器中的所有外链图片标签为占位符
            // 返回是否成功删除
            function replaceAllExternalImagesWithPlaceholders() {
                var contentEditor = document.getElementById('content');
                if (!contentEditor) return false;

                var content = contentEditor.value;
                var newContent = content;
                var replacedCount = 0;
                
                // 按原始顺序处理每张图片
                images.forEach(function(img, index) {
                    if (img.status === 'pending') {
                        var url = img.url;
                        
                        // 首先检查URL是否存在于内容中
                        if (newContent.indexOf(url) === -1) {
                            // 尝试处理HTML实体编码的URL
                            var encodedUrl = url.replace(/&/g, '&amp;');
                            if (newContent.indexOf(encodedUrl) === -1) {
                                // 尝试提取URL的关键部分进行匹配
                                var urlParts = url.split('/');
                                var lastPart = urlParts[urlParts.length - 1];
                                if (newContent.indexOf(lastPart) === -1) {
                                    // 无法找到匹配，跳过这张图片
                                    console.log('无法找到图片URL:', url);
                                    return;
                                }
                            }
                        }
                        
                        // 使用简化的正则表达式匹配img标签
                        // 提取URL中不包含特殊字符的部分作为匹配关键字
                        var matchKey = extractMatchKey(url);
                        
                        // 构建正则表达式匹配包含该关键字的img标签
                        var escapedKey = matchKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        
                        // 匹配包含该关键字的任何img标签
                        var pattern = new RegExp('<img[^>]*' + escapedKey + '[^>]*>', 'gi');
                        
                        var match = newContent.match(pattern);
                        if (match && match.length > 0) {
                            // 找到匹配的img标签，用占位符替换整个标签
                            var placeholder = '<!--img-collector-placeholder-' + index + '-->';
                            newContent = newContent.replace(pattern, placeholder);
                            // 保存原始img标签用于失败时恢复
                            img.originalTag = match[0];
                            replacedCount++;
                        } else {
                            console.log('正则匹配失败，尝试其他方法:', url);
                            // 尝试用更宽松的匹配
                            var domainMatch = getDomainFromUrl(url);
                            if (domainMatch) {
                                var domainPattern = new RegExp('<img[^>]*' + domainMatch + '[^>]*>', 'gi');
                                var domainMatchResult = newContent.match(domainPattern);
                                if (domainMatchResult && domainMatchResult.length > 0) {
                                    var placeholder = '<!--img-collector-placeholder-' + index + '-->';
                                    newContent = newContent.replace(domainPattern, placeholder);
                                    img.originalTag = domainMatchResult[0];
                                    replacedCount++;
                                }
                            }
                        }
                    }
                });
                
                if (replacedCount > 0) {
                    contentEditor.value = newContent;
                    
                    if (typeof tinymce !== 'undefined') {
                        var editor = tinymce.get('content');
                        if (editor && !editor.isHidden()) {
                            editor.setContent(newContent);
                        }
                    }
                    return true;
                }
                
                return false;
            }

            // 提取URL中的匹配关键字（去除特殊字符）
            function extractMatchKey(url) {
                // 提取URL的最后一部分（文件名+参数）
                var path = url;
                if (url.indexOf('?') !== -1) {
                    path = url.substring(0, url.indexOf('?'));
                }
                var parts = path.split('/');
                var filename = parts[parts.length - 1];
                
                // 如果文件名太短，使用前面的部分
                if (filename.length < 10) {
                    if (parts.length > 1) {
                        filename = parts[parts.length - 2] + '/' + filename;
                    }
                }
                
                return filename;
            }

            // 从URL获取域名
            function getDomainFromUrl(url) {
                try {
                    var a = document.createElement('a');
                    a.href = url;
                    return a.hostname;
                } catch(e) {
                    return '';
                }
            }

            // 用本地URL替换占位符，生成干净的img标签
            function replacePlaceholderWithLocalUrl(index, localUrl) {
                var contentEditor = document.getElementById('content');
                if (!contentEditor) return;

                var content = contentEditor.value;
                var placeholder = '<!--img-collector-placeholder-' + index + '-->';
                
                // 获取原始alt属性
                var originalAlt = images[index].alt || '';
                
                // 确定alt属性值
                var altOption = '<?php echo esc_js($settings['dom_attr_alt_option'] ?? 'keep'); ?>';
                var customAlt = '<?php echo esc_js($settings['dom_attr_custom_alt'] ?? ''); ?>';
                var altValue = '';
                
                if (altOption === 'keep') {
                    altValue = originalAlt;
                } else if (altOption === 'custom') {
                    altValue = customAlt;
                }
                
                // 确定对齐方式
                var alignOption = '<?php echo esc_js($settings['dom_attr_align'] ?? 'none'); ?>';
                var alignClass = '';
                if (alignOption === 'left') {
                    alignClass = ' alignleft';
                } else if (alignOption === 'center') {
                    alignClass = ' aligncenter';
                } else if (alignOption === 'right') {
                    alignClass = ' alignright';
                }
                
                // 生成干净的img标签
                var cleanImgTag = '<img src="' + localUrl + '" alt="' + altValue + '"';
                if (alignClass) {
                    cleanImgTag += ' class="' + alignClass.trim() + '"';
                }
                cleanImgTag += ' />';
                
                var newContent = content.split(placeholder).join(cleanImgTag);
                
                if (newContent !== content) {
                    contentEditor.value = newContent;
                    
                    if (typeof tinymce !== 'undefined') {
                        var editor = tinymce.get('content');
                        if (editor && !editor.isHidden()) {
                            editor.setContent(newContent);
                        }
                    }
                }
            }

            // 采集失败时恢复原始img标签
            function restoreOriginalTag(index) {
                var contentEditor = document.getElementById('content');
                if (!contentEditor) return;

                var content = contentEditor.value;
                var placeholder = '<!--img-collector-placeholder-' + index + '-->';
                var originalTag = images[index].originalTag || ('<img src="' + images[index].url + '" />');
                var newContent = content.split(placeholder).join(originalTag);
                
                if (newContent !== content) {
                    contentEditor.value = newContent;
                    
                    if (typeof tinymce !== 'undefined') {
                        var editor = tinymce.get('content');
                        if (editor && !editor.isHidden()) {
                            editor.setContent(newContent);
                        }
                    }
                }
            }

            // 采集单张图片
            function collectSingleImage(index) {
                return new Promise(function(resolve) {
                    var img = images[index];
                    img.status = 'collecting';
                    renderImageList();
                    updateStats();

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'img_collector_collect_single',
                            nonce: '<?php echo wp_create_nonce('img_collector_nonce'); ?>',
                            url: img.url,
                            post_id: postId,
                            method: $('#img-collector-method').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                img.status = 'success';
                                img.localUrl = response.data.local_url;
                                // 按原始顺序替换占位符为干净的img标签
                                replacePlaceholderWithLocalUrl(index, response.data.local_url);
                            } else {
                                img.status = 'failed';
                                // 采集失败，恢复原始img标签
                                restoreOriginalTag(index);
                            }
                            renderImageList();
                            updateStats();
                            resolve();
                        },
                        error: function() {
                            img.status = 'failed';
                            // 采集失败，恢复原始img标签
                            restoreOriginalTag(index);
                            renderImageList();
                            updateStats();
                            resolve();
                        }
                    });
                });
            }

            // 开始批量采集
            async function startCollect() {
                if (isCollecting) return;

                var pendingImages = images.filter(function(img) {
                    return img.status === 'pending';
                });

                if (pendingImages.length === 0) {
                    $('#img-collector-status').html('<span style="color:#646970;"><?php _e('没有待采集的图片', 'img-collector'); ?></span>');
                    return;
                }

                isCollecting = true;
                $('#img-collector-start').prop('disabled', true).text('<?php _e('采集中...', 'img-collector'); ?>');
                $('#img-collector-status').html('<span class="img-collector-loading"></span> <?php _e('正在处理图片标签...', 'img-collector'); ?>');

                // 第一步：删除所有外链图片标签，替换为占位符
                var replaceSuccess = replaceAllExternalImagesWithPlaceholders();
                
                // 如果没有成功删除任何图片标签，提示错误并终止
                if (!replaceSuccess) {
                    isCollecting = false;
                    $('#img-collector-start').prop('disabled', false).text('<?php _e('开始采集', 'img-collector'); ?>');
                    $('#img-collector-status').html('<span style="color:#d63638;"><?php _e('错误：无法删除外链图片标签，请检查内容', 'img-collector'); ?></span>');
                    return;
                }

                $('#img-collector-status').html('<span class="img-collector-loading"></span> <?php _e('正在采集...', 'img-collector'); ?>');

                // 第二步：按原始顺序逐个采集并替换
                for (var i = 0; i < images.length; i++) {
                    if (images[i].status === 'pending') {
                        await collectSingleImage(i);
                    }
                }

                isCollecting = false;
                $('#img-collector-start').prop('disabled', false).text('<?php _e('开始采集', 'img-collector'); ?>');
                
                var successCount = images.filter(function(img) { return img.status === 'success'; }).length;
                var failedCount = images.filter(function(img) { return img.status === 'failed'; }).length;
                
                if (failedCount === 0) {
                    $('#img-collector-status').html('<span style="color:#00a32a;">✓ <?php _e('全部采集完成', 'img-collector'); ?></span>');
                } else {
                    $('#img-collector-status').html('<span style="color:#d63638;"><?php _e('完成', 'img-collector'); ?>: ' + successCount + ' <?php _e('成功', 'img-collector'); ?>, ' + failedCount + ' <?php _e('失败', 'img-collector'); ?></span>');
                }
            }

            // 绑定开始采集按钮
            $('#img-collector-start').on('click', function() {
                startCollect();
            });

            // 绑定重试失败项按钮
            $('#img-collector-retry').on('click', function() {
                images.forEach(function(img) {
                    if (img.status === 'failed') {
                        img.status = 'pending';
                        img.localUrl = '';
                    }
                });
                renderImageList();
                updateStats();
                startCollect();
            });

            // HTML转义
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
        </script>
        <?php
    }

    /**
     * 自动采集（保存文章时）
     */
    public static function auto_collect_on_save($post_id, $post, $update) {
        // 检查是否为自动保存
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // 检查权限
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // 检查文章类型
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        // 检查是否为修订版本
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // 检查设置
        $settings = get_option('img_collector_settings', array());
        $collect_mode = isset($settings['collect_mode']) ? $settings['collect_mode'] : 'manual';

        if ($collect_mode !== 'auto') {
            return;
        }

        // 加载采集器类
        require_once IMG_COLLECTOR_PATH . 'includes/class-collector.php';

        $collector = Img_Collector_Core::get_instance();
        $images = $collector->extract_external_images($post->post_content, $post_id);

        if (empty($images)) {
            return;
        }

        // 获取采集方法
        $method = isset($settings['default_collect_method']) ? $settings['default_collect_method'] : 'server';

        // 执行采集
        $urls = array();
        foreach ($images as $image) {
            $urls[] = $image['url'];
        }

        $results = $collector->collect_batch($urls, $post_id, $method);

        // 更新文章内容
        $new_content = $collector->update_content_urls($post->post_content, $results);

        if ($new_content !== $post->post_content) {
            // 移除钩子避免循环
            remove_action('save_post', array(__CLASS__, 'auto_collect_on_save'), 20, 3);

            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content,
            ));

            // 重新添加钩子
            add_action('save_post', array(__CLASS__, 'auto_collect_on_save'), 20, 3);
        }

        // 设置特色图片
        if (!empty($settings['set_featured_image']) && !empty($results)) {
            foreach ($results as $result) {
                if (!is_wp_error($result) && isset($result['success']) && $result['success']) {
                    $collector->set_featured_image($post_id, $result['attachment_id']);
                    break;
                }
            }
        }
    }
}