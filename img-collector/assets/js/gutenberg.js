/**
 * 远程图片采集器 - 古腾堡编辑器插件
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

(function(wp) {
    'use strict';

    // 检查wp对象是否存在
    if (typeof wp === 'undefined' || !wp.element || !wp.editPost || !wp.components) {
        console.error('Img Collector: WordPress Gutenberg API not available');
        return;
    }

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var PluginSidebar = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var Button = wp.components.Button;
    var SelectControl = wp.components.SelectControl;
    var PanelBody = wp.components.PanelBody;
    var Spinner = wp.components.Spinner;
    var Notice = wp.components.Notice;

    // 注册插件
    wp.plugins.registerPlugin('img-collector-gutenberg', {
        render: function() {
            var [method, setMethod] = useState(imgCollectorGutenberg.defaultMethod);
            var [isCollecting, setIsCollecting] = useState(false);
            var [progress, setProgress] = useState(0);
            var [message, setMessage] = useState('');
            var [results, setResults] = useState([]);
            var [showNotice, setShowNotice] = useState(false);

            // 执行采集
            function startCollect() {
                setIsCollecting(true);
                setProgress(20);
                setMessage(imgCollectorGutenberg.i18n.collecting);
                setResults([]);

                var data = new FormData();
                data.append('action', 'img_collector_collect');
                data.append('nonce', imgCollectorGutenberg.nonce);
                data.append('post_id', imgCollectorGutenberg.postId);
                data.append('method', method);

                fetch(imgCollectorGutenberg.ajaxUrl, {
                    method: 'POST',
                    body: data
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(response) {
                    setIsCollecting(false);
                    setProgress(100);

                    if (response.success) {
                        setMessage(response.data.message);
                        setResults(response.data.results || []);
                        setShowNotice(true);

                        // 刷新编辑器内容
                        if (response.data.total > 0 && response.data.updated_content) {
                            wp.data.dispatch('core/editor').editPost({
                                content: response.data.updated_content
                            });
                        }
                    } else {
                        setMessage(response.data.message || imgCollectorGutenberg.i18n.failed);
                        setShowNotice(true);
                    }
                })
                .catch(function(error) {
                    setIsCollecting(false);
                    setProgress(0);
                    setMessage('请求失败: ' + error);
                    setShowNotice(true);
                });
            }

            // 渲染侧边栏
            return el(
                PluginSidebar,
                {
                    name: 'img-collector-sidebar',
                    title: imgCollectorGutenberg.i18n.title,
                    icon: 'images-alt2'
                },
                el(
                    PanelBody,
                    {
                        title: imgCollectorGutenberg.i18n.title,
                        initialOpen: true
                    },
                    // 采集模式选择
                    el(SelectControl, {
                        label: imgCollectorGutenberg.i18n.collectMethod,
                        value: method,
                        options: [
                            { label: imgCollectorGutenberg.i18n.serverCollect, value: 'server' },
                            { label: imgCollectorGutenberg.i18n.proxyCollect, value: 'proxy' },
                            { label: imgCollectorGutenberg.i18n.browserCollect, value: 'browser' }
                        ],
                        onChange: function(value) {
                            setMethod(value);
                        }
                    }),
                    // 进度显示
                    isCollecting && el('div', { className: 'img-collector-progress' },
                        el(Spinner),
                        ' ' + message
                    ),
                    // 结果显示
                    results.length > 0 && el('div', { className: 'img-collector-results' },
                        results.map(function(item, index) {
                            return el('div', {
                                key: index,
                                className: item.success ? 'result-success' : 'result-failed'
                            },
                                item.success ? '✓ ' : '✗ ',
                                item.url || item.message
                            );
                        })
                    ),
                    // 采集按钮
                    el(Button, {
                        isPrimary: true,
                        disabled: isCollecting,
                        onClick: startCollect
                    }, isCollecting ? imgCollectorGutenberg.i18n.collecting : imgCollectorGutenberg.i18n.startCollect)
                )
            );
        }
    });

})(window.wp);