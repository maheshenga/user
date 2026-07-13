define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'qingyu_ip_agent/member/index',
        detail_url: 'qingyu_ip_agent/member/detail',
        grant_vip_url: 'qingyu_ip_agent/member/grantVip'
    };

    var statusLabels = {
        pending: '待审核',
        active: '正常',
        disabled: '已禁用',
        frozen: '已冻结'
    };

    function detailUrl(id) {
        return init.detail_url + '?id=' + encodeURIComponent(id || '');
    }

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: ['refresh'],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'mobile', minWidth: 130, title: '手机号'},
                    {field: 'email', minWidth: 180, title: '邮箱'},
                    {field: 'nickname', minWidth: 140, title: '昵称'},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: statusLabels},
                    {field: 'source_module', minWidth: 140, title: '来源模块'},
                    {field: 'vip_level', width: 110, title: 'VIP等级', searchOp: '='},
                    {field: 'vip_expires_at', minWidth: 170, title: 'VIP到期', search: false},
                    {field: 'create_time', minWidth: 170, title: '注册时间', search: false},
                    {
                        width: 140,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            return '<a class="layui-btn layui-btn-xs" data-open="' + detailUrl(d.id) + '" data-title="会员详情">详情</a>';
                        }
                    }
                ]]
            });
            ea.listen();
        },
        detail: function () {
            ea.listen();
        }
    };
});
