# User Ops Maintenance Phase 7 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add production maintenance controls for notification retention, notification visibility, and withdrawal operating statistics.

**Architecture:** Keep delivery and payout behavior in their current services. Add a small `NotificationOutboxMaintenanceService` for read-only summary and bounded purge operations, expose it through artisan commands and an admin read-only controller, and add withdrawal stats to the existing admin withdrawal controller without changing money movement rules.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, EasyAdmin admin controllers/views/JS, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:
- Notification outbox summary and safe sent-row purge.
- Console command `user:notifications:purge`.
- Admin notification outbox list and stats endpoints.
- Admin withdrawal stats endpoint.
- Focused tests, full-suite verification, review checkpoint, and local merge.

Excluded:
- Real SMS provider integration.
- Queue worker scheduling.
- CSV/export reports.
- Deleting pending, retryable, failed, or payout records.
- UI redesign beyond existing EasyAdmin table surfaces.

---

## File Structure

- Create `app/User/NotificationOutboxMaintenanceService.php`
- Modify `routes/console.php`
- Create `app/Http/Controllers/admin/user/NotificationOutboxController.php`
- Create `resources/views/admin/user/notification-outbox/index.blade.php`
- Create `public/static/admin/js/user/notification-outbox.js`
- Modify `app/User/WithdrawalService.php`
- Modify `app/Http/Controllers/admin/user/WithdrawalController.php`
- Modify `public/static/admin/js/user/withdrawal.js`
- Modify `tests/Feature/User/UserPasswordResetNotificationTest.php`
- Create `tests/Feature/User/UserAdminNotificationOpsControllerTest.php`
- Modify `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

---

## Task 1: Notification Outbox Maintenance Service and Purge Command

**Files:**
- Create: `app/User/NotificationOutboxMaintenanceService.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/User/UserPasswordResetNotificationTest.php`

- [ ] **Step 1: Add failing maintenance tests**

Append to `tests/Feature/User/UserPasswordResetNotificationTest.php`:

```php
use App\User\NotificationOutboxMaintenanceService;
```

Add these methods:

```php
public function test_notification_maintenance_summary_counts_statuses_and_retryable_rows(): void
{
    $this->createOutboxRow('sent', -60, 1);
    $this->createOutboxRow('sent', -10, 1);
    $this->createOutboxRow('pending', -1, 3);
    $this->createOutboxRow('pending', 10, 4);

    $summary = app(NotificationOutboxMaintenanceService::class)->summary();

    $this->assertSame(4, $summary['total']);
    $this->assertSame(2, $summary['by_status']['sent']);
    $this->assertSame(2, $summary['by_status']['pending']);
    $this->assertSame(1, $summary['retryable_pending']);
    $this->assertSame(1, $summary['delayed_pending']);
}

public function test_notification_maintenance_purges_only_old_sent_rows_with_limit(): void
{
    $oldSent = $this->createOutboxRow('sent', -60, 1);
    $oldSentSecond = $this->createOutboxRow('sent', -45, 1);
    $recentSent = $this->createOutboxRow('sent', -5, 1);
    $oldPending = $this->createOutboxRow('pending', -60, 1);

    $result = app(NotificationOutboxMaintenanceService::class)->purgeSentOlderThan(30, 1);

    $this->assertSame(1, $result['deleted']);
    $this->assertDatabaseMissing('user_notification_outbox', ['id' => $oldSent->id]);
    $this->assertDatabaseHas('user_notification_outbox', ['id' => $oldSentSecond->id]);
    $this->assertDatabaseHas('user_notification_outbox', ['id' => $recentSent->id]);
    $this->assertDatabaseHas('user_notification_outbox', ['id' => $oldPending->id]);
}

public function test_notification_purge_command_reports_deleted_count(): void
{
    $this->createOutboxRow('sent', -60, 1);

    $this->artisan('user:notifications:purge', ['--days' => 30, '--limit' => 50])
        ->expectsOutputToContain('deleted=1')
        ->assertExitCode(0);
}

