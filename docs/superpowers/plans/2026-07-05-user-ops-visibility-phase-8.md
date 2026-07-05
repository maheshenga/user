# User Ops Visibility Phase 8 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the completed P1-P7 user operations work visible in the EasyAdmin backend through a menu group, an overview dashboard, and a repeatable menu synchronization command.

**Architecture:** Add a small menu synchronization service and artisan command that write `system_menu` rows idempotently. Add a dashboard metrics service and a thin admin controller/view pair under the existing `admin/user` EasyAdmin pattern. Keep this phase non-destructive: no P1-P7 business rules or money movement rules change.

**Tech Stack:** PHP 8.3, Laravel 13, EasyAdmin admin controllers/views/JS, Eloquent/Query Builder, PHPUnit 12, SQLite test runner through `composer run test:sqlite`.

---

## Scope

Included:

- "User Operations" parent menu.
- Child menu entries for the existing user admin pages.
- `user:ops-menu:sync` artisan command.
- `/admin/user/dashboard/index` overview page.
- Dashboard metrics JSON for operational counts and amounts.
- Focused tests, full-suite verification, review checkpoint, local commit, and push.

Excluded:

- Public user-facing pages.
- Global EasyAdmin layout redesign.
- Charts or heavy dashboard JavaScript.
- New payment, payout, SMS, or queue providers.
- Business-rule changes to registration, invite, VIP, balance, withdrawal, risk, or notification behavior.
- Destructive cleanup of existing menu rows.

---

## File Structure

- Create `app/User/UserOpsMenuService.php`
  - Owns the menu definition and idempotent synchronization into `system_menu`.
- Create `app/User/UserOpsDashboardService.php`
  - Owns dashboard aggregate queries and money formatting.
- Create `app/Http/Controllers/admin/user/DashboardController.php`
  - Renders the dashboard view for normal requests and JSON metrics for AJAX/JSON requests.
- Create `resources/views/admin/user/dashboard/index.blade.php`
  - Provides a compact EasyAdmin-style page.
- Create `public/static/admin/js/user/dashboard.js`
  - Renders simple metrics and entry links without changing global layout.
- Modify `routes/console.php`
  - Registers `user:ops-menu:sync`.
- Create `tests/Feature/User/UserOpsVisibilityTest.php`
  - Covers menu sync idempotency and dashboard behavior.

---

## Task 1: Menu Synchronization Command

**Files:**

- Create: `app/User/UserOpsMenuService.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/User/UserOpsVisibilityTest.php`

- [ ] **Step 1: Add failing menu sync tests**

Create `tests/Feature/User/UserOpsVisibilityTest.php`:

```php
<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckAuth;
use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Http\Middleware\SystemLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserOpsVisibilityTest extends TestCase
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

    public function test_user_ops_menu_sync_creates_visible_menu_entries(): void
    {
        $this->artisan('user:ops-menu:sync')
            ->expectsOutputToContain('synced=13')
            ->assertExitCode(0);

        $parent = DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', 'User Operations')
            ->first();

        $this->assertNotNull($parent);
        $this->assertSame('', (string) $parent->href);
        $this->assertSame(1, (int) $parent->status);

        foreach ($this->expectedMenuEntries() as $href => $title) {
            $this->assertDatabaseHas('system_menu', [
                'pid' => $parent->id,
                'title' => $title,
                'href' => $href,
                'status' => 1,
            ]);
        }
    }

    public function test_user_ops_menu_sync_is_idempotent(): void
    {
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);
        $this->artisan('user:ops-menu:sync')->assertExitCode(0);

        $this->assertSame(1, DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', 'User Operations')
            ->count());

        foreach (array_keys($this->expectedMenuEntries()) as $href) {
            $this->assertSame(1, DB::table('system_menu')->where('href', $href)->count(), $href);
        }
    }

    private function expectedMenuEntries(): array
    {
        return [
            'user/dashboard/index' => 'Overview',
            'user/account/index' => 'User Accounts',
            'user/invite/index' => 'Invite Codes',
            'user/invite/relations' => 'Invite Relations',
            'user/vip-plan/index' => 'VIP Plans',
            'user/activation-code/index' => 'Activation Codes',
            'user/activation-code/redemptions' => 'Activation Redemptions',
            'user/balance/index' => 'Balance Ledger',
            'user/commission/index' => 'Affiliate Commissions',
            'user/withdrawal/index' => 'Withdrawal Review',
            'user/risk-event/index' => 'Risk Events',
            'user/security-log/index' => 'Security Logs',
            'user/notification-outbox/index' => 'Notification Outbox',
        ];
    }

    private function createSystemConfigTable(): void
    {
        if (! Schema::hasTable('system_config')) {
            Schema::create('system_config', function (Blueprint $table): void {
                $table->id();
                $table->string('group', 120)->default('');
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
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter menu
```

