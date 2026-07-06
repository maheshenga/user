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
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'direction', width: 120, title: '方向', search: 'select', selectList: {
                        in: '入账',
                        out: '出账',
                        freeze: '冻结',
                        unfreeze: '解冻'
                    }},
                    {field: 'amount', width: 120, title: '金额', search: false},
                    {field: 'balance_before', width: 150, title: '变动前余额', search: false},
                    {field: 'balance_after', width: 150, title: '变动后余额', search: false},
                    {field: 'frozen_before', width: 150, title: '冻结前余额', search: false},
                    {field: 'frozen_after', width: 150, title: '冻结后余额', search: false},
                    {field: 'type', width: 170, title: '类型'},
                    {field: 'source_type', width: 170, title: '来源类型'},
                    {field: 'source_id', width: 120, title: '来源ID'},
                    {field: 'remark', minWidth: 180, title: '备注', search: false},
                    {field: 'admin_id', width: 110, title: '管理员'},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
