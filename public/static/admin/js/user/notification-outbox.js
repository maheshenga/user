define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/notification-outbox/index'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'user_id', width: 110, title: '用户ID'},
                    {field: 'type', width: 150, title: '类型'},
                    {field: 'channel', width: 100, title: '渠道'},
                    {field: 'recipient_mask', minWidth: 160, title: '接收人'},
                    {field: 'subject', minWidth: 180, title: '主题', search: false},
                    {field: 'status', width: 120, title: '状态', search: 'select', selectList: {
                        pending: '待发送',
                        sent: '已发送'
                    }},
                    {field: 'attempt_count', width: 130, title: '尝试次数', search: false},
                    {field: 'last_error', minWidth: 220, title: '最后错误', search: false},
                    {field: 'available_at', minWidth: 170, title: '可发送时间', search: false},
                    {field: 'sent_at', minWidth: 170, title: '发送时间', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