Expected: FAIL because `user:ops-menu:sync` does not exist.

- [ ] **Step 3: Implement menu sync service**

Create `app/User/UserOpsMenuService.php`:

```php
<?php

namespace App\User;

use App\Http\Services\TriggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class UserOpsMenuService
{
    private const PARENT_TITLE = 'User Operations';

    private const ENTRIES = [
        ['title' => 'Overview', 'href' => 'user/dashboard/index', 'icon' => 'fa fa-dashboard', 'sort' => 990],
        ['title' => 'User Accounts', 'href' => 'user/account/index', 'icon' => 'fa fa-user', 'sort' => 980],
        ['title' => 'Invite Codes', 'href' => 'user/invite/index', 'icon' => 'fa fa-share-alt', 'sort' => 970],
        ['title' => 'Invite Relations', 'href' => 'user/invite/relations', 'icon' => 'fa fa-sitemap', 'sort' => 960],
        ['title' => 'VIP Plans', 'href' => 'user/vip-plan/index', 'icon' => 'fa fa-diamond', 'sort' => 950],
        ['title' => 'Activation Codes', 'href' => 'user/activation-code/index', 'icon' => 'fa fa-ticket', 'sort' => 940],
        ['title' => 'Activation Redemptions', 'href' => 'user/activation-code/redemptions', 'icon' => 'fa fa-check-square-o', 'sort' => 930],
        ['title' => 'Balance Ledger', 'href' => 'user/balance/index', 'icon' => 'fa fa-list-alt', 'sort' => 920],
        ['title' => 'Affiliate Commissions', 'href' => 'user/commission/index', 'icon' => 'fa fa-money', 'sort' => 910],
        ['title' => 'Withdrawal Review', 'href' => 'user/withdrawal/index', 'icon' => 'fa fa-credit-card', 'sort' => 900],
        ['title' => 'Risk Events', 'href' => 'user/risk-event/index', 'icon' => 'fa fa-warning', 'sort' => 890],
        ['title' => 'Security Logs', 'href' => 'user/security-log/index', 'icon' => 'fa fa-shield', 'sort' => 880],
        ['title' => 'Notification Outbox', 'href' => 'user/notification-outbox/index', 'icon' => 'fa fa-envelope', 'sort' => 870],
    ];

    public function sync(): array
    {
        if (! Schema::hasTable('system_menu')) {
            throw new RuntimeException('system_menu table does not exist. Import the EasyAdmin base install first.');
        }

        $now = time();
        $parentId = $this->syncParent($now);

        $synced = 0;
        foreach (self::ENTRIES as $entry) {
            $this->syncChild($parentId, $entry, $now);
            $synced++;
        }

        TriggerService::updateMenu();

        return [
            'parent_id' => $parentId,
            'synced' => $synced,
        ];
    }

    private function syncParent(int $now): int
    {
        $existing = DB::table('system_menu')
            ->where('pid', 0)
            ->where('title', self::PARENT_TITLE)
            ->whereNull('delete_time')
            ->first();

        $data = [
            'pid' => 0,
            'title' => self::PARENT_TITLE,
            'icon' => 'fa fa-users',
            'href' => '',
            'target' => '_self',
            'sort' => 990,
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        if ($existing !== null) {
            DB::table('system_menu')->where('id', $existing->id)->update($data);

            return (int) $existing->id;
        }

        $data['create_time'] = $now;

        return (int) DB::table('system_menu')->insertGetId($data);
    }

    private function syncChild(int $parentId, array $entry, int $now): void
    {
        $data = [
            'pid' => $parentId,
            'title' => $entry['title'],
            'icon' => $entry['icon'],
            'href' => $entry['href'],
            'target' => '_self',
            'sort' => $entry['sort'],
            'status' => 1,
            'update_time' => $now,
            'delete_time' => null,
        ];

        $existing = DB::table('system_menu')->where('href', $entry['href'])->first();
        if ($existing !== null) {
            DB::table('system_menu')->where('id', $existing->id)->update($data);

            return;
        }

        $data['create_time'] = $now;
        DB::table('system_menu')->insert($data);
    }
}
```

