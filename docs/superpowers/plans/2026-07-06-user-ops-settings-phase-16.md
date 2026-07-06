# User Operations Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-operated `user_ops` settings center for invite defaults, password reset expiry, risk thresholds, and withdrawal amount limits while preserving current defaults.

**Architecture:** Reuse existing `system_config` and `sysconfig()` infrastructure. Add a typed `App\User\UserOpsSettings` service, a small admin controller/view, a menu entry, and targeted service integrations.

**Tech Stack:** PHP 8.3, Laravel 13, EasyAdmin dynamic admin routes, existing `system_config`, PHPUnit feature tests.

---

## File Structure

- Create: `app/User/UserOpsSettings.php`
  - Typed reader and validator for `system_config` group `user_ops`.
  - Owns defaults, integer clamps, money normalization, public settings payload, and save validation.
- Create: `app/Http/Controllers/admin/user/SettingsController.php`
  - Renders and saves the User Operations settings page.
  - Persists allowlisted settings into `system_config`.
- Create: `resources/views/admin/user/settings/index.blade.php`
  - Compact EasyAdmin/Layui form for the nine settings.
- Create: `tests/Feature/User/UserOpsSettingsTest.php`
  - Covers settings defaults/overrides, admin save, menu sync, and service integrations.
- Modify: `app/User/UserOpsMenuService.php`
  - Adds `Settings` menu entry under User Operations.
- Modify: `app/User/InviteService.php`
  - Uses configured invite defaults when creating each user's default invite code.
- Modify: `app/User/PasswordResetService.php`
  - Uses configured password reset expiry minutes.
- Modify: `app/User/RiskService.php`
  - Uses configured invite burst and activation failure thresholds/windows.
- Modify: `app/User/WithdrawalService.php`
  - Uses configured withdrawal min/max amount policy.

## Task 1: Typed settings service

**Files:**
- Create: `app/User/UserOpsSettings.php`
- Create: `tests/Feature/User/UserOpsSettingsTest.php`

- [ ] **Step 1: Write failing default/override tests**

Create `tests/Feature/User/UserOpsSettingsTest.php` with the shared setup helpers copied from existing user feature tests: migrate fresh, create `system_config`, create minimal `system_admin`, and seed `site_name`.

Add:

```php
public function test_user_ops_settings_defaults_preserve_current_behavior(): void
{
    $settings = app(\App\User\UserOpsSettings::class);

    $this->assertSame(0, $settings->inviteDefaultMaxUses());
    $this->assertSame(0, $settings->inviteDefaultExpiresDays());
    $this->assertSame(30, $settings->passwordResetExpiresMinutes());
    $this->assertSame(5, $settings->riskInviteBurstThreshold());
    $this->assertSame(24, $settings->riskInviteBurstWindowHours());
    $this->assertSame(5, $settings->riskActivationFailureThreshold());
    $this->assertSame(10, $settings->riskActivationFailureWindowMinutes());
    $this->assertSame('0.01', $settings->withdrawalMinAmount());
    $this->assertSame('0.00', $settings->withdrawalMaxAmount());
}

public function test_user_ops_settings_read_system_config_overrides(): void
{
    DB::table('system_config')->insert([
        ['group' => 'user_ops', 'name' => 'invite_default_max_uses', 'value' => '3'],
        ['group' => 'user_ops', 'name' => 'invite_default_expires_days', 'value' => '14'],
        ['group' => 'user_ops', 'name' => 'password_reset_expires_minutes', 'value' => '45'],
        ['group' => 'user_ops', 'name' => 'risk_invite_burst_threshold', 'value' => '7'],
        ['group' => 'user_ops', 'name' => 'risk_invite_burst_window_hours', 'value' => '12'],
        ['group' => 'user_ops', 'name' => 'risk_activation_failure_threshold', 'value' => '4'],
        ['group' => 'user_ops', 'name' => 'risk_activation_failure_window_minutes', 'value' => '15'],
        ['group' => 'user_ops', 'name' => 'withdrawal_min_amount', 'value' => '10'],
        ['group' => 'user_ops', 'name' => 'withdrawal_max_amount', 'value' => '500.5'],
    ]);

    Cache::flush();

    $settings = app(\App\User\UserOpsSettings::class);

    $this->assertSame(3, $settings->inviteDefaultMaxUses());
    $this->assertSame(14, $settings->inviteDefaultExpiresDays());
    $this->assertSame(45, $settings->passwordResetExpiresMinutes());
    $this->assertSame(7, $settings->riskInviteBurstThreshold());
    $this->assertSame(12, $settings->riskInviteBurstWindowHours());
    $this->assertSame(4, $settings->riskActivationFailureThreshold());
    $this->assertSame(15, $settings->riskActivationFailureWindowMinutes());
    $this->assertSame('10.00', $settings->withdrawalMinAmount());
    $this->assertSame('500.50', $settings->withdrawalMaxAmount());
}
```

