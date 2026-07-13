define(["jquery", "easy-admin"], function ($, ea) {

    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/account/index',
        detail_url: 'user/account/detail',
        modify_url: 'user/account/modify'
    };

    var statusLabels = {
        pending: '待审核',
        active: '正常',
        disabled: '已禁用',
        frozen: '已冻结'
    };

    function detailUrl(id) {
        return init.detail_url + '?id=' + encodeURIComponent(id || '');
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

    function statusEndpoint() {
        return $('[data-user-status-admin]').attr('data-status-endpoint') || ea.url(init.modify_url);
    }

    function canModifyStatus() {
        return CONFIG.IS_SUPER_ADMIN === '1'
            || CONFIG.IS_SUPER_ADMIN === 1
            || CONFIG.IS_SUPER_ADMIN === true
            || $(init.table_elem).attr('data-auth-modify') === '1';
    }

    function statusActions(row) {
        if (!canModifyStatus()) {
            return '';
        }

        var id = escapeAttr(row.id);
        var currentStatus = row.status || 'pending';
        var actions = [];

        $.each(statusLabels, function (status, label) {
            if (status === currentStatus) {
                return;
            }

            actions.push(
                '<a class="layui-btn layui-btn-primary layui-btn-xs" data-account-status="' + status + '" data-account-id="' + id + '">' + label + '</a>'
            );
        });

        return actions.join(' ');
    }

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: ['refresh'],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', searchOp: '='},
                    {field: 'mobile', minWidth: 130, title: '手机号'},
                    {field: 'email', minWidth: 180, title: '邮箱'},
                    {field: 'nickname', minWidth: 140, title: '昵称'},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: statusLabels, templet: '#userStatusTpl'},
                    {field: 'source_module', minWidth: 130, title: '所属模块', searchOp: '='},
                    {field: 'vip_level', width: 110, title: 'VIP 等级', searchOp: '='},
                    {field: 'available_balance', width: 150, title: '余额', search: false},
                    {field: 'last_login_at', minWidth: 170, title: '最后登录', search: 'datetime'},
                    {
                        width: 360,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            var actions = [
                                '<a class="layui-btn layui-btn-xs" data-open="' + detailUrl(d.id) + '" data-title="用户详情">详情</a>',
                                statusActions(d)
                            ];

                            return actions.join(' ');
                        }
                    }
                ]]
            });

            $('body').on('click', '[data-account-status]', function () {
                var status = $(this).data('account-status');
                var id = $(this).data('account-id');
                var label = statusLabels[status] || status;

                ea.msg.confirm('确认将账号状态改为「' + label + '」？', function () {
                    ea.request.post({
                        url: statusEndpoint(),
                        data: {
                            id: id,
                            field: 'status',
                            value: status
                        }
                    }, function () {
                        ea.table.reload(init.table_render_id);
                    });
                });
            });

            ea.listen();
        },
        detail: function () {
            ea.listen();
        }
    };
});
