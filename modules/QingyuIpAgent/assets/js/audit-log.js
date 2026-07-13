define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'qingyu_ip_agent/audit-log/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: ['refresh'],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'admin_id', width: 110, title: '管理员ID'},
                    {field: 'action', minWidth: 180, title: '动作'},
                    {field: 'target_type', minWidth: 150, title: '目标类型'},
                    {field: 'target_id', width: 120, title: '目标ID'},
                    {field: 'result', width: 110, title: '结果', search: 'select', selectList: {success: '成功', failed: '失败'}},
                    {field: 'error_message', minWidth: 220, title: '错误信息', search: false},
                    {field: 'ip', minWidth: 140, title: 'IP'},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