private function createOutboxRow(string $status, int $availableOffsetMinutes, int $attempts): UserNotificationOutbox
{
    $time = now()->addMinutes($availableOffsetMinutes);

    return UserNotificationOutbox::query()->create([
        'user_id' => 10,
        'type' => 'password_reset',
        'channel' => 'email',
        'recipient' => 'ops@example.com',
        'recipient_mask' => 'o***@example.com',
        'subject' => 'Ops row',
        'payload_json' => ['token' => 'ops-token', 'code' => '123456'],
        'status' => $status,
        'attempt_count' => $attempts,
        'available_at' => $time,
        'sent_at' => $status === 'sent' ? $time : null,
        'create_time' => $time->timestamp,
        'update_time' => $time->timestamp,
    ]);
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "maintenance|purge"
```

Expected: FAIL because `NotificationOutboxMaintenanceService` and `user:notifications:purge` do not exist.

- [ ] **Step 3: Implement maintenance service**

Create `app/User/NotificationOutboxMaintenanceService.php`:

```php
<?php

namespace App\User;

use App\Models\UserNotificationOutbox;

final class NotificationOutboxMaintenanceService
{
    public function summary(): array
    {
        $rows = UserNotificationOutbox::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byStatus = [];
        foreach ($rows as $status => $count) {
            $byStatus[(string) $status] = (int) $count;
        }

        $retryable = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->count();

        $delayed = UserNotificationOutbox::query()
            ->where('status', 'pending')
            ->where('available_at', '>', now())
            ->count();

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'retryable_pending' => (int) $retryable,
            'delayed_pending' => (int) $delayed,
        ];
    }

    public function purgeSentOlderThan(int $days, int $limit = 500): array
    {
        $days = max(1, $days);
        $limit = max(1, min(5000, $limit));
        $threshold = now()->subDays($days);

        $ids = UserNotificationOutbox::query()
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return ['deleted' => 0, 'days' => $days, 'limit' => $limit];
        }

        $deleted = UserNotificationOutbox::query()->whereIn('id', $ids)->delete();

        return ['deleted' => (int) $deleted, 'days' => $days, 'limit' => $limit];
    }
}
```

- [ ] **Step 4: Implement purge command**

Append to `routes/console.php`:

```php
Artisan::command('user:notifications:purge {--days=30} {--limit=500}', function (): int {
    $result = app(\App\User\NotificationOutboxMaintenanceService::class)->purgeSentOlderThan(
        (int) $this->option('days'),
        (int) $this->option('limit')
    );
    $this->info('deleted='.$result['deleted'].' days='.$result['days'].' limit='.$result['limit']);

    return Command::SUCCESS;
})->purpose('Purge old sent user notification outbox rows');
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php --filter "maintenance|purge"
git add app/User/NotificationOutboxMaintenanceService.php routes/console.php tests/Feature/User/UserPasswordResetNotificationTest.php
git commit -m "feat: add notification outbox maintenance"
```

---

## Task 2: Admin Notification Outbox Visibility

**Files:**
- Create: `app/Http/Controllers/admin/user/NotificationOutboxController.php`
- Create: `resources/views/admin/user/notification-outbox/index.blade.php`
- Create: `public/static/admin/js/user/notification-outbox.js`
- Test: `tests/Feature/User/UserAdminNotificationOpsControllerTest.php`

- [ ] **Step 1: Add failing admin visibility tests**

Create `tests/Feature/User/UserAdminNotificationOpsControllerTest.php`:

```php
<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use App\Models\UserNotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminNotificationOpsControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();

        $this->withoutMiddleware([
            CheckInstall::class,
            CheckAuth::class,
            RateLimiting::class,
            SystemLog::class,
        ]);

        DB::table('system_admin')->updateOrInsert(['id' => 77], ['status' => 1, 'auth_ids' => '']);
        $this->withSession(['admin.id' => 77, 'admin.expire_time' => true]);
    }

    public function test_admin_notification_outbox_index_returns_safe_rows(): void
    {
        $row = $this->createOutbox('pending', 3);

        $response = $this->getJson('/admin/user/notification-outbox/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.recipient_mask', 'o***@example.com');

        $payload = $response->json('data.0');
        $this->assertArrayNotHasKey('payload_json', $payload);
        $this->assertArrayNotHasKey('recipient', $payload);
    }

    public function test_admin_notification_outbox_stats_returns_summary(): void
    {
        $this->createOutbox('sent', 1, now()->subMinutes(10));
        $this->createOutbox('pending', 2, now()->subMinute());
        $this->createOutbox('pending', 2, now()->addMinutes(10));

        $response = $this->getJson('/admin/user/notification-outbox/stats');

        $response->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_status.sent', 1)
            ->assertJsonPath('data.by_status.pending', 2)
            ->assertJsonPath('data.retryable_pending', 1)
            ->assertJsonPath('data.delayed_pending', 1);
    }

    public function test_admin_notification_outbox_blocks_unsafe_inherited_actions(): void
    {
        $row = $this->createOutbox('pending', 1);

        foreach ([
            ['postJson', '/admin/user/notification-outbox/add', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/edit', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/delete', ['id' => $row->id]],
            ['postJson', '/admin/user/notification-outbox/modify', ['id' => $row->id, 'field' => 'status', 'value' => 'sent']],
            ['getJson', '/admin/user/notification-outbox/recycle', []],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertOk()->assertJsonPath('code', 0);
        }

        $this->getJson('/admin/user/notification-outbox/export')->assertForbidden();
        $this->assertDatabaseHas('user_notification_outbox', ['id' => $row->id, 'status' => 'pending']);
    }

    private function createOutbox(string $status, int $attempts, mixed $availableAt = null): UserNotificationOutbox
    {
        $time = $availableAt ?? now();

        return UserNotificationOutbox::query()->create([
            'user_id' => 10,
            'type' => 'password_reset',
            'channel' => 'email',
            'recipient' => 'ops@example.com',
            'recipient_mask' => 'o***@example.com',
            'subject' => 'Ops row',
            'payload_json' => ['token' => 'ops-token', 'code' => '123456'],
            'status' => $status,
            'attempt_count' => $attempts,
            'available_at' => $time,
            'sent_at' => $status === 'sent' ? $time : null,
            'create_time' => $time->timestamp,
            'update_time' => $time->timestamp,
        ]);
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function ($table) {
                $table->id();
                $table->string('group', 80)->default('');
                $table->string('name', 120);
                $table->text('value')->nullable();
            });
        }

        DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => '8.0.0'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin8'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'textarea'],
            ['group' => 'site', 'name' => 'iframe_open_top', 'value' => '0'],
        ]);
    }
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminNotificationOpsControllerTest.php
```

Expected: FAIL because the admin controller and routes do not exist.

- [ ] **Step 3: Implement admin controller**

Create `app/Http/Controllers/admin/user/NotificationOutboxController.php`:

```php
<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserNotificationOutbox;
use App\User\NotificationOutboxMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'User Notification Outbox')]
class NotificationOutboxController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'user_id',
        'type',
        'channel',
        'recipient_mask',
        'subject',
        'status',
        'attempt_count',
        'last_error',
        'available_at',
        'sent_at',
        'create_time',
        'update_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'user_id',
        'type',
        'channel',
        'recipient_mask',
        'status',
        'attempt_count',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserNotificationOutbox();
    }

    #[NodeAnnotation(title: 'Notification Outbox', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();
        $query = UserNotificationOutbox::query()->where($where);

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => (clone $query)
                ->select(self::LIST_COLUMNS)
                ->orderBy($order, $direction)
                ->paginate((int) $limit, ['*'], 'page', (int) $page)
                ->items(),
        ]);
    }

    public function stats(): JsonResponse
    {
        return $this->success('Notification outbox stats.', app(NotificationOutboxMaintenanceService::class)->summary());
    }

    public function add(): JsonResponse { return $this->readOnlyError(); }
    public function edit(): JsonResponse { return $this->readOnlyError(); }
    public function delete(): JsonResponse { return $this->readOnlyError(); }
    public function modify(): JsonResponse { return $this->readOnlyError(); }
    public function recycle(): JsonResponse { return $this->readOnlyError(); }

    public function export(): View|bool
    {
        abort(403, 'Notification outbox export is disabled.');
    }

    private function sanitizeTableWhere(array $where): array
    {
        return array_values(array_filter($where, static function (array $condition): bool {
            $field = $condition[0] ?? null;

            return is_string($field) && in_array($field, self::SEARCHABLE_COLUMNS, true);
        }));
    }

    private function sanitizeTableOrder(): array
    {
        if (! in_array($this->order, self::LIST_COLUMNS, true)) {
            return ['id', 'desc'];
        }

        $direction = strtolower($this->orderDirection);

        return [$this->order, in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc'];
    }

    private function readOnlyError(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => 'Notification outbox action is not allowed.',
            'data' => [],
        ]);
    }
}
```

- [ ] **Step 4: Add view and JS**

Create `resources/views/admin/user/notification-outbox/index.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide" lay-filter="currentTable"></table>
    </div>
