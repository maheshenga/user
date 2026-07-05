@extends('user.portal.layout')

@section('content')
    <h1 class="page-title">Dashboard</h1>
    <section class="panel">
        <div data-user-session='@json($currentUser)'>
            <strong>Current user:</strong>
            <span data-current-user-label>{{ $currentUser['nickname'] ?? $currentUser['email'] ?? $currentUser['mobile'] ?? 'Not logged in' }}</span>
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
        <button type="button" class="secondary" data-portal-logout>Logout</button>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>VIP</h2>
            <button type="button" data-refresh="vip" data-dashboard-protected disabled>Refresh VIP</button>
            <div class="data-box" data-dashboard-box="vip">Waiting for VIP summary.</div>
        </section>
        <section class="panel">
            <h2>Balance</h2>
            <button type="button" data-refresh="balance" data-dashboard-protected disabled>Refresh Balance</button>
            <div class="data-box" data-dashboard-box="balance">Waiting for balance summary.</div>
        </section>
        <section class="panel">
            <h2>Balance Ledger</h2>
            <button type="button" data-refresh="ledger" data-dashboard-protected disabled>Refresh Ledger</button>
            <div class="data-box" data-dashboard-box="ledger">Waiting for ledger.</div>
        </section>
        <section class="panel">
            <h2>Invite Summary</h2>
            <button type="button" data-refresh="invite" data-dashboard-protected disabled>Refresh Invite</button>
            <div class="data-box" data-dashboard-box="invite">Waiting for invite summary.</div>
        </section>
        <section class="panel">
            <h2>Invite Records</h2>
            <button type="button" data-refresh="inviteRecords" data-dashboard-protected disabled>Refresh Records</button>
            <div class="data-box" data-dashboard-box="inviteRecords">Waiting for invite records.</div>
        </section>
        <section class="panel">
            <h2>Withdrawals</h2>
            <button type="button" data-refresh="withdrawals" data-dashboard-protected disabled>Refresh Withdrawals</button>
            <div class="data-box" data-dashboard-box="withdrawals">Waiting for withdrawals.</div>
        </section>
    </div>

    <div class="grid">
        <section class="panel">
            <h2>Redeem Activation Code</h2>
            <form data-dashboard-form="activation">
                <label>
                    Code
                    <input type="text" name="code" required>
                </label>
                <button type="submit" data-dashboard-protected disabled>Redeem</button>
                <div class="status" data-form-status></div>
            </form>
        </section>
        <section class="panel">
            <h2>Request Withdrawal</h2>
            <form data-dashboard-form="withdrawal">
                <label>
                    Amount
                    <input type="number" step="0.01" min="0" name="amount" required>
                </label>
                <label>
                    Account Number
                    <input type="text" name="account[account_no]" required>
                </label>
                <button type="submit" data-dashboard-protected disabled>Request</button>
                <div class="status" data-form-status></div>
            </form>
        </section>
    </div>
@endsection
