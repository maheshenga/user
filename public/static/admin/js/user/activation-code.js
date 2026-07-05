define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/activation-code/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'batch_id', width: 110, title: 'Batch ID'},
                    {field: 'display_code_tail', width: 130, title: 'Tail', search: false},
                    {field: 'status', width: 120, title: 'Status', search: 'select', selectList: {
                        unused: 'unused',
                        used: 'used',
                        disabled: 'disabled',
                        expired: 'expired',
                        void: 'void'
                    }},
                    {field: 'max_uses', width: 110, title: 'Max Uses', search: false},
                    {field: 'used_count', width: 110, title: 'Used', search: false},
                    {field: 'bound_user_id', width: 130, title: 'Bound User'},
                    {field: 'expires_at', minWidth: 170, title: 'Expires At', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        },
        redemptions: function () {
            ea.table.render({
                init: {
                    table_elem: '#currentTable',
                    table_render_id: 'currentTableRenderId',
                    index_url: 'user/activation-code/redemptions'
                },
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'activation_code_id', width: 160, title: 'Code ID'},
                    {field: 'batch_id', width: 110, title: 'Batch ID'},
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'vip_record_id', width: 140, title: 'VIP Record', search: false},
                    {field: 'commission_source_id', width: 170, title: 'Commission Source', search: false},
                    {field: 'redeem_ip', minWidth: 140, title: 'IP'},
                    {field: 'result', width: 120, title: 'Result', search: 'select', selectList: {
                        success: 'success',
                        failed: 'failed'
                    }},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
