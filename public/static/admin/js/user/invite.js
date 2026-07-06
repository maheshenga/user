define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/invite/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'owner_user_id', width: 130, title: '所属用户ID'},
                    {field: 'code', minWidth: 150, title: '邀请码'},
                    {field: 'type', width: 110, title: '类型'},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: {
                        active: '启用',
                        disabled: '禁用',
                        expired: '已过期'
                    }},
                    {field: 'max_uses', width: 110, title: '最大次数', search: false},
                    {field: 'used_count', width: 110, title: '已使用', search: false},
                    {field: 'expires_at', minWidth: 160, title: '过期时间', search: false},
                    {field: 'create_time', minWidth: 160, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
