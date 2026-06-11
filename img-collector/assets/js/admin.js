/**
 * 远程图片采集器 - 管理后台脚本
 *
 * @package Img_Collector
 * @author 老九
 * @version 1.7.0
 */

(function($) {
    'use strict';

    // 扫描页面初始化
    $(document).ready(function() {
        // 开始扫描
        $('#start-scan').on('click', function() {
            var btn = $(this);
            var conditions = {
                scan_type: $('#scan_type').val() || 'post',
                scan_status: $('#scan_status').val() || 'publish',
                scan_id_start: $('#scan_id_start').val() || '',
                scan_id_end: $('#scan_id_end').val() || '',
                scan_date_start: $('#scan_date_start').val() || '',
                scan_date_end: $('#scan_date_end').val() || '',
                scan_category: $('#scan_category').val() || 0,
                scan_order: $('#scan_order').val() || 'DESC'
            };

            btn.prop('disabled', true).text('扫描中...');
            $('#scan-progress').html('<span class="img-collector-loading"></span> 正在扫描...');
            $('#scan-results').hide();

            $.ajax({
                url: imgCollector.ajaxUrl,
                type: 'POST',
                data: $.extend({ action: 'img_collector_scan', nonce: imgCollector.nonce }, conditions),
                success: function(response) {
                    btn.prop('disabled', false).text('开始扫描');

                    if (response.success) {
                        $('#scan-stats').html('找到 <strong>' + (response.data.total || 0) + '</strong> 篇文章包含外链图片');
                        showScanResults(response.data.results || []);
                        $('#scan-results').show();
                        $('#scan-progress').empty();
                    } else {
                        $('#scan-progress').html('<span style="color:red;">扫描失败: ' + (response.data ? response.data.message : '未知错误') + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('开始扫描');
                    $('#scan-progress').html('<span style="color:red;">请求失败</span>');
                }
            });
        });

        // 批量采集
        $('#batch-collect').on('click', function() {
            var btn = $(this);
            var selected = [];
            $('.post-checkbox:checked').each(function() {
                selected.push($(this).val());
            });

            if (selected.length === 0) {
                alert('请选择要采集的文章');
                return;
            }

            if (!confirm('确认开始批量采集选中的 ' + selected.length + ' 篇文章?')) {
                return;
            }

            btn.prop('disabled', true).text('采集中...');
            $('#scan-progress').html('<span class="img-collector-loading"></span> 正在批量采集...');

            $.ajax({
                url: imgCollector.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'img_collector_batch_collect',
                    nonce: imgCollector.nonce,
                    post_ids: selected,
                    method: 'server'
                },
                success: function(response) {
                    btn.prop('disabled', false).text('批量采集选中项');

                    if (response.success) {
                        $('#scan-progress').html('<span style="color:green;">批量采集完成</span>');

                        // 更新状态
                        if (response.data.results) {
                            response.data.results.forEach(function(item) {
                                var $row = $('.post-checkbox[value="' + item.post_id + '"]').closest('tr');
                                if ($row.length > 0) {
                                    $row.find('td:nth-child(5)').text(item.success ? '已采集' : '失败');
                                }
                            });
                        }
                    } else {
                        $('#scan-progress').html('<span style="color:red;">批量采集失败</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('批量采集选中项');
                    $('#scan-progress').html('<span style="color:red;">请求失败</span>');
                }
            });
        });

        // 全选
        $('#select-all').on('change', function() {
            $('.post-checkbox').prop('checked', $(this).prop('checked'));
        });
    });

    // 显示扫描结果
    function showScanResults(results) {
        var html = '';
        if (results.length > 0) {
            results.forEach(function(item) {
                html += '<tr>';
                html += '<td><input type="checkbox" class="post-checkbox" value="' + item.post_id + '"></td>';
                html += '<td>' + item.post_id + '</td>';
                html += '<td>' + escHtml(item.post_title || '') + '</td>';
                html += '<td>' + (item.image_count || 0) + '</td>';
                html += '<td>' + (item.status || 'pending') + '</td>';
                html += '<td><button type="button" class="button collect-single" data-id="' + item.post_id + '">采集</button></td>';
                html += '</tr>';
            });
        } else {
            html = '<tr><td colspan="6" style="text-align:center;">未找到包含外链图片的文章</td></tr>';
        }
        $('#results-body').html(html);

        // 绑定单个采集按钮
        $('.collect-single').on('click', function() {
            var btn = $(this);
            var postId = btn.data('id');
            btn.prop('disabled', true).text('采集中...');

            $.ajax({
                url: imgCollector.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'img_collector_collect',
                    nonce: imgCollector.nonce,
                    post_id: postId,
                    method: 'server'
                },
                success: function(response) {
                    if (response.success) {
                        btn.text('已完成').prop('disabled', true);
                        btn.closest('tr').find('td:nth-child(5)').text('已采集');
                    } else {
                        btn.text('失败').prop('disabled', false);
                    }
                },
                error: function() {
                    btn.text('失败').prop('disabled', false);
                }
            });
        });
    }

    // HTML转义
    function escHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
