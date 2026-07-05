# User Withdrawal Payout Ops Phase 7 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert withdrawal review from a one-step "approve means paid" flow into a production payout workflow with admin review, payout proof, retryable payout exceptions, and auditable balance settlement.

**Architecture:** Keep the existing request-time balance freeze. Split admin approval from actual payout settlement: `approve()` moves `pending` to `approved` without touching frozen funds, `markPaid()` settles frozen balance and records payout proof, `markPayoutFailed()` records a retryable exception while keeping funds frozen, and `reject()` can release funds from pending, approved, or failed states. Store payout metadata directly on `user_withdrawal_request` for the current product slice, and keep all money movement inside `BalanceLedgerService`.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent models, existing EasyAdmin controllers/views, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Current Baseline

P6 currently has:
- user withdrawal request: freezes available balance and creates `user_withdrawal_request.status = pending`;
- admin approve: immediately calls `BalanceLedgerService::settleFrozen()` and marks the request `paid`;
- admin reject: unfreezes and marks the request `rejected`;
- admin list page with read-only table columns.

P7-2 changes the lifecycle to:

```text
pending -> approved -> paid
pending -> rejected
approved -> payout_failed -> paid
approved -> payout_failed -> rejected
approved -> rejected
```

Money rules:
- request: freeze available balance, same as P6;
- approve: no ledger settlement, funds remain frozen;
- payout success: settle frozen balance, write `withdraw_success`, record proof;
- payout failure: no ledger settlement or unfreeze, funds remain frozen for retry or manual reject;
- reject: unfreeze and write `withdraw_reject` from any non-terminal review/payout state.

---

## Scope

Included:
- Migration adding payout metadata fields to `user_withdrawal_request`.
- Model casts for payout metadata.
- `WithdrawalService` state-machine changes.
- Admin endpoints for approval, payout success, payout failure, and rejection.
- Admin table columns and JS actions for the new states.
- Focused tests, full-suite verification, and review checkpoint commit.

Excluded:
- Real bank, Alipay, WeChat, or third-party payout provider API calls.
- Async payout workers.
- Exporting payout reports.
- User-facing frontend pages beyond existing JSON endpoints.
- Automatic risk scoring changes.

---

## File Structure

