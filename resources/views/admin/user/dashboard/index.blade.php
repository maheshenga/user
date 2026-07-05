@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>User Operations</legend>
            <div class="layui-row layui-col-space15" id="userOpsMetrics">
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Total Users</div>
                        <div class="layui-card-body" data-metric="total_users">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Today Registrations</div>
                        <div class="layui-card-body" data-metric="today_registrations">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Active VIP Users</div>
                        <div class="layui-card-body" data-metric="active_vip_users">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Pending Withdrawals</div>
                        <div class="layui-card-body" data-metric="pending_withdrawals">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Pending Payouts</div>
                        <div class="layui-card-body" data-metric="pending_payouts">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Pending Notifications</div>
                        <div class="layui-card-body" data-metric="pending_notifications">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Retryable Notifications</div>
                        <div class="layui-card-body" data-metric="retryable_notifications">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Risk Events</div>
                        <div class="layui-card-body" data-metric="risk_events">0</div>
                    </div>
                </div>
                <div class="layui-col-md3">
                    <div class="layui-card">
                        <div class="layui-card-header">Today Commission</div>
                        <div class="layui-card-body" data-metric="today_commission_amount">0.00</div>
                    </div>
                </div>
            </div>
        </fieldset>
        <table class="layui-table">
            <thead>
            <tr>
                <th>Area</th>
                <th>Entry</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>User Accounts</td>
                <td><a data-open-tab="user/account/index">Open</a></td>
            </tr>
            <tr>
                <td>Withdrawals</td>
                <td><a data-open-tab="user/withdrawal/index">Open</a></td>
            </tr>
            <tr>
                <td>Notification Outbox</td>
                <td><a data-open-tab="user/notification-outbox/index">Open</a></td>
            </tr>
            <tr>
                <td>Risk Events</td>
                <td><a data-open-tab="user/risk-event/index">Open</a></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
