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
                    {field: 'mobile', minWidth: 130, title: 'Mobile'},
                    {field: 'email', minWidth: 180, title: 'Email'},
                    {field: 'nickname', minWidth: 140, title: 'Nickname'},
                    {field: 'status', width: 110, title: 'Status', search: 'select', selectList: {
                        active: 'active',
                        pending: 'pending',
                        disabled: 'disabled',
                        frozen: 'frozen'
                    }},
                    {field: 'vip_level', width: 110, title: 'VIP Level', searchOp: '='},
                    {field: 'available_balance', width: 150, title: 'Balance', search: false},
                    {field: 'last_login_at', minWidth: 170, title: 'Last Login', search: 'datetime'},
                    {
                        width: 100,
                        title: 'Action',
                        search: false,
                        templet: function (d) {
                            return '<a class="layui-btn layui-btn-xs" data-open="' + detailUrl(d.id) + '" data-title="User Detail">Detail</a>';
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
