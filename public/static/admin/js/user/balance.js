define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/balance/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'direction', width: 120, title: 'Direction', search: 'select', selectList: {
                        in: 'in',
                        out: 'out',
                        freeze: 'freeze',
                        unfreeze: 'unfreeze'
                    }},
                    {field: 'amount', width: 120, title: 'Amount', search: false},
                    {field: 'balance_before', width: 150, title: 'Before', search: false},
                    {field: 'balance_after', width: 150, title: 'After', search: false},
                    {field: 'frozen_before', width: 150, title: 'Frozen Before', search: false},
                    {field: 'frozen_after', width: 150, title: 'Frozen After', search: false},
                    {field: 'type', width: 170, title: 'Type'},
                    {field: 'source_type', width: 170, title: 'Source Type'},
                    {field: 'source_id', width: 120, title: 'Source ID'},
                    {field: 'remark', minWidth: 180, title: 'Remark', search: false},
                    {field: 'admin_id', width: 110, title: 'Admin'},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
