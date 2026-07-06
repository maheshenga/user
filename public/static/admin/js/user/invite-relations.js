define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/invite/relations'
    };

    return {
        relations: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'user_id', width: 120, title: '用户ID'},
                    {field: 'parent_user_id', width: 140, title: '上级用户ID'},
                    {field: 'grandparent_user_id', width: 160, title: '上上级用户ID'},
                    {field: 'invite_code_id', width: 140, title: '邀请码ID'},
                    {field: 'level_path', minWidth: 160, title: '层级路径', search: false},
                    {field: 'bind_type', width: 120, title: '绑定类型'},
                    {field: 'status', width: 110, title: '状态'},
                    {field: 'create_time', minWidth: 160, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
