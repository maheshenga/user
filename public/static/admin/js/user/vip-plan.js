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
                    {field: 'name', minWidth: 180, title: '名称'},
                    {field: 'level', width: 100, title: '等级'},
                    {field: 'duration_days', width: 130, title: '天数', search: false},
                    {field: 'price', width: 120, title: '价格', search: false},
                    {field: 'status', width: 120, title: '状态', search: 'select', selectList: {
                        active: '启用',
                        disabled: '禁用'
                    }},
                    {field: 'is_commissionable', width: 150, title: '参与分佣', search: false},
                    {field: 'first_level_rate', width: 150, title: '一级比例', search: false},
                    {field: 'second_level_rate', width: 150, title: '二级比例', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