- Create `database/migrations/2026_07_05_000008_add_payout_ops_to_user_withdrawal_request_phase_7.php`
- Modify `app/Models/UserWithdrawalRequest.php`
- Modify `app/User/WithdrawalService.php`
- Modify `app/Http/Controllers/admin/user/WithdrawalController.php`
- Modify `public/static/admin/js/user/withdrawal.js`
- Modify `tests/Feature/User/UserRiskOpsTest.php`
- Modify `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

---

## Task 1: Payout Metadata Persistence

**Files:**
- Create: `database/migrations/2026_07_05_000008_add_payout_ops_to_user_withdrawal_request_phase_7.php`
- Modify: `app/Models/UserWithdrawalRequest.php`
- Test: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Add failing persistence test**

Append this method to `tests/Feature/User/UserRiskOpsTest.php`:

```php
public function test_withdrawal_payout_ops_fields_exist_and_cast_json(): void
{
    $this->assertTrue(Schema::hasColumns('user_withdrawal_request', [
        'approved_admin_id',
        'approved_at',
        'payout_admin_id',
        'payout_method',
        'payout_transaction_id',
        'payout_proof_json',
        'payout_error',
        'payout_attempt_count',
        'payout_last_attempt_at',
        'paid_at',
    ]));

    $withdrawal = UserWithdrawalRequest::query()->create([
        'withdrawal_no' => 'WD202607050002',
        'user_id' => 1,
        'amount' => '18.50',
        'status' => 'approved',
        'request_ip' => '127.0.0.1',
        'account_snapshot_json' => ['account_no' => 'masked-002'],
        'payout_proof_json' => ['receipt_url' => 'https://example.test/receipt/1'],
        'payout_attempt_count' => 1,
        'create_time' => time(),
        'update_time' => time(),
    ]);

    $this->assertSame(['receipt_url' => 'https://example.test/receipt/1'], $withdrawal->refresh()->payout_proof_json);
    $this->assertSame(1, (int) $withdrawal->payout_attempt_count);
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter payout_ops_fields
```

Expected: FAIL because the payout fields do not exist.

- [ ] **Step 3: Implement migration**

Create `database/migrations/2026_07_05_000008_add_payout_ops_to_user_withdrawal_request_phase_7.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_withdrawal_request', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_admin_id')->nullable()->after('audit_admin_id')->index();
            $table->timestamp('approved_at')->nullable()->after('approved_admin_id');
            $table->unsignedBigInteger('payout_admin_id')->nullable()->after('approved_at')->index();
            $table->string('payout_method', 40)->default('')->after('payout_admin_id')->index();
            $table->string('payout_transaction_id', 120)->default('')->after('payout_method')->index();
            $table->json('payout_proof_json')->nullable()->after('payout_transaction_id');
            $table->string('payout_error', 1000)->default('')->after('payout_proof_json');
            $table->unsignedInteger('payout_attempt_count')->default(0)->after('payout_error');
            $table->timestamp('payout_last_attempt_at')->nullable()->after('payout_attempt_count');
            $table->timestamp('paid_at')->nullable()->after('payout_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_withdrawal_request', function (Blueprint $table): void {
            $table->dropColumn([
                'approved_admin_id',
                'approved_at',
                'payout_admin_id',
                'payout_method',
                'payout_transaction_id',
                'payout_proof_json',
                'payout_error',
                'payout_attempt_count',
                'payout_last_attempt_at',
                'paid_at',
            ]);
        });
    }
};
```

- [ ] **Step 4: Implement model casts**

Modify `app/Models/UserWithdrawalRequest.php` casts:

```php
protected $casts = [
    'amount' => 'decimal:2',
    'account_snapshot_json' => 'array',
    'payout_proof_json' => 'array',
    'audited_at' => 'datetime',
    'approved_at' => 'datetime',
    'payout_last_attempt_at' => 'datetime',
    'paid_at' => 'datetime',
    'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
];
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter payout_ops_fields
git add database/migrations/2026_07_05_000008_add_payout_ops_to_user_withdrawal_request_phase_7.php app/Models/UserWithdrawalRequest.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: add withdrawal payout metadata"
```

---

## Task 2: Withdrawal Service Payout State Machine

**Files:**
- Modify: `app/User/WithdrawalService.php`
- Test: `tests/Feature/User/UserRiskOpsTest.php`

- [ ] **Step 1: Add failing approval-does-not-pay test**

In `tests/Feature/User/UserRiskOpsTest.php`, replace the old approve part of `test_withdrawal_service_admin_approve_and_reject_review_pending_requests` with explicit approved-state assertions:

```php
$approved = $service->approve($approve['id'], 7);
$this->assertSame('approved', $approved['status']);
$this->assertNull($approved['ledger_success_id']);
$this->assertSame(7, $approved['approved_admin_id']);
$this->assertDatabaseHas('user_account', [
    'id' => $approvedUser->id,
    'available_balance' => '38.00',
    'frozen_balance' => '12.00',
]);
```

- [ ] **Step 2: Add failing payout-success test**

Append:

```php
public function test_withdrawal_service_mark_paid_settles_frozen_balance_and_records_proof(): void
{
    $user = $this->createAccount('withdraw-paid@example.com', '50.00');
    $service = app(WithdrawalService::class);
    $request = $service->request($user->id, '12.00', ['account_no' => 'paid'], '127.0.0.6');
    $service->approve($request['id'], 7);

    $paid = $service->markPaid($request['id'], [
        'method' => 'manual_bank',
        'transaction_id' => 'BANK-20260705-001',
        'proof' => ['receipt_no' => 'R001', 'operator_note' => 'Bank portal confirmed'],
    ], 8);

    $this->assertSame('paid', $paid['status']);
    $this->assertNotNull($paid['ledger_success_id']);
    $this->assertSame(8, $paid['payout_admin_id']);
    $this->assertSame('manual_bank', $paid['payout_method']);
    $this->assertSame('BANK-20260705-001', $paid['payout_transaction_id']);
    $this->assertSame(['receipt_no' => 'R001', 'operator_note' => 'Bank portal confirmed'], $paid['payout_proof_json']);
    $this->assertSame(1, $paid['payout_attempt_count']);
    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'available_balance' => '38.00',
        'frozen_balance' => '0.00',
    ]);
    $this->assertDatabaseHas('user_balance_ledger', [
        'id' => $paid['ledger_success_id'],
        'user_id' => $user->id,
        'direction' => 'settle_frozen',
        'amount' => '12.00',
        'type' => 'withdraw_success',
        'source_type' => 'user_withdrawal_request',
    ]);
}
```

- [ ] **Step 3: Add failing payout-failure and reject-after-failure test**

Append:

```php
public function test_withdrawal_service_records_payout_failure_and_allows_reject_to_unfreeze(): void
{
    $user = $this->createAccount('withdraw-failed@example.com', '40.00');
    $service = app(WithdrawalService::class);
    $request = $service->request($user->id, '15.00', ['account_no' => 'failed'], '127.0.0.6');
    $service->approve($request['id'], 7);

    $failed = $service->markPayoutFailed($request['id'], 'Bank account name mismatch', 8);

    $this->assertSame('payout_failed', $failed['status']);
    $this->assertSame('Bank account name mismatch', $failed['payout_error']);
    $this->assertSame(1, $failed['payout_attempt_count']);
    $this->assertNull($failed['ledger_success_id']);
    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'available_balance' => '25.00',
        'frozen_balance' => '15.00',
    ]);

    $rejected = $service->reject($request['id'], 'Manual payout exception reject', 9);

    $this->assertSame('rejected', $rejected['status']);
    $this->assertSame('Manual payout exception reject', $rejected['reason']);
    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'available_balance' => '40.00',
        'frozen_balance' => '0.00',
    ]);
}
```

- [ ] **Step 4: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "admin_approve|mark_paid|payout_failure"
```

