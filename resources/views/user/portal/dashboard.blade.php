@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">控制台</h1>
    <section class="panel">
        <div data-user-session='@json($currentUser)'>
            <strong>当前用户：</strong>
            <span data-current-user-label>{{ $currentUser['nickname'] ?? $currentUser['email'] ?? $currentUser['mobile'] ?? '未登录' }}</span>
        </div>
        <div data-dashboard-endpoints
             data-session="/user/session"
             data-vip="/user/vip"
             data-balance="/user/balance"
             data-ledger="/user/balance/ledger"
             data-withdrawals="/user/withdrawal"
             data-invite="/user/invite"
             data-invite-records="/user/invite/records"
             data-activation="/user/activation-code/redeem"
             data-withdrawal-request="/user/withdrawal/request"
             data-logout="/user/logout"></div>
        <div class="status" data-dashboard-status></div>
        <button type="button" class="secondary" data-portal-logout>退出登录</button>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>VIP</h2>
            <button type="button" data-refresh="vip" data-dashboard-protected disabled>刷新 VIP</button>
            <div class="data-box" data-dashboard-box="vip" data-dashboard-render="vip">等待 VIP 摘要。</div>
        </section>
        <section class="panel">
            <h2>余额</h2>
            <button type="button" data-refresh="balance" data-dashboard-protected disabled>刷新余额</button>
            <div class="data-box" data-dashboard-box="balance" data-dashboard-render="balance">等待余额摘要。</div>
        </section>
        <section class="panel">
            <h2>余额流水</h2>
            <button type="button" data-refresh="ledger" data-dashboard-protected disabled>刷新流水</button>
            <div class="data-box" data-dashboard-box="ledger" data-dashboard-render="ledger">等待流水记录。</div>
        </section>
        <section class="panel">
            <h2>邀请摘要</h2>
            <button type="button" data-refresh="invite" data-dashboard-protected disabled>刷新邀请</button>
            <div class="data-box" data-dashboard-box="invite" data-dashboard-render="invite">等待邀请摘要。</div>
        </section>
        <section class="panel">
            <h2>邀请记录</h2>
            <button type="button" data-refresh="inviteRecords" data-dashboard-protected disabled>刷新记录</button>
            <div class="data-box" data-dashboard-box="inviteRecords" data-dashboard-render="inviteRecords">等待邀请记录。</div>
        </section>
        <section class="panel">
            <h2>提现</h2>
            <button type="button" data-refresh="withdrawals" data-dashboard-protected disabled>刷新提现</button>
            <div class="data-box" data-dashboard-box="withdrawals" data-dashboard-render="withdrawals">等待提现记录。</div>
        </section>
    </div>

    <div class="grid">
        <section class="panel">
            <h2>兑换激活码</h2>
            <form data-dashboard-form="activation">
                <label>
                    激活码
                    <input type="text" name="code" required>
                </label>
                <button type="submit" data-dashboard-protected disabled>兑换</button>
                <div class="status" data-form-status></div>
            </form>
        </section>
        <section class="panel">
            <h2>申请提现</h2>
            <form data-dashboard-form="withdrawal">
                <label>
                    金额
                    <input type="number" step="0.01" min="0" name="amount" required>
                </label>
                <label>
                    收款账号
                    <input type="text" name="account[account_no]" required>
                </label>
                <button type="submit" data-dashboard-protected disabled>提交申请</button>
                <div class="status" data-form-status></div>
            </form>
        </section>
    </div>
@endsection
