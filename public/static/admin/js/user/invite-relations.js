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
                    {field: 'user_id', width: 120, title: 'User ID'},
                    {field: 'parent_user_id', width: 140, title: 'Parent ID'},
                    {field: 'grandparent_user_id', width: 160, title: 'Grandparent ID'},
                    {field: 'invite_code_id', width: 140, title: 'Code ID'},
                    {field: 'level_path', minWidth: 160, title: 'Level Path', search: false},
                    {field: 'bind_type', width: 120, title: 'Bind Type'},
                    {field: 'status', width: 110, title: 'Status'},
                    {field: 'create_time', minWidth: 160, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
