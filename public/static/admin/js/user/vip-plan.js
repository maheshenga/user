define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/vip-plan/index',
        add_url: 'user/vip-plan/add',
        edit_url: 'user/vip-plan/edit'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'name', minWidth: 180, title: 'Name'},
                    {field: 'level', width: 100, title: 'Level'},
                    {field: 'duration_days', width: 130, title: 'Days', search: false},
                    {field: 'price', width: 120, title: 'Price', search: false},
                    {field: 'status', width: 120, title: 'Status', search: 'select', selectList: {
                        active: 'active',
                        disabled: 'disabled'
                    }},
                    {field: 'is_commissionable', width: 150, title: 'Commission', search: false},
                    {field: 'first_level_rate', width: 150, title: 'L1 Rate', search: false},
                    {field: 'second_level_rate', width: 150, title: 'L2 Rate', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