- [ ] **Step 2: Run RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsSettingsTest.php --filter settings
```

Expected: FAIL because `App\User\UserOpsSettings` does not exist.

- [ ] **Step 3: Implement `UserOpsSettings`**

Create `app/User/UserOpsSettings.php`:

```php
<?php

namespace App\User;

use InvalidArgumentException;

final class UserOpsSettings
{
    public const GROUP = 'user_ops';

    public const DEFAULTS = [
        'invite_default_max_uses' => '0',
        'invite_default_expires_days' => '0',
        'password_reset_expires_minutes' => '30',
        'risk_invite_burst_threshold' => '5',
        'risk_invite_burst_window_hours' => '24',
        'risk_activation_failure_threshold' => '5',
        'risk_activation_failure_window_minutes' => '10',
        'withdrawal_min_amount' => '0.01',
        'withdrawal_max_amount' => '0.00',
    ];

    public function inviteDefaultMaxUses(): int
    {
        return $this->intValue('invite_default_max_uses', 0, 1_000_000);
    }

    public function inviteDefaultExpiresDays(): int
    {
        return $this->intValue('invite_default_expires_days', 0, 3650);
    }

    public function passwordResetExpiresMinutes(): int
    {
        return $this->intValue('password_reset_expires_minutes', 1, 1440);
    }

    public function riskInviteBurstThreshold(): int
    {
        return $this->intValue('risk_invite_burst_threshold', 1, 1000);
    }

    public function riskInviteBurstWindowHours(): int
    {
        return $this->intValue('risk_invite_burst_window_hours', 1, 720);
    }

    public function riskActivationFailureThreshold(): int
    {
        return $this->intValue('risk_activation_failure_threshold', 1, 1000);
    }

    public function riskActivationFailureWindowMinutes(): int
    {
        return $this->intValue('risk_activation_failure_window_minutes', 1, 1440);
    }

    public function withdrawalMinAmount(): string
    {
        return $this->moneyValue('withdrawal_min_amount');
    }

    public function withdrawalMaxAmount(): string
    {
        return $this->moneyValue('withdrawal_max_amount');
    }

    public function publicSettings(): array
    {
        return [
            'invite_default_max_uses' => $this->inviteDefaultMaxUses(),
            'invite_default_expires_days' => $this->inviteDefaultExpiresDays(),
            'password_reset_expires_minutes' => $this->passwordResetExpiresMinutes(),
            'risk_invite_burst_threshold' => $this->riskInviteBurstThreshold(),
            'risk_invite_burst_window_hours' => $this->riskInviteBurstWindowHours(),
            'risk_activation_failure_threshold' => $this->riskActivationFailureThreshold(),
            'risk_activation_failure_window_minutes' => $this->riskActivationFailureWindowMinutes(),
            'withdrawal_min_amount' => $this->withdrawalMinAmount(),
            'withdrawal_max_amount' => $this->withdrawalMaxAmount(),
        ];
    }

