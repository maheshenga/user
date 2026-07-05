define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/commission/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'source_type', width: 150, title: 'Source Type'},
                    {field: 'source_id', width: 120, title: 'Source ID'},
                    {field: 'buyer_user_id', width: 130, title: 'Buyer'},
                    {field: 'beneficiary_user_id', width: 150, title: 'Beneficiary'},
                    {field: 'level', width: 90, title: 'Level'},
                    {field: 'amount', width: 120, title: 'Amount', search: false},
                    {field: 'status', width: 120, title: 'Status', search: 'select', selectList: {
                        pending: 'pending',
                        settled: 'settled',
                        rejected: 'rejected',
                        frozen: 'frozen',
                        reversed: 'reversed'
                    }},
                    {field: 'audit_admin_id', width: 130, title: 'Admin'},
                    {field: 'audited_at', minWidth: 170, title: 'Audited At', search: false},
                    {field: 'settled_ledger_id', width: 150, title: 'Ledger', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
