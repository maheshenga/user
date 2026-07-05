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
                    {field: 'owner_user_id', width: 130, title: 'Owner ID'},
                    {field: 'code', minWidth: 150, title: 'Code'},
                    {field: 'type', width: 110, title: 'Type'},
                    {field: 'status', width: 110, title: 'Status', search: 'select', selectList: {
                        active: 'active',
                        disabled: 'disabled',
                        expired: 'expired'
                    }},
                    {field: 'max_uses', width: 110, title: 'Max Uses', search: false},
                    {field: 'used_count', width: 110, title: 'Used', search: false},
                    {field: 'expires_at', minWidth: 160, title: 'Expires At', search: false},
                    {field: 'create_time', minWidth: 160, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