</div>
@include('admin.layout.foot')
```

Create `public/static/admin/js/user/notification-outbox.js`:

```javascript
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
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminNotificationOpsControllerTest.php
node --check public\static\admin\js\user\notification-outbox.js
git add app/Http/Controllers/admin/user/NotificationOutboxController.php resources/views/admin/user/notification-outbox/index.blade.php public/static/admin/js/user/notification-outbox.js tests/Feature/User/UserAdminNotificationOpsControllerTest.php
git commit -m "feat: add admin notification outbox ops"
```

---

## Task 3: Withdrawal Operating Statistics

**Files:**
- Modify: `app/User/WithdrawalService.php`
- Modify: `app/Http/Controllers/admin/user/WithdrawalController.php`
- Modify: `public/static/admin/js/user/withdrawal.js`
- Test: `tests/Feature/User/UserAdminRiskOpsControllerTest.php`

- [ ] **Step 1: Add failing withdrawal stats test**

Append to `test_admin_withdrawal_index_approve_and_reject` after the payout failure assertion:

```php
$stats = $this->getJson('/admin/user/withdrawal/stats');
$stats->assertOk()
    ->assertJsonPath('code', 1)
    ->assertJsonPath('data.by_status.pending.count', 1)
    ->assertJsonPath('data.by_status.paid.count', 1)
    ->assertJsonPath('data.by_status.payout_failed.count', 1)
    ->assertJsonPath('data.by_status.payout_failed.amount', '6.00')
    ->assertJsonPath('data.pending_payout_count', 2)
    ->assertJsonPath('data.pending_payout_amount', '14.00');
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php --filter withdrawal
```

Expected: FAIL because `/admin/user/withdrawal/stats` does not exist.

- [ ] **Step 3: Implement withdrawal stats service method**

Add to `app/User/WithdrawalService.php`:

```php
public function stats(): array
{
    $rows = UserWithdrawalRequest::query()
        ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
        ->groupBy('status')
        ->get();

    $byStatus = [];
    foreach ($rows as $row) {
        $byStatus[(string) $row->status] = [
            'count' => (int) $row->total,
            'amount' => $this->money($row->amount ?? 0),
        ];
    }

    $pendingPayout = UserWithdrawalRequest::query()
        ->whereIn('status', ['approved', 'payout_failed'])
        ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
        ->first();

    return [
        'by_status' => $byStatus,
        'pending_payout_count' => (int) ($pendingPayout->total ?? 0),
        'pending_payout_amount' => $this->money($pendingPayout->amount ?? 0),
    ];
}

