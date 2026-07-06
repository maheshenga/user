@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>用户运营</legend>
            <div class="layui-row layui-col-space15" id="userOpsMetrics">
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">用户总数</div>
                        <div class="layui-card-body" data-metric="total_users">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">今日注册</div>
                        <div class="layui-card-body" data-metric="today_registrations">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">有效 VIP 用户</div>
                        <div class="layui-card-body" data-metric="active_vip_users">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">待审核提现</div>
                        <div class="layui-card-body" data-metric="pending_withdrawals">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">待打款提现</div>
                        <div class="layui-card-body" data-metric="pending_payouts">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">待发送通知</div>
                        <div class="layui-card-body" data-metric="pending_notifications">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">可重试通知</div>
                        <div class="layui-card-body" data-metric="retryable_notifications">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">风控事件</div>
                        <div class="layui-card-body" data-metric="risk_events">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">今日佣金</div>
                        <div class="layui-card-body" data-metric="today_commission_amount">0.00</div>
                    </div>
                </div>
            </div>
        </fieldset>
        <table class="layui-table">
            <thead>
            <tr>
                <th>区域</th>
                <th>入口</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>用户账号</td>
                <td><a data-open-tab="user/account/index">打开</a></td>
            </tr>
            <tr>
                <td>提现审核</td>
                <td><a data-open-tab="user/withdrawal/index">打开</a></td>
            </tr>
            <tr>
                <td>通知队列</td>
                <td><a data-open-tab="user/notification-outbox/index">打开</a></td>
            </tr>
            <tr>
                <td>风控事件</td>
                <td><a data-open-tab="user/risk-event/index">打开</a></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