Expected: FAIL because `approve()` still marks paid and `markPaid()` / `markPayoutFailed()` do not exist.

- [ ] **Step 5: Implement service state machine**

Modify `app/User/WithdrawalService.php`:

```php
public function approve(int $withdrawalId, int $adminId): array
{
    if ($adminId <= 0) {
        throw new InvalidArgumentException('Admin id is required.');
    }

    return DB::transaction(function () use ($withdrawalId, $adminId): array {
        $withdrawal = $this->lockedWithdrawal($withdrawalId);
        if ($withdrawal->status !== 'pending') {
            throw new InvalidArgumentException('Only pending withdrawal can be approved.');
        }

        $withdrawal->forceFill([
            'status' => 'approved',
            'audit_admin_id' => $adminId,
            'audited_at' => now(),
            'approved_admin_id' => $adminId,
            'approved_at' => now(),
            'update_time' => time(),
        ])->save();

        return $this->publicWithdrawal($withdrawal->refresh());
    });
}

public function markPaid(int $withdrawalId, array $payload, int $adminId): array
{
    if ($adminId <= 0) {
        throw new InvalidArgumentException('Admin id is required.');
    }

    $method = trim((string) ($payload['method'] ?? ''));
    $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
    $proof = $payload['proof'] ?? [];
    if (! is_array($proof)) {
        $proof = [];
    }

    if ($method === '') {
        throw new InvalidArgumentException('Payout method is required.');
    }

    if ($transactionId === '') {
        throw new InvalidArgumentException('Payout transaction id is required.');
    }

    return DB::transaction(function () use ($withdrawalId, $method, $transactionId, $proof, $adminId): array {
        $withdrawal = $this->lockedWithdrawal($withdrawalId);
        if (! in_array($withdrawal->status, ['approved', 'payout_failed'], true)) {
            throw new InvalidArgumentException('Only approved or failed payout withdrawal can be marked paid.');
        }

        if ($withdrawal->ledger_success_id !== null) {
            throw new InvalidArgumentException('Withdrawal payout has already been settled.');
        }

        $ledger = $this->balanceLedger->settleFrozen(
            (int) $withdrawal->user_id,
            $withdrawal->amount,
            'withdraw_success',
            'user_withdrawal_request',
            (int) $withdrawal->id,
            'Withdrawal payout paid',
            $adminId
        );

        $withdrawal->forceFill([
            'status' => 'paid',
            'ledger_success_id' => $ledger['id'],
            'payout_admin_id' => $adminId,
            'payout_method' => $method,
            'payout_transaction_id' => $transactionId,
            'payout_proof_json' => $proof,
            'payout_error' => '',
            'payout_attempt_count' => ((int) $withdrawal->payout_attempt_count) + 1,
            'payout_last_attempt_at' => now(),
            'paid_at' => now(),
            'update_time' => time(),
        ])->save();

        return $this->publicWithdrawal($withdrawal->refresh());
    });
}

public function markPayoutFailed(int $withdrawalId, string $error, int $adminId): array
{
    if ($adminId <= 0) {
        throw new InvalidArgumentException('Admin id is required.');
    }

    $error = trim($error);
    if ($error === '') {
        throw new InvalidArgumentException('Payout error is required.');
    }

    return DB::transaction(function () use ($withdrawalId, $error, $adminId): array {
        $withdrawal = $this->lockedWithdrawal($withdrawalId);
        if (! in_array($withdrawal->status, ['approved', 'payout_failed'], true)) {
            throw new InvalidArgumentException('Only approved or failed payout withdrawal can be marked failed.');
        }

        if ($withdrawal->ledger_success_id !== null) {
            throw new InvalidArgumentException('Paid withdrawal cannot be marked failed.');
        }

        $withdrawal->forceFill([
            'status' => 'payout_failed',
            'payout_admin_id' => $adminId,
            'payout_error' => substr($error, 0, 1000),
            'payout_attempt_count' => ((int) $withdrawal->payout_attempt_count) + 1,
            'payout_last_attempt_at' => now(),
            'update_time' => time(),
        ])->save();

        return $this->publicWithdrawal($withdrawal->refresh());
    });
}
```