    public function validateForSave(array $payload): array
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $payload)) {
                $values[$key] = (string) $payload[$key];
            }
        }

        $validated = [
            'invite_default_max_uses' => (string) $this->validateInt($values, 'invite_default_max_uses', 0, 1_000_000),
            'invite_default_expires_days' => (string) $this->validateInt($values, 'invite_default_expires_days', 0, 3650),
            'password_reset_expires_minutes' => (string) $this->validateInt($values, 'password_reset_expires_minutes', 1, 1440),
            'risk_invite_burst_threshold' => (string) $this->validateInt($values, 'risk_invite_burst_threshold', 1, 1000),
            'risk_invite_burst_window_hours' => (string) $this->validateInt($values, 'risk_invite_burst_window_hours', 1, 720),
            'risk_activation_failure_threshold' => (string) $this->validateInt($values, 'risk_activation_failure_threshold', 1, 1000),
            'risk_activation_failure_window_minutes' => (string) $this->validateInt($values, 'risk_activation_failure_window_minutes', 1, 1440),
            'withdrawal_min_amount' => $this->validateMoney($values, 'withdrawal_min_amount'),
            'withdrawal_max_amount' => $this->validateMoney($values, 'withdrawal_max_amount'),
        ];

        if ($this->compareMoney($validated['withdrawal_max_amount'], '0.00') > 0
            && $this->compareMoney($validated['withdrawal_max_amount'], $validated['withdrawal_min_amount']) < 0) {
            throw new InvalidArgumentException('Withdrawal max amount must be zero or greater than min amount.');
        }

        return $validated;
    }

    private function stringValue(string $key): string
    {
        $value = sysconfig(self::GROUP, $key);

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULTS[$key];
    }

    private function intValue(string $key, int $min, int $max): int
    {
        $value = filter_var($this->stringValue($key), FILTER_VALIDATE_INT);

        if ($value === false) {
            $value = (int) self::DEFAULTS[$key];
        }

        return max($min, min($max, (int) $value));
    }

    private function moneyValue(string $key): string
    {
        return $this->money($this->stringValue($key));
    }

    private function validateInt(array $values, string $key, int $min, int $max): int
    {
        $value = $values[$key] ?? self::DEFAULTS[$key];

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new InvalidArgumentException("{$key} must be between {$min} and {$max}.");
        }

        return $int;
    }

    private function validateMoney(array $values, string $key): string
    {
        $value = $values[$key] ?? self::DEFAULTS[$key];

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException("{$key} must be a non-negative amount.");
        }

        return $this->money($value);
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function compareMoney(string $left, string $right): int
    {
        return ((int) round(((float) $left) * 100)) <=> ((int) round(((float) $right) * 100));
    }
}
```

- [ ] **Step 4: Run GREEN**

Run the same PHPUnit command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/User/UserOpsSettings.php tests/Feature/User/UserOpsSettingsTest.php
git commit -m "feat: add user ops settings reader"
```

## Task 2: Admin settings page and menu

**Files:**
- Create: `app/Http/Controllers/admin/user/SettingsController.php`
- Create: `resources/views/admin/user/settings/index.blade.php`
- Modify: `app/User/UserOpsMenuService.php`
- Modify: `tests/Feature/User/UserOpsSettingsTest.php`

- [ ] **Step 1: Add failing admin save/menu tests**

Add tests:

```php
public function test_admin_user_ops_settings_page_renders_current_values(): void
{
    $response = $this->get('/admin/user/settings/index');

    $response->assertOk();
    $response->assertSee('User Operations Settings');
    $response->assertSee('name="password_reset_expires_minutes"', false);
    $response->assertSee('value="30"', false);
}

public function test_admin_user_ops_settings_save_validates_and_persists_allowlisted_values(): void
{
    $response = $this->postJson('/admin/user/settings/save', [
        'invite_default_max_uses' => '3',
        'invite_default_expires_days' => '14',
        'password_reset_expires_minutes' => '45',
        'risk_invite_burst_threshold' => '7',
        'risk_invite_burst_window_hours' => '12',
        'risk_activation_failure_threshold' => '4',
        'risk_activation_failure_window_minutes' => '15',
        'withdrawal_min_amount' => '10',
        'withdrawal_max_amount' => '500.5',
        'unexpected_key' => 'must-not-save',
    ]);

    $response->assertOk()->assertJsonPath('code', 1);

    $this->assertSame('45', DB::table('system_config')->where('group', 'user_ops')->where('name', 'password_reset_expires_minutes')->value('value'));
    $this->assertNull(DB::table('system_config')->where('group', 'user_ops')->where('name', 'unexpected_key')->value('value'));
}

public function test_admin_user_ops_settings_save_rejects_invalid_amount_policy(): void
{
    $response = $this->postJson('/admin/user/settings/save', [
        'withdrawal_min_amount' => '100',
        'withdrawal_max_amount' => '50',
    ]);

    $response->assertOk()->assertJsonPath('code', 0);
    $response->assertJsonFragment(['msg' => 'Withdrawal max amount must be zero or greater than min amount.']);
}

public function test_user_ops_menu_sync_adds_settings_entry(): void
{
    $this->artisan('user:ops-menu:sync')->assertExitCode(0);

    $this->assertDatabaseHas('system_menu', [
        'href' => 'user/settings/index',
        'title' => 'Settings',
    ]);
}
```

