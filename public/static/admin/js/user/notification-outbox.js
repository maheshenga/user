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
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'type', width: 150, title: 'Type'},
                    {field: 'channel', width: 100, title: 'Channel'},
                    {field: 'recipient_mask', minWidth: 160, title: 'Recipient'},
                    {field: 'subject', minWidth: 180, title: 'Subject', search: false},
                    {field: 'status', width: 120, title: 'Status', search: 'select', selectList: {
                        pending: 'pending',
                        sent: 'sent'
                    }},
                    {field: 'attempt_count', width: 130, title: 'Attempts', search: false},
                    {field: 'last_error', minWidth: 220, title: 'Last Error', search: false},
                    {field: 'available_at', minWidth: 170, title: 'Available At', search: false},
                    {field: 'sent_at', minWidth: 170, title: 'Sent At', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false}
                ]]
            });
            ea.listen();
        }
    };
});
