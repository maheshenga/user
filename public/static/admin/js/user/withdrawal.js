define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/withdrawal/index',
        approve_url: 'user/withdrawal/approve',
        reject_url: 'user/withdrawal/reject',
        payout_url: 'user/withdrawal/payout',
        payout_fail_url: 'user/withdrawal/payoutFail',
        stats_url: 'user/withdrawal/stats'
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
                    {field: 'withdrawal_no', width: 180, title: '单号'},
                    {field: 'user_id', width: 110, title: '用户 ID'},
                    {field: 'amount', width: 120, title: '金额', search: false},
                    {field: 'status', width: 120, title: '状态'},
                    {field: 'request_ip', width: 140, title: 'IP'},
                    {field: 'ledger_freeze_id', width: 140, title: '冻结流水', search: false},
                    {field: 'ledger_success_id', width: 150, title: '成功流水', search: false},
                    {field: 'reason', minWidth: 180, title: '原因', search: false},
                    {field: 'audit_admin_id', width: 140, title: '审核管理员'},
                    {field: 'approved_admin_id', width: 150, title: '批准管理员'},
                    {field: 'approved_at', minWidth: 170, title: '批准时间', search: false},
                    {field: 'payout_admin_id', width: 140, title: '打款管理员'},
                    {field: 'payout_method', width: 140, title: '打款方式'},
                    {field: 'payout_transaction_id', minWidth: 190, title: '交易流水号'},
                    {field: 'payout_attempt_count', width: 150, title: '打款尝试次数', search: false},
                    {field: 'payout_last_attempt_at', minWidth: 190, title: '最近打款尝试', search: false},
                    {field: 'paid_at', minWidth: 170, title: '打款时间', search: false},
                    {field: 'audited_at', minWidth: 170, title: '审核时间', search: false},
                    {field: 'create_time', minWidth: 170, title: '创建时间', search: false},
                    {
                        width: 300,
                        title: '操作',
                        search: false,
                        templet: function (row) {
                            var id = escapeAttr(row.id);
                            var actions = [];
                            if (row.status === 'pending') {
                                actions.push('<a class="layui-btn layui-btn-xs" data-withdrawal-approve="' + id + '">通过</a>');
                                actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" data-withdrawal-reject="' + id + '">拒绝</a>');
                            }
                            if (row.status === 'approved' || row.status === 'payout_failed') {
                                actions.push('<a class="layui-btn layui-btn-normal layui-btn-xs" data-withdrawal-payout="' + id + '">已打款</a>');
                                actions.push('<a class="layui-btn layui-btn-warm layui-btn-xs" data-withdrawal-payout-fail="' + id + '">打款失败</a>');
                                actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" data-withdrawal-reject="' + id + '">拒绝</a>');
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
                layui.layer.prompt({title: '拒绝原因', formType: 2}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.reject_url, {id: id, reason: value});
                });
            });

            $('body').on('click', '[data-withdrawal-payout]', function () {
                var id = $(this).data('withdrawal-payout');
                layui.layer.prompt({title: '打款交易流水号'}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.payout_url, {
                        id: id,
                        method: 'manual',
                        transaction_id: value,
                        proof: {operator_note: '管理员后台手动记录打款'}
                    });
                });
            });

            $('body').on('click', '[data-withdrawal-payout-fail]', function () {
                var id = $(this).data('withdrawal-payout-fail');
                layui.layer.prompt({title: '打款失败原因', formType: 2}, function (value, index) {
                    layui.layer.close(index);
                    postAction(init.payout_fail_url, {id: id, error: value});
                });
            });

            ea.listen();
        }
    };
});
