define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/risk-event/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'category', width: 140, title: 'Category'},
                    {field: 'event_type', width: 180, title: 'Event'},
                    {field: 'severity', width: 120, title: 'Severity'},
                    {field: 'source_type', width: 180, title: 'Source Type'},
                    {field: 'source_id', width: 120, title: 'Source ID'},
                    {field: 'ip', width: 140, title: 'IP'},
                    {field: 'status', width: 120, title: 'Status'},
                    {field: 'review_admin_id', width: 140, title: 'Admin'},
                    {field: 'reviewed_at', minWidth: 170, title: 'Reviewed At', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
