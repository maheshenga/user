define(["jquery", "easy-admin"], function ($, ea) {

    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'system/module/index',
        detail_url: 'system/module/detail',
        logs_url: 'system/module/logs',
        upload_url: 'system/module/upload',
        discover_url: 'system/module/discover',
        install_url: 'system/module/install',
        approve_url: 'system/module/approve',
        reject_url: 'system/module/reject',
        enable_url: 'system/module/enable',
        disable_url: 'system/module/disable',
        uninstall_url: 'system/module/uninstall',
        upgradeLocal_url: 'system/module/upgradeLocal',
        upgradeZip_url: 'system/module/upgradeZip',
        rollback_url: 'system/module/rollback'
    };

    function moduleUrl(url, name) {
        return url + '?name=' + encodeURIComponent(name || '');
    }

    function escapeAttr(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char];
        });
    }

    function requestAction(url, name, title) {
        ea.msg.confirm(title || '确认执行该操作？', function () {
            ea.request.post({
                url: ea.url(moduleUrl(url, name))
            }, function () {
                ea.table.reload(init.table_render_id);
            });
        });
    }

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: ['refresh', [{
                    text: '发现模块',
                    url: init.discover_url,
                    method: 'request',
                    auth: 'discover',
                    class: 'layui-btn layui-btn-sm layui-btn-success',
                    icon: 'fa fa-search',
                    extend: 'data-table="' + init.table_render_id + '"'
                }, {
                    text: '上传ZIP',
                    url: init.upload_url,
                    method: 'open',
                    auth: 'upload',
                    class: 'layui-btn layui-btn-sm layui-btn-normal',
                    icon: 'fa fa-upload',
                    extend: 'data-width="520px" data-height="320px"'
                }]],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'name', minWidth: 120, title: '名称'},
                    {field: 'title', minWidth: 160, title: '标题'},
                    {field: 'version', width: 110, title: '版本', search: false},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: {
                        discovered: 'discovered',
                        pending_review: 'pending_review',
                        approved: 'approved',
                        rejected: 'rejected',
                        installed: 'installed',
                        enabled: 'enabled',
                        disabled: 'disabled',
                        uninstalled: 'uninstalled'
                    }},
                    {field: 'admin_prefix', minWidth: 120, title: '后台前缀'},
                    {field: 'vendor', minWidth: 120, title: '厂商'},
                    {field: 'update_time', minWidth: 160, title: '更新时间', search: false},
                    {
                        width: 520,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            var rawName = d.name || '';
                            var name = escapeAttr(rawName);
                            var buttons = [
                                '<a class="layui-btn layui-btn-xs" data-open="' + moduleUrl(init.detail_url, rawName) + '" data-title="模块详情">详情</a>',
                                '<a class="layui-btn layui-btn-normal layui-btn-xs" data-open="' + moduleUrl(init.logs_url, rawName) + '" data-title="模块日志">日志</a>',
                                '<a class="layui-btn layui-btn-warm layui-btn-xs" data-module-action="' + init.install_url + '" data-module-name="' + name + '">安装</a>',
                                '<a class="layui-btn layui-btn-xs" data-module-action="' + init.enable_url + '" data-module-name="' + name + '">启用</a>',
                                '<a class="layui-btn layui-btn-primary layui-btn-xs" data-module-action="' + init.disable_url + '" data-module-name="' + name + '">禁用</a>',
                                '<a class="layui-btn layui-btn-normal layui-btn-xs" data-module-action="' + init.upgradeLocal_url + '" data-module-name="' + name + '">本地升级</a>',
                                '<a class="layui-btn layui-btn-danger layui-btn-xs" data-module-action="' + init.rollback_url + '" data-module-name="' + name + '">回滚</a>',
                                '<a class="layui-btn layui-btn-danger layui-btn-xs" data-module-action="' + init.uninstall_url + '" data-module-name="' + name + '">卸载</a>'
                            ];
                            if (d.status === 'pending_review' || d.status === 'rejected') {
                                buttons.push('<a class="layui-btn layui-btn-xs" data-module-action="' + init.approve_url + '" data-module-name="' + name + '">Approve</a>');
                            }
                            if (d.status === 'pending_review' || d.status === 'approved') {
                                buttons.push('<a class="layui-btn layui-btn-danger layui-btn-xs" data-module-reject="' + init.reject_url + '" data-module-name="' + name + '">Reject</a>');
                            }
                            return buttons.join(' ');
                        }
                    }
                ]]
            });

            $('body').on('click', '[data-module-action]', function () {
                requestAction($(this).data('module-action'), $(this).data('module-name'));
            });

            $('body').on('click', '[data-module-reject]', function () {
                var url = $(this).data('module-reject');
                var name = $(this).data('module-name');
                layui.layer.prompt({title: 'Reject reason', formType: 2}, function (value, index) {
                    layui.layer.close(index);
                    ea.request.post({
                        url: ea.url(moduleUrl(url, name)),
                        data: {reason: value}
                    }, function () {
                        ea.table.reload(init.table_render_id);
                    });
                });
            });

            ea.listen();
        },
        logs: function () {
            var module = $('#currentTable').data('module') || '';
            ea.table.render({
                init: $.extend({}, init, {
                    index_url: init.logs_url + '?module=' + encodeURIComponent(module)
                }),
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'module', minWidth: 120, title: '模块'},
                    {field: 'action', width: 110, title: '动作'},
                    {field: 'old_state', width: 110, title: '原状态', search: false},
                    {field: 'new_state', width: 110, title: '新状态', search: false},
                    {field: 'result', width: 100, title: '结果', search: 'select', selectList: {success: 'success', failed: 'failed'}},
                    {field: 'error_message', minWidth: 220, title: '错误', search: false},
                    {field: 'finished_at', minWidth: 160, title: '完成时间', search: false}
                ]]
            });
            ea.listen();
        },
        upload: function () {
            layui.upload.render({
                elem: '#moduleZip',
                url: ea.url(init.upgradeZip_url),
                headers: {'X-CSRF-TOKEN': CONFIG.CSRF_TOKEN},
                accept: 'file',
                exts: 'zip',
                auto: false,
                bindAction: '#moduleZipSubmit',
                data: {
                    name: function () {
                        return $('input[name="name"]').val();
                    }
                },
                choose: function (obj) {
                    obj.preview(function (index, file) {
                        $('#moduleZipName').text(file.name);
                    });
                },
                done: function (res) {
                    if (res.code) {
                        ea.msg.success(res.msg || '操作成功', function () {
                            parent.location.reload();
                        });
                        return;
                    }
                    ea.msg.error(res.msg || '操作失败');
                }
            });
            ea.listen();
        },
        detail: function () {
            layui.code();
            ea.listen();
        }
    };
});
