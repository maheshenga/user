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
                    {field: 'batch_id', width: 110, title: '批次ID'},
                    {field: 'display_code_tail', width: 130, title: '尾号', search: false},
                    {field: 'status', width: 120, title: '状态', search: 'select', selectList: {
                        unused: '未使用',
                        used: '已使用',
                        disabled: '已禁用',
                        expired: '已过期',
                        void: '已作废'
                    }},
                    {field: 'max_uses', width: 110, title: '最大次数', search: false},
                    {field: 'used_count', width: 110, title: '已使用', search: false},
                    {field: 'bound_user_id', width: 130, title: '绑定用户'},
                    {field: 'expires_at', minWidth: 170, title: '过期时间', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
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
                    {field: 'activation_code_id', width: 160, title: '激活码ID'},
                    {field: 'batch_id', width: 110, title: '批次ID'},
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'vip_record_id', width: 140, title: 'VIP记录', search: false},
                    {field: 'commission_source_id', width: 170, title: '分佣来源', search: false},
                    {field: 'redeem_ip', minWidth: 140, title: 'IP'},
                    {field: 'result', width: 120, title: '结果', search: 'select', selectList: {
                        success: '成功',
                        failed: '失败'
                    }},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
