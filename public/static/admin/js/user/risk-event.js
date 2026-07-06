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
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'category', width: 140, title: '分类'},
                    {field: 'event_type', width: 180, title: '事件'},
                    {field: 'severity', width: 120, title: '等级'},
                    {field: 'source_type', width: 180, title: '来源类型'},
                    {field: 'source_id', width: 120, title: '来源ID'},
                    {field: 'ip', width: 140, title: 'IP'},
                    {field: 'status', width: 120, title: '状态'},
                    {field: 'review_admin_id', width: 140, title: '审核管理员'},
                    {field: 'reviewed_at', minWidth: 170, title: '审核时间', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
