define(["jquery", "easy-admin"], function ($, ea) {
    var batchInit = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'qingyu_ip_agent/activation-code/index',
        create_batch_url: 'qingyu_ip_agent/activation-code/createBatch',
        generate_codes_url: 'qingyu_ip_agent/activation-code/generateCodes'
    };

    var redemptionInit = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'qingyu_ip_agent/activation-code/redemptions'
    };

    var batchStatusLabels = {
        active: '启用',
        disabled: '禁用',
        expired: '已过期'
    };

    var resultLabels = {
        success: '成功',
        failed: '失败'
    };

    return {
        index: function () {
            ea.table.render({
                init: batchInit,
                toolbar: ['refresh', [{
                    text: '新增批次',
                    title: '新增激活码批次',
                    url: batchInit.create_batch_url,
                    method: 'open',
                    class: 'layui-btn layui-btn-normal layui-btn-sm',
                    icon: 'fa fa-plus'
                }, {
                    text: '生成激活码',
                    title: '生成激活码',
                    url: batchInit.generate_codes_url,
                    method: 'open',
                    class: 'layui-btn layui-btn-sm layui-bg-blue',
                    icon: 'fa fa-ticket'
                }]],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'name', minWidth: 180, title: '批次名称'},
                    {field: 'vip_plan_id', width: 120, title: 'VIP套餐ID', searchOp: '='},
                    {field: 'total_count', width: 120, title: '总数量', search: false},
                    {field: 'generated_count', width: 120, title: '已生成', search: false},
                    {field: 'used_count', width: 120, title: '已使用', search: false},
                    {field: 'duration_days', width: 120, title: '有效天数', search: false},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: batchStatusLabels},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false},
                    {
                        width: 120,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            return '<a class="layui-btn layui-btn-xs layui-bg-blue" data-open="' + batchInit.generate_codes_url + '?batch_id=' + encodeURIComponent(d.id || '') + '" data-title="生成激活码">生成</a>';
                        }
                    }
                ]]
            });
            ea.listen();
        },
        createBatch: function () {
            ea.listen();
        },
        generateCodes: function () {
            ea.listen(null, function (res) {
                var codes = (res.data && res.data.codes) ? res.data.codes : [];
                $('#generatedCodes').val(codes.join('\n'));
                ea.msg.success(res.msg || '生成成功');
                return false;
            });
        },
        redemptions: function () {
            ea.table.render({
                init: redemptionInit,
                toolbar: ['refresh'],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'activation_code_id', width: 150, title: '激活码ID'},
                    {field: 'batch_id', width: 110, title: '批次ID'},
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'vip_record_id', width: 140, title: 'VIP记录', search: false},
                    {field: 'commission_source_id', width: 160, title: '分销来源', search: false},
                    {field: 'redeem_ip', minWidth: 140, title: '兑换IP'},
                    {field: 'result', width: 110, title: '结果', search: 'select', selectList: resultLabels},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
