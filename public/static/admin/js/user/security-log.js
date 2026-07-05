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
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'event', minWidth: 190, title: 'Event'},
                    {field: 'ip', minWidth: 140, title: 'IP'},
                    {field: 'user_agent', minWidth: 240, title: 'User Agent', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: 'range'}
                ]]
            });
            ea.listen();
        }
    };
});
