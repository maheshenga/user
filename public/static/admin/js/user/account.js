define(["jquery", "easy-admin"], function ($, ea) {

    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/account/index',
        detail_url: 'user/account/detail'
    };

    function detailUrl(id) {
        return init.detail_url + '?id=' + encodeURIComponent(id || '');
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
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: {
                        active: 'active',
                        pending: 'pending',
                        disabled: 'disabled',
                        frozen: 'frozen'
                    }},
                    {field: 'vip_level', width: 110, title: 'VIP 等级', searchOp: '='},
                    {field: 'available_balance', width: 150, title: '余额', search: false},
                    {field: 'last_login_at', minWidth: 170, title: '最后登录', search: 'datetime'},
                    {
                        width: 100,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            return '<a class="layui-btn layui-btn-xs" data-open="' + detailUrl(d.id) + '" data-title="用户详情">详情</a>';
                        }
                    }
                ]]
            });

            ea.listen();
        },
        detail: function () {
            ea.listen();
        }
    };
});