Also:
- Change `reject()` status guard to allow `pending`, `approved`, and `payout_failed`.
- Extract this helper:

```php
private function lockedWithdrawal(int $withdrawalId): UserWithdrawalRequest
{
    $withdrawal = UserWithdrawalRequest::query()->lockForUpdate()->find($withdrawalId);
    if ($withdrawal === null) {
        throw new InvalidArgumentException('Withdrawal request not found.');
    }

    return $withdrawal;
}
```

- Extend `publicWithdrawal()` with:

```php
'approved_admin_id' => $withdrawal->approved_admin_id === null ? null : (int) $withdrawal->approved_admin_id,
'approved_at' => $withdrawal->approved_at,
'payout_admin_id' => $withdrawal->payout_admin_id === null ? null : (int) $withdrawal->payout_admin_id,
'payout_method' => $withdrawal->payout_method,
'payout_transaction_id' => $withdrawal->payout_transaction_id,
'payout_proof_json' => $withdrawal->payout_proof_json ?: [],
'payout_error' => $withdrawal->payout_error,
'payout_attempt_count' => (int) $withdrawal->payout_attempt_count,
'payout_last_attempt_at' => $withdrawal->payout_last_attempt_at,
'paid_at' => $withdrawal->paid_at,
```

- [ ] **Step 6: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php --filter "admin_approve|mark_paid|payout_failure"
git add app/User/WithdrawalService.php tests/Feature/User/UserRiskOpsTest.php
git commit -m "feat: add withdrawal payout state machine"
```

---

## Task 3: Admin Payout Endpoints and Table Surface

**Files:**
- Modify: `app/Http/Controllers/admin/user/WithdrawalController.php`
- Modify: `public/static/admin/js/user/withdrawal.js`
- Test: `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

- [ ] **Step 1: Add failing admin endpoint test**

Update `test_admin_withdrawal_index_approve_and_reject` in `tests/Feature/User/UserAdminRiskOpsControllerTest.php`:

```php
$approved = $this->postJson('/admin/user/withdrawal/approve', ['id' => $approve['id']]);
$approved->assertOk()
    ->assertJsonPath('code', 1)
    ->assertJsonPath('data.status', 'approved')
    ->assertJsonPath('data.audit_admin_id', 77)
    ->assertJsonPath('data.approved_admin_id', 77);

$paid = $this->postJson('/admin/user/withdrawal/payout', [
    'id' => $approve['id'],
    'method' => 'manual_bank',
    'transaction_id' => 'BANK-ADMIN-001',
    'proof' => ['receipt_no' => 'ADMIN-R001'],
]);
$paid->assertOk()
    ->assertJsonPath('code', 1)
    ->assertJsonPath('data.status', 'paid')
    ->assertJsonPath('data.payout_admin_id', 77)
    ->assertJsonPath('data.payout_method', 'manual_bank')
    ->assertJsonPath('data.payout_transaction_id', 'BANK-ADMIN-001');
```

Append a failure endpoint assertion:

```php
$failedUser = $this->createAccount('withdraw-admin-failed@example.com', '30.00');
$failedRequest = $service->request($failedUser->id, '6.00', ['account_no' => 'failed'], '127.0.0.1');
$service->approve($failedRequest['id'], 77);

$failed = $this->postJson('/admin/user/withdrawal/payoutFail', [
    'id' => $failedRequest['id'],
    'error' => 'Bank rejected receipt',
]);
$failed->assertOk()
    ->assertJsonPath('code', 1)
    ->assertJsonPath('data.status', 'payout_failed')
    ->assertJsonPath('data.payout_error', 'Bank rejected receipt')
    ->assertJsonPath('data.payout_attempt_count', 1);
```

Update final balance expectation for the approved user to still be:

```php
$this->assertDatabaseHas('user_account', [
    'id' => $approveUser->id,
    'available_balance' => '20.00',
    'frozen_balance' => '0.00',
]);
```

- [ ] **Step 2: Add failing list-column test**

After the list response in the same test:

```php
$row = $index->json('data.0');
$this->assertArrayHasKey('payout_method', $row);
$this->assertArrayHasKey('payout_transaction_id', $row);
$this->assertArrayHasKey('payout_attempt_count', $row);
$this->assertArrayHasKey('paid_at', $row);
$this->assertArrayNotHasKey('account_snapshot_json', $row);
$this->assertArrayNotHasKey('payout_proof_json', $row);
```

- [ ] **Step 3: Add failing unsafe inherited action coverage**

In `test_admin_risk_ops_controllers_block_unsafe_inherited_actions`, keep existing unsafe endpoints and add no unsafe payout shortcuts. The endpoint-level test in Step 1 proves allowed actions. No new inherited action should be exposed.

- [ ] **Step 4: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php --filter withdrawal
```

Expected: FAIL because `/payout`, `/payoutFail`, and new list columns do not exist.

- [ ] **Step 5: Implement controller endpoints**

Modify `app/Http/Controllers/admin/user/WithdrawalController.php`:

- Add list columns:

```php
'approved_admin_id',
'approved_at',
'payout_admin_id',
'payout_method',
'payout_transaction_id',
'payout_attempt_count',
'payout_last_attempt_at',
'paid_at',
```

- Add searchable columns:

```php
'approved_admin_id',
'payout_admin_id',
'payout_method',
'payout_transaction_id',
'paid_at',
```

- Add methods:

```php
public function payout(): JsonResponse
{
    try {
        $proof = request()->input('proof', []);
        if (! is_array($proof)) {
            $proof = [];
        }

        return $this->success('Withdrawal payout recorded.', app(WithdrawalService::class)->markPaid(
            (int) request()->input('id', 0),
            [
                'method' => request()->input('method', ''),
                'transaction_id' => request()->input('transaction_id', ''),
                'proof' => $proof,
            ],
            (int) session('admin.id', 0)
        ));
    } catch (InvalidArgumentException $exception) {
        return $this->error($exception->getMessage());
    }
}

