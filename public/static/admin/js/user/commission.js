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
                    {field: 'source_type', width: 150, title: '来源类型'},
                    {field: 'source_id', width: 120, title: '来源ID'},
                    {field: 'buyer_user_id', width: 130, title: '购买用户'},
                    {field: 'beneficiary_user_id', width: 150, title: '受益用户'},
                    {field: 'level', width: 90, title: '级别'},
                    {field: 'amount', width: 120, title: '金额', search: false},
                    {field: 'status', width: 120, title: '状态', search: 'select', selectList: {
                        pending: '待处理',
                        settled: '已结算',
                        rejected: '已拒绝',
                        frozen: '已冻结',
                        reversed: '已冲正'
                    }},
                    {field: 'audit_admin_id', width: 130, title: '审核管理员'},
                    {field: 'audited_at', minWidth: 170, title: '审核时间', search: false},
                    {field: 'settled_ledger_id', width: 150, title: '流水ID', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
