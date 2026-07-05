define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/withdrawal/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'withdrawal_no', width: 180, title: 'No'},
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'amount', width: 120, title: 'Amount', search: false},
                    {field: 'status', width: 120, title: 'Status'},
                    {field: 'request_ip', width: 140, title: 'IP'},
                    {field: 'ledger_freeze_id', width: 140, title: 'Freeze Ledger', search: false},
                    {field: 'ledger_success_id', width: 150, title: 'Success Ledger', search: false},
                    {field: 'reason', minWidth: 180, title: 'Reason', search: false},
                    {field: 'audit_admin_id', width: 140, title: 'Admin'},
                    {field: 'audited_at', minWidth: 170, title: 'Audited At', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