private function money(mixed $amount): string
{
    return number_format(round((float) $amount, 2), 2, '.', '');
}
```

Then update `positiveMoney()` and `publicWithdrawal()` to call `$this->money(...)` instead of repeating `number_format(...)`.

- [ ] **Step 4: Implement admin endpoint and JS URL**

Add to `app/Http/Controllers/admin/user/WithdrawalController.php`:

```php
public function stats(): JsonResponse
{
    return $this->success('Withdrawal stats.', app(WithdrawalService::class)->stats());
}
```

Add to `public/static/admin/js/user/withdrawal.js` init:

```javascript
stats_url: 'user/withdrawal/stats',
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php --filter withdrawal
node --check public\static\admin\js\user\withdrawal.js
git add app/User/WithdrawalService.php app/Http/Controllers/admin/user/WithdrawalController.php public/static/admin/js/user/withdrawal.js tests/Feature/User/UserAdminRiskOpsControllerTest.php
git commit -m "feat: add withdrawal ops stats"
```

---

## Task 4: Review and Full Verification

- [ ] **Step 1: Focused tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPasswordResetNotificationTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminNotificationOpsControllerTest.php
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
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\NotificationOutboxMaintenanceService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\NotificationOutboxController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\WithdrawalController.php
E:\code\user\.tools\php-8.3.32\php.exe -l routes\console.php
node --check public\static\admin\js\user\notification-outbox.js
node --check public\static\admin\js\user\withdrawal.js
git diff --check
```

- [ ] **Step 4: Review checklist**

Confirm:
- purge deletes only `sent` rows older than the retention threshold;
- purge is bounded by `limit`;
- purge never deletes pending retry rows;
- notification summary separates retryable pending from delayed pending;
- admin notification list does not expose plaintext `recipient` or encrypted `payload_json`;
- admin notification controller blocks inherited write/export actions;
- withdrawal stats count and sum by status;
- pending payout stats include `approved` and `payout_failed`, not `pending`, `paid`, or `rejected`;
- no money movement rules changed.

- [ ] **Step 5: Review commit**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review ops maintenance phase 7"
```

---

## Plan Self-Review

- Spec coverage: This plan covers notification retention, notification visibility, and withdrawal operating statistics without changing delivery or payout behavior.
- Placeholder scan: No placeholders remain; each task has tests, expected RED failure, implementation snippets, verification commands, and commit commands.
- Type consistency: `NotificationOutboxMaintenanceService`, `summary`, `purgeSentOlderThan`, `NotificationOutboxController`, and withdrawal `stats` are used consistently.
- Scope guard: Real providers, background scheduling, exports, and destructive payout cleanup are intentionally excluded.