- [ ] **Step 4: Register the artisan command**

Append this command to `routes/console.php` after the existing user notification commands:

```php
Artisan::command('user:ops-menu:sync', function (): int {
    try {
        $result = app(\App\User\UserOpsMenuService::class)->sync();
    } catch (\RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('parent_id='.$result['parent_id'].' synced='.$result['synced']);

    return Command::SUCCESS;
})->purpose('Synchronize EasyAdmin menu entries for user operations');
```

- [ ] **Step 5: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter menu
```

Expected: PASS with 2 tests.

Commit:

```powershell
git add app/User/UserOpsMenuService.php routes/console.php tests/Feature/User/UserOpsVisibilityTest.php
git commit -m "feat: add user ops menu sync"
```

---

## Task 2: Dashboard Metrics Service

**Files:**

- Create: `app/User/UserOpsDashboardService.php`
- Modify: `tests/Feature/User/UserOpsVisibilityTest.php`

- [ ] **Step 1: Add failing dashboard metrics tests**

Add these imports to `tests/Feature/User/UserOpsVisibilityTest.php`:

```php
use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;
use App\User\UserOpsDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
```

In `setUp()`, before `migrate:fresh`, add:

```php
Config::set('app.key', 'base64:'.base64_encode(str_repeat('v', 32)));
```

Add these tests:

```php
public function test_user_ops_dashboard_metrics_return_zero_values_for_empty_tables(): void
{
    $metrics = app(UserOpsDashboardService::class)->metrics();

    $this->assertSame(0, $metrics['total_users']);
    $this->assertSame(0, $metrics['today_registrations']);
    $this->assertSame(0, $metrics['active_vip_users']);
    $this->assertSame(0, $metrics['pending_withdrawals']);
    $this->assertSame(0, $metrics['pending_payouts']);
    $this->assertSame(0, $metrics['pending_notifications']);
    $this->assertSame(0, $metrics['retryable_notifications']);
    $this->assertSame(0, $metrics['risk_events']);
    $this->assertSame('0.00', $metrics['today_commission_amount']);
}

