define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/security-log/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'event', minWidth: 190, title: '事件'},
                    {field: 'ip', minWidth: 140, title: 'IP'},
                    {field: 'user_agent', minWidth: 240, title: '用户代理', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: 'range'}
                ]]
            });
            ea.listen();
        }
    };
});