- [ ] **Step 2: Run RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsSettingsTest.php --filter "settings_page|settings_save|menu_sync"
```

Expected: FAIL because controller/view/menu entry are missing.

- [ ] **Step 3: Implement controller**

Create `app/Http/Controllers/admin/user/SettingsController.php`:

```php
<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\TriggerService;
use App\Models\SystemConfig;
use App\User\UserOpsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class SettingsController extends AdminController
{
    public function index(UserOpsSettings $settings): View
    {
        $this->assign('settings', $settings->publicSettings());

        return $this->fetch();
    }

    public function save(UserOpsSettings $settings): JsonResponse
    {
        if (! request()->ajax()) {
            return $this->error();
        }

        try {
            $values = $settings->validateForSave(request()->post());

            foreach ($values as $name => $value) {
                SystemConfig::query()->updateOrCreate([
                    'group' => UserOpsSettings::GROUP,
                    'name' => $name,
                ], [
                    'value' => $value,
                ]);
            }

            TriggerService::updateSysConfig();

            return $this->success('Saved');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }
}
```

- [ ] **Step 4: Implement Blade view**

Create `resources/views/admin/user/settings/index.blade.php` with a single `layui-form` posting to `user/settings/save`. Include input names for all nine settings and a submit button. Use compact labels and helper text; no nested cards.

- [ ] **Step 5: Add menu entry**

In `app/User/UserOpsMenuService.php`, add:

```php
['title' => 'Settings', 'href' => 'user/settings/index', 'icon' => 'fa fa-cogs', 'sort' => 880],
```

- [ ] **Step 6: Run GREEN and commit**

Run the focused tests. Expected: PASS.

```bash
git add app/Http/Controllers/admin/user/SettingsController.php resources/views/admin/user/settings/index.blade.php app/User/UserOpsMenuService.php tests/Feature/User/UserOpsSettingsTest.php
git commit -m "feat: add user ops settings admin page"
```

## Task 3: Integrate settings into business services

**Files:**
- Modify: `app/User/InviteService.php`
- Modify: `app/User/PasswordResetService.php`
- Modify: `app/User/RiskService.php`
- Modify: `app/User/WithdrawalService.php`
- Modify: `tests/Feature/User/UserOpsSettingsTest.php`

- [ ] **Step 1: Add failing service integration tests**

Add tests proving:

```php
public function test_invite_default_code_uses_configured_limits(): void
{
    $this->saveUserOpsConfig([
        'invite_default_max_uses' => '2',
        'invite_default_expires_days' => '10',
    ]);

    $user = $this->createUserAccount(['email' => 'invite-settings@example.com']);
    $code = app(\App\User\InviteService::class)->createDefaultCode($user);

    $this->assertSame(2, (int) $code->max_uses);
    $this->assertNotNull($code->expires_at);
}

public function test_password_reset_uses_configured_expiry(): void
{
    $this->saveUserOpsConfig(['password_reset_expires_minutes' => '45']);
    $this->createUserAccount(['email' => 'reset-settings@example.com']);

    $result = app(\App\User\PasswordResetService::class)->requestReset([
        'account' => 'reset-settings@example.com',
    ], '127.0.0.1');

    $this->assertSame(2700, $result['expires_in']);
}

public function test_withdrawal_amount_policy_uses_configured_min_and_max(): void
{
    $this->saveUserOpsConfig([
        'withdrawal_min_amount' => '10.00',
        'withdrawal_max_amount' => '100.00',
    ]);

    $user = $this->createUserAccount(['email' => 'withdraw-settings@example.com']);
    app(\App\User\BalanceLedgerService::class)->credit($user->id, '50.00', 'admin_adjust');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Withdrawal amount must be at least 10.00.');

    app(\App\User\WithdrawalService::class)->request($user->id, '5.00', ['account' => 'bank'], '127.0.0.1');
}
```

Add one risk test:

```php
public function test_risk_service_uses_configured_activation_failure_threshold(): void
{
    $this->saveUserOpsConfig([
        'risk_activation_failure_threshold' => '2',
        'risk_activation_failure_window_minutes' => '10',
    ]);

    app(\App\User\RiskService::class)->recordActivationFailure(1, '127.0.0.1', 'bad');
    $second = app(\App\User\RiskService::class)->recordActivationFailure(1, '127.0.0.1', 'bad');

    $this->assertSame('medium', $second['severity']);
}
```

- [ ] **Step 2: Run RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsSettingsTest.php --filter "configured"
```

Expected: FAIL because services still use hardcoded defaults.

- [ ] **Step 3: Inject and use settings**

Modify constructors:

```php
public function __construct(private readonly UserOpsSettings $settings)
```

For services that already have constructor dependencies, append `UserOpsSettings $settings`.

Apply behavior:

- `InviteService::createDefaultCode()`:
  - `max_uses` becomes `$this->settings->inviteDefaultMaxUses()`.
  - If expiry days > 0, set `expires_at` to `Carbon::now()->addDays($days)`.
- `PasswordResetService::requestReset()`:
  - `$minutes = $this->settings->passwordResetExpiresMinutes()`.
  - `expires_at` uses `addMinutes($minutes)`.
  - `expires_in` returns `$minutes * 60`.
- `RiskService::evaluateInviteRegistration()`:
  - threshold from `riskInviteBurstThreshold()`.
  - since from `riskInviteBurstWindowHours() * 3600`.
- `RiskService::recordActivationFailure()`:
  - recent window from `riskActivationFailureWindowMinutes() * 60`.
  - severity threshold from `riskActivationFailureThreshold()`.
- `WithdrawalService::request()`:
  - after `$amount = $this->positiveMoney($amount)`, compare against min/max.
  - If below min, throw `Withdrawal amount must be at least <min>.`
  - If max > 0 and amount > max, throw `Withdrawal amount must be at most <max>.`

- [ ] **Step 4: Run GREEN and commit**

Run the focused tests. Expected: PASS.

```bash
git add app/User/InviteService.php app/User/PasswordResetService.php app/User/RiskService.php app/User/WithdrawalService.php tests/Feature/User/UserOpsSettingsTest.php
git commit -m "feat: apply user ops settings to operations"
```

## Task 4: Verification, review, commit, push

**Files:**
- Review all P16 changes.

- [ ] **Step 1: Run syntax checks**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -l app\User\UserOpsSettings.php
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\SettingsController.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserOpsSettingsTest.php
```

- [ ] **Step 2: Run focused suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsSettingsTest.php tests\Feature\User\UserInviteTest.php tests\Feature\User\UserPasswordResetTest.php tests\Feature\User\UserRiskOpsTest.php tests\Feature\User\UserAffiliateBalanceTest.php
```

- [ ] **Step 3: Run smoke-related regression**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserOpsVisibilityTest.php tests\Feature\User\UserAdminSmokeScriptTest.php tests\Feature\User\UserPortalSmokeScriptTest.php tests\Feature\User\DeployAcceptanceScriptTest.php
```

- [ ] **Step 4: Run full SQLite suite**

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 5: Request code review**

Use a reviewer subagent with:

```text
Description: P16 user operations settings center.
Requirements: docs/superpowers/specs/2026-07-06-user-ops-settings-design.md and this plan.
Base: a1425d8
Head: current HEAD after P16 implementation.
```

Fix all Critical and Important findings.

- [ ] **Step 6: Push**

```bash
git push origin main
```

Expected: push succeeds through SSH remote.

## Self-review

Spec coverage: settings service, admin page, menu sync, invite/password/risk/withdrawal integrations, validation, tests, review, and push are covered.

Placeholder scan: no placeholders remain.

Type consistency: `UserOpsSettings`, `user_ops`, setting keys, controller route `user/settings/index`, and test names are consistent.