public function payoutFail(): JsonResponse
{
    try {
        return $this->success('Withdrawal payout failure recorded.', app(WithdrawalService::class)->markPayoutFailed(
            (int) request()->input('id', 0),
            (string) request()->input('error', ''),
            (int) session('admin.id', 0)
        ));
    } catch (InvalidArgumentException $exception) {
        return $this->error($exception->getMessage());
    }
}
```

- [ ] **Step 6: Implement admin JS table actions**

Modify `public/static/admin/js/user/withdrawal.js`:

```javascript
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
        ea.request.post({url: url, data: data}, function () {
            ea.table.reload(init.table_render_id);
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
                    {field: 'status', width: 130, title: 'Status'},
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
                    {width: 280, title: 'Actions', templet: function (row) {
                        var actions = [];
                        if (row.status === 'pending') {
                            actions.push('<a class="layui-btn layui-btn-xs" lay-event="approve">Approve</a>');
                            actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="reject">Reject</a>');
                        }
                        if (row.status === 'approved' || row.status === 'payout_failed') {
                            actions.push('<a class="layui-btn layui-btn-normal layui-btn-xs" lay-event="payout">Paid</a>');
                            actions.push('<a class="layui-btn layui-btn-warm layui-btn-xs" lay-event="payoutFail">Fail</a>');
                            actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="reject">Reject</a>');
                        }
                        return actions.join(' ');
                    }}
                ]]
            });

            ea.table.onTool(init.table_elem, function (obj) {
                if (obj.event === 'approve') {
                    postAction(init.approve_url, {id: obj.data.id});
                }
                if (obj.event === 'reject') {
                    layer.prompt({title: 'Reject reason'}, function (value, index) {
                        layer.close(index);
                        postAction(init.reject_url, {id: obj.data.id, reason: value});
                    });
                }
                if (obj.event === 'payout') {
                    layer.prompt({title: 'Payout transaction id'}, function (value, index) {
                        layer.close(index);
                        postAction(init.payout_url, {
                            id: obj.data.id,
                            method: 'manual',
                            transaction_id: value,
                            proof: {operator_note: 'Manual payout recorded in admin panel'}
                        });
                    });
                }
                if (obj.event === 'payoutFail') {
                    layer.prompt({title: 'Payout failure reason'}, function (value, index) {
                        layer.close(index);
                        postAction(init.payout_fail_url, {id: obj.data.id, error: value});
                    });
                }
            });
            ea.listen();
        }
    };
});
```

- [ ] **Step 7: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php --filter withdrawal
node --check public\static\admin\js\user\withdrawal.js
git add app/Http/Controllers/admin/user/WithdrawalController.php public/static/admin/js/user/withdrawal.js tests/Feature/User/UserAdminRiskOpsControllerTest.php
git commit -m "feat: add admin withdrawal payout ops"
```

---

## Task 4: Review and Full Verification

- [ ] **Step 1: Focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserRiskOpsTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php
```

- [ ] **Step 2: Full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Models\UserWithdrawalRequest.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\WithdrawalController.php
E:\code\user\.tools\php-8.3.32\php.exe -l database\migrations\2026_07_05_000008_add_payout_ops_to_user_withdrawal_request_phase_7.php
node --check public\static\admin\js\user\withdrawal.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- withdrawal request still freezes available balance and writes `withdraw_freeze`;
- approval no longer settles frozen balance or writes `withdraw_success`;
- approval records admin review metadata;
- payout success requires method and transaction id;
- payout success settles frozen balance exactly once and records proof metadata;
- payout failure records bounded error, attempt count, and keeps frozen balance intact;
- reject unfreezes from pending, approved, and payout_failed states;
- terminal paid withdrawals cannot be failed or rejected again;
- admin list does not expose `account_snapshot_json` or `payout_proof_json`;
- admin endpoints use the session admin id;
- no real third-party payout delivery is claimed.

- [ ] **Step 5: Review commit**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review withdrawal payout ops phase 7"
```

---

## Plan Self-Review

- Spec coverage: This plan closes the P7 withdrawal payout gap by separating review from payout, adding payout proof, retryable failure recording, balance-safe settlement, admin endpoints, and verification.
- Placeholder scan: No TBD/TODO placeholders remain; each behavior has a test, expected RED state, implementation target, and verification command.
- Type consistency: `approved_admin_id`, `approved_at`, `payout_admin_id`, `payout_method`, `payout_transaction_id`, `payout_proof_json`, `payout_error`, `payout_attempt_count`, `payout_last_attempt_at`, and `paid_at` are used consistently across migration, model, service, controller, JS, and tests.
- Scope guard: Real payout providers, async jobs, export reports, and automatic risk scoring remain intentionally outside this phase.
