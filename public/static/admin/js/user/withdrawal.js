define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/withdrawal/index',
        approve_url: 'user/withdrawal/approve',
        reject_url: 'user/withdrawal/reject',
        payout_url: 'user/withdrawal/payout',
        payout_fail_url: 'user/withdrawal/payoutFail'
    };

    function postAction(url, data) {
        ea.request.post({
            url: ea.url(url),
            data: data
        }, function () {
            ea.table.reload(init.table_render_id);
        });
    }

    function escapeAttr(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char];
        });
    }

    return {
        index: function () {
            ea.table.render({
                init: init,
                toolbar: [],
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'withdrawal_no', width: 180, title: 'No'},
                    {field: 'user_id', width: 110, title: 'User ID'},
                    {field: 'amount', width: 120, title: 'Amount', search: false},
                    {field: 'status', width: 120, title: 'Status'},
                    {field: 'request_ip', width: 140, title: 'IP'},
                    {field: 'ledger_freeze_id', width: 140, title: 'Freeze Ledger', search: false},
                    {field: 'ledger_success_id', width: 150, title: 'Success Ledger', search: false},
                    {field: 'reason', minWidth: 180, title: 'Reason', search: false},
                    {field: 'audit_admin_id', width: 140, title: 'Review Admin'},
                    {field: 'approved_admin_id', width: 150, title: 'Approved Admin'},
                    {field: 'approved_at', minWidth: 170, title: 'Approved At', search: false},
                    {field: 'payout_admin_id', width: 140, title: 'Payout Admin'},
                    {field: 'payout_method', width: 140, title: 'Payout Method'},
                    {field: 'payout_transaction_id', minWidth: 190, title: 'Transaction ID'},
                    {field: 'payout_attempt_count', width: 150, title: 'Payout Attempts', search: false},
                    {field: 'payout_last_attempt_at', minWidth: 190, title: 'Last Payout Attempt', search: false},
                    {field: 'paid_at', minWidth: 170, title: 'Paid At', search: false},
                    {field: 'audited_at', minWidth: 170, title: 'Audited At', search: false},
                    {field: 'create_time', minWidth: 170, title: 'Created At', search: false},
                    {
                        width: 300,
                        title: 'Actions',
                        search: false,
                        templet: function (row) {
                            var id = escapeAttr(row.id);
                            var actions = [];
                            if (row.status === 'pending') {
                                actions.push('<a class="layui-btn layui-btn-xs" data-withdrawal-approve="' + id + '">Approve</a>');
                                actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" data-withdrawal-reject="' + id + '">Reject</a>');
                            }
                            if (row.status === 'approved' || row.status === 'payout_failed') {
                                actions.push('<a class="layui-btn layui-btn-normal layui-btn-xs" data-withdrawal-payout="' + id + '">Paid</a>');
                                actions.push('<a class="layui-btn layui-btn-warm layui-btn-xs" data-withdrawal-payout-fail="' + id + '">Fail</a>');
                                actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" data-withdrawal-reject="' + id + '">Reject</a>');
                            }

                            return actions.join(' ');
                        }
                    }
                ]]
            });

            $('body').on('click', '[data-withdrawal-approve]', function () {
                postAction(init.approve_url, {id: $(this).data('withdrawal-approve')});
            });

            $('body').on('click', '[data-withdrawal-reject]', function () {
                var id = $(this).data('withdrawal-reject');
                layui.layer.prompt({title: 'Reject reason', formType: 2}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.reject_url, {id: id, reason: value});
                });
            });

            $('body').on('click', '[data-withdrawal-payout]', function () {
                var id = $(this).data('withdrawal-payout');
                layui.layer.prompt({title: 'Payout transaction id'}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.payout_url, {
                        id: id,
                        method: 'manual',
                        transaction_id: value,
                        proof: {operator_note: 'Manual payout recorded in admin panel'}
                    });
                });
            });

            $('body').on('click', '[data-withdrawal-payout-fail]', function () {
                var id = $(this).data('withdrawal-payout-fail');
                layui.layer.prompt({title: 'Payout failure reason', formType: 2}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.payout_fail_url, {id: id, error: value});
                });
            });

            ea.listen();
        }
    };
});