public function test_user_ops_dashboard_metrics_reflect_current_operations_data(): void
{
    Carbon::setTestNow(Carbon::create(2026, 7, 5, 12, 0, 0));

    try {
        UserAccount::query()->create([
            'email' => 'normal@example.com',
            'password' => 'secret123',
            'create_time' => now()->timestamp,
        ]);
        UserAccount::query()->create([
            'email' => 'vip@example.com',
            'password' => 'secret123',
            'vip_level' => 2,
            'vip_expires_at' => now()->addDay(),
            'create_time' => now()->subDay()->timestamp,
        ]);

        UserWithdrawalRequest::query()->create([
            'withdrawal_no' => 'WD202607050001',
            'user_id' => 1,
            'amount' => '10.00',
            'status' => 'pending',
            'request_ip' => '127.0.0.1',
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);
        UserWithdrawalRequest::query()->create([
            'withdrawal_no' => 'WD202607050002',
            'user_id' => 2,
            'amount' => '20.00',
            'status' => 'approved',
            'request_ip' => '127.0.0.1',
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);
        UserWithdrawalRequest::query()->create([
            'withdrawal_no' => 'WD202607050003',
            'user_id' => 2,
            'amount' => '30.00',
            'status' => 'payout_failed',
            'request_ip' => '127.0.0.1',
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);

        UserNotificationOutbox::query()->create([
            'user_id' => 1,
            'type' => 'password_reset',
            'channel' => 'email',
            'recipient' => 'normal@example.com',
            'recipient_mask' => 'n***@example.com',
            'subject' => 'Reset',
            'payload_json' => ['token' => 'secret'],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now()->subMinute(),
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);
        UserNotificationOutbox::query()->create([
            'user_id' => 2,
            'type' => 'password_reset',
            'channel' => 'email',
            'recipient' => 'vip@example.com',
            'recipient_mask' => 'v***@example.com',
            'subject' => 'Reset',
            'payload_json' => ['token' => 'secret'],
            'status' => 'pending',
            'attempt_count' => 0,
            'available_at' => now()->addHour(),
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);

        UserRiskEvent::query()->create([
            'user_id' => 1,
            'category' => 'auth',
            'event_type' => 'login_failed',
            'severity' => 'medium',
            'ip' => '127.0.0.1',
            'status' => 'open',
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);

        AffiliateCommission::query()->create([
            'source_type' => 'vip_order',
            'source_id' => 1001,
            'buyer_user_id' => 1,
            'beneficiary_user_id' => 2,
            'level' => 1,
            'amount' => '12.34',
            'status' => 'pending',
            'create_time' => now()->timestamp,
            'update_time' => now()->timestamp,
        ]);

        $metrics = app(UserOpsDashboardService::class)->metrics();

        $this->assertSame(2, $metrics['total_users']);
        $this->assertSame(1, $metrics['today_registrations']);
        $this->assertSame(1, $metrics['active_vip_users']);
        $this->assertSame(1, $metrics['pending_withdrawals']);
        $this->assertSame(2, $metrics['pending_payouts']);
        $this->assertSame(2, $metrics['pending_notifications']);
        $this->assertSame(1, $metrics['retryable_notifications']);
        $this->assertSame(1, $metrics['risk_events']);
        $this->assertSame('12.34', $metrics['today_commission_amount']);
    } finally {
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter dashboard_metrics
```

Expected: FAIL because `UserOpsDashboardService` does not exist.

- [ ] **Step 3: Implement dashboard metrics service**

Create `app/User/UserOpsDashboardService.php`:

```php
<?php

namespace App\User;

use App\Models\AffiliateCommission;
use App\Models\UserAccount;
use App\Models\UserNotificationOutbox;
use App\Models\UserRiskEvent;
use App\Models\UserWithdrawalRequest;

final class UserOpsDashboardService
{
    public function metrics(): array
    {
        $todayStart = now()->startOfDay()->timestamp;
        $tomorrowStart = now()->copy()->addDay()->startOfDay()->timestamp;

        $todayCommission = AffiliateCommission::query()
            ->where('create_time', '>=', $todayStart)
            ->where('create_time', '<', $tomorrowStart)
            ->sum('amount');

        return [
            'total_users' => UserAccount::query()->count(),
            'today_registrations' => UserAccount::query()
                ->where('create_time', '>=', $todayStart)
                ->where('create_time', '<', $tomorrowStart)
                ->count(),
            'active_vip_users' => UserAccount::query()
                ->where('vip_level', '>', 0)
                ->where(function ($query): void {
                    $query->whereNull('vip_expires_at')->orWhere('vip_expires_at', '>', now());
                })
                ->count(),
            'pending_withdrawals' => UserWithdrawalRequest::query()
                ->where('status', 'pending')
                ->count(),
            'pending_payouts' => UserWithdrawalRequest::query()
                ->whereIn('status', ['approved', 'payout_failed'])
                ->count(),
            'pending_notifications' => UserNotificationOutbox::query()
                ->where('status', 'pending')
                ->count(),
            'retryable_notifications' => UserNotificationOutbox::query()
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now()->timestamp);
                })
                ->count(),
            'risk_events' => UserRiskEvent::query()->count(),
            'today_commission_amount' => $this->money($todayCommission),
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
```

- [ ] **Step 4: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter dashboard_metrics
```

Expected: PASS with 2 tests.

Commit:

```powershell
git add app/User/UserOpsDashboardService.php tests/Feature/User/UserOpsVisibilityTest.php
git commit -m "feat: add user ops dashboard metrics"
```

---

## Task 3: Dashboard Admin Page

**Files:**

- Create: `app/Http/Controllers/admin/user/DashboardController.php`
- Create: `resources/views/admin/user/dashboard/index.blade.php`
- Create: `public/static/admin/js/user/dashboard.js`
- Modify: `tests/Feature/User/UserOpsVisibilityTest.php`

- [ ] **Step 1: Add failing dashboard endpoint tests**

Add these tests to `tests/Feature/User/UserOpsVisibilityTest.php`:

```php
public function test_admin_user_ops_dashboard_json_returns_metrics(): void
{
    $response = $this->getJson('/admin/user/dashboard/index');

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.total_users', 0)
        ->assertJsonPath('data.today_commission_amount', '0.00');
}

public function test_admin_user_ops_dashboard_page_renders(): void
{
    $response = $this->get('/admin/user/dashboard/index');

    $response->assertOk();
    $response->assertSee('User Operations');
    $response->assertSee('Total Users');
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter dashboard
```

Expected: FAIL because `DashboardController` and dashboard view do not exist.

- [ ] **Step 3: Implement dashboard controller**

Create `app/Http/Controllers/admin/user/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\User\UserOpsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'User Operations Dashboard')]
class DashboardController extends AdminController
{
    #[NodeAnnotation(title: 'Overview', auth: true)]
    public function index(): View|JsonResponse
    {
        if (request()->ajax() || request()->expectsJson()) {
            return response()->json([
                'code' => 1,
                'msg' => 'User operations metrics.',
                'data' => app(UserOpsDashboardService::class)->metrics(),
                'url' => '',
                'wait' => 3,
                '__token__' => csrf_token(),
            ]);
        }

        return $this->fetch();
    }
}
```

- [ ] **Step 4: Implement dashboard Blade view**

Create `resources/views/admin/user/dashboard/index.blade.php`:

```blade
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>User Operations</legend>
            <div class="layui-row layui-col-space15" id="userOpsMetrics">
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Total Users</div><div class="layui-card-body" data-metric="total_users">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Today Registrations</div><div class="layui-card-body" data-metric="today_registrations">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Active VIP Users</div><div class="layui-card-body" data-metric="active_vip_users">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Pending Withdrawals</div><div class="layui-card-body" data-metric="pending_withdrawals">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Pending Payouts</div><div class="layui-card-body" data-metric="pending_payouts">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Pending Notifications</div><div class="layui-card-body" data-metric="pending_notifications">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Retryable Notifications</div><div class="layui-card-body" data-metric="retryable_notifications">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Risk Events</div><div class="layui-card-body" data-metric="risk_events">0</div></div></div>
                <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">Today Commission</div><div class="layui-card-body" data-metric="today_commission_amount">0.00</div></div></div>
            </div>
        </fieldset>
        <table class="layui-table">
            <thead>
            <tr><th>Area</th><th>Entry</th></tr>
            </thead>
            <tbody>
            <tr><td>User Accounts</td><td><a data-open-tab="user/account/index">Open</a></td></tr>
            <tr><td>Withdrawals</td><td><a data-open-tab="user/withdrawal/index">Open</a></td></tr>
            <tr><td>Notification Outbox</td><td><a data-open-tab="user/notification-outbox/index">Open</a></td></tr>
            <tr><td>Risk Events</td><td><a data-open-tab="user/risk-event/index">Open</a></td></tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
```

- [ ] **Step 5: Implement dashboard JavaScript**

Create `public/static/admin/js/user/dashboard.js`:

```javascript
define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        stats_url: 'user/dashboard/index'
    };

    function setMetric(key, value) {
        $('[data-metric="' + key + '"]').text(value);
    }

    return {
        index: function () {
            ea.request.get({
                url: ea.url(init.stats_url)
            }, function (response) {
                var data = response.data || {};
                Object.keys(data).forEach(function (key) {
                    setMetric(key, data[key]);
                });
            });

            $('body').on('click', '[data-open-tab]', function () {
                var href = $(this).data('open-tab');
                parent.layui.element.tabAdd('layuiminiTab', {
                    title: $(this).closest('tr').find('td:first').text(),
                    content: '<iframe width="100%" height="100%" frameborder="0" src="' + ea.url(href) + '"></iframe>',
                    id: href
                });
                parent.layui.element.tabChange('layuiminiTab', href);
            });
        }
    };
});
```

- [ ] **Step 6: Verify GREEN and commit**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php --filter dashboard
node --check public\static\admin\js\user\dashboard.js
```

Expected: PHPUnit PASS and `node --check` exit 0.

Commit:

```powershell
git add app/Http/Controllers/admin/user/DashboardController.php resources/views/admin/user/dashboard/index.blade.php public/static/admin/js/user/dashboard.js tests/Feature/User/UserOpsVisibilityTest.php
git commit -m "feat: add user ops dashboard"
```

---

## Task 4: Review, Local Sync, and Verification

**Files:**

- Review all files changed in Tasks 1-3.

- [ ] **Step 1: Run focused visibility tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php
```

Expected: PASS.

- [ ] **Step 2: Run existing focused user-admin tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminRiskOpsControllerTest.php
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminNotificationOpsControllerTest.php
```

Expected: all PASS.

- [ ] **Step 3: Run full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 4: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserOpsMenuService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserOpsDashboardService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\DashboardController.php
E:\code\user\.tools\php-8.3.32\php.exe -l routes\console.php
node --check public\static\admin\js\user\dashboard.js
git diff --check
```

Expected: all checks clean.

- [ ] **Step 5: Review checklist**

Confirm:

- Menu command creates one parent menu only.
- Menu command creates exactly 13 child entries.
- Menu command does not duplicate rows after repeated runs.
- Menu command does not delete unrelated menu rows.
- Menu command clears menu cache.
- Dashboard JSON returns all required metric keys.
- Dashboard handles empty tables with zero values.
- Dashboard amount values use fixed two-decimal strings.
- No P1-P7 business rules changed.
- No user secrets are exposed by the dashboard.

- [ ] **Step 6: Commit review checkpoint**

If no code changes are needed after review:

```powershell
git commit --allow-empty -m "chore: review user ops visibility phase"
```

---

## Task 5: Apply Menu Sync to Local Test Instance and Push

**Files:**

- No source files should change.

- [ ] **Step 1: Run menu sync on the local MySQL-backed test instance**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe artisan user:ops-menu:sync
```

Expected output contains:

```text
synced=13
```

- [ ] **Step 2: Verify local backend pages**

Run:

```powershell
try { $r=Invoke-WebRequest -UseBasicParsing -Uri http://127.0.0.1:8000/admin/user/dashboard/index -TimeoutSec 15; "status=$($r.StatusCode) length=$($r.Content.Length)" } catch { "ERR $($_.Exception.Message)" }
```

Expected:

```text
status=200
```

- [ ] **Step 3: Verify git status**

Run:

```powershell
git status --short --branch
```

Expected: clean except ahead commits.

- [ ] **Step 4: Push to GitHub**

Run:

```powershell
git push origin main
```

If Git DNS fails in this environment, run:

```powershell
git -c http.curloptResolve=github.com:443:198.18.0.42 push origin main
```

Expected: push succeeds to `https://github.com/maheshenga/user.git`.

---

## Plan Self-Review

- Spec coverage: Tasks cover visible menu entries, dashboard page, dashboard metrics, idempotent menu sync, tests, local menu application, and push.
- Completion-marker scan: no unresolved markers remain.
- Type consistency: `UserOpsMenuService`, `UserOpsDashboardService`, `DashboardController`, `user:ops-menu:sync`, and all metric keys are named consistently across tasks.
- Scope guard: no public frontend, provider integration, global layout redesign, destructive menu cleanup, or P1-P7 business-rule changes are included.
