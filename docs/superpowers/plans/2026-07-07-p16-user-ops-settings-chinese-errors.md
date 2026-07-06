# P16 User Ops Settings Chinese Errors Implementation Plan

> **Execution note:** Implement directly in the current workspace without subagents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the user operations settings admin save validation messages Chinese by default, so backend operators do not see English error text.

**Architecture:** Keep the existing `App\User\UserOpsSettings` validation flow and controller response shape. Replace English `InvalidArgumentException` messages with concise Chinese messages and add tests that assert the admin JSON response and service validation errors are localized.

**Tech Stack:** PHP 8.3, Laravel feature tests, existing `UserOpsSettingsTest`, SQLite test runner.

## Global Constraints

- Do not change saved setting keys, ranges, defaults, or business behavior.
- Do not change the settings page layout.
- Keep public API response shape unchanged: `code`, `msg`, `data`, `url`, `wait`, `__token__`.
- Execute directly in this session; do not dispatch subagents.

---

## File Structure

- Modify: `app/User/UserOpsSettings.php`
  - Replace English validation messages with Chinese messages.
- Modify: `tests/Feature/User/UserOpsSettingsTest.php`
  - Update existing invalid amount tests to expect Chinese.
  - Add tests for integer and money validation messages.

## Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserOpsSettingsTest.php`

**Interfaces:**
- Consumes: `App\User\UserOpsSettings::validateForSave(array $payload): array`.
- Produces: failing expectations for Chinese validation messages.

- [ ] **Step 1: Update invalid amount policy test**

Change `test_admin_user_ops_settings_save_rejects_invalid_amount_policy()` to expect:

```php
$response->assertJsonFragment(['msg' => '最大提现金额必须为 0，或大于等于最小提现金额。']);
```

- [ ] **Step 2: Update withdrawal service policy tests**

Change exception expectations:

```php
$this->expectExceptionMessage('提现金额不能低于 10.00。');
```

```php
$this->expectExceptionMessage('提现金额不能高于 100.00。');
```

- [ ] **Step 3: Add direct settings validation tests**

Add:

```php
public function test_user_ops_settings_validation_messages_are_chinese(): void
{
    $settings = app(\App\User\UserOpsSettings::class);

    try {
        $settings->validateForSave(['password_reset_expires_minutes' => 'abc']);
        $this->fail('Expected invalid integer exception.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame('找回密码有效分钟数必须是整数。', $exception->getMessage());
    }

    try {
        $settings->validateForSave(['withdrawal_min_amount' => '-1']);
        $this->fail('Expected invalid money exception.');
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame('最小提现金额必须是非负金额。', $exception->getMessage());
    }
}
```

- [ ] **Step 4: Run RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserOpsSettingsTest"
```

Expected: FAIL because current validation messages are English.

## Task 2: GREEN Implementation

**Files:**
- Modify: `app/User/UserOpsSettings.php`
- Modify related service validation messages only if focused tests prove they remain English.

**Interfaces:**
- Keeps `validateForSave(array $payload): array` unchanged.
- Produces localized `InvalidArgumentException` messages.

- [ ] **Step 1: Add Chinese labels**

Add this constant to `UserOpsSettings`:

```php
private const LABELS = [
    'invite_default_max_uses' => '默认邀请码可用次数',
    'invite_default_expires_days' => '默认邀请码有效天数',
    'password_reset_expires_minutes' => '找回密码有效分钟数',
    'risk_invite_burst_threshold' => '邀请集中注册阈值',
    'risk_invite_burst_window_hours' => '邀请集中注册窗口小时数',
    'risk_activation_failure_threshold' => '激活码失败阈值',
    'risk_activation_failure_window_minutes' => '激活码失败窗口分钟数',
    'withdrawal_min_amount' => '最小提现金额',
    'withdrawal_max_amount' => '最大提现金额',
];
```

Add:

```php
private function label(string $key): string
{
    return self::LABELS[$key] ?? $key;
}
```

- [ ] **Step 2: Localize validation messages**

Change:

```php
throw new InvalidArgumentException('Withdrawal max amount must be zero or greater than min amount.');
```

to:

```php
throw new InvalidArgumentException('最大提现金额必须为 0，或大于等于最小提现金额。');
```

Change integer validation exceptions to:

```php
throw new InvalidArgumentException("{$this->label($key)}必须是整数。");
throw new InvalidArgumentException("{$this->label($key)}必须在 {$min} 到 {$max} 之间。");
```

Change money validation exception to:

```php
throw new InvalidArgumentException("{$this->label($key)}必须是非负金额。");
```

- [ ] **Step 3: Localize withdrawal service min/max messages if needed**

If focused tests still fail on withdrawal policy messages, change `app/User/WithdrawalService.php` messages:

```php
throw new InvalidArgumentException("提现金额不能低于 {$minAmount}。");
throw new InvalidArgumentException("提现金额不能高于 {$maxAmount}。");
```

- [ ] **Step 4: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserOpsSettingsTest"
```

Expected: PASS.

## Task 3: Verification, Review, Commit, Push

**Files:**
- Review all P16 changes.

- [ ] **Step 1: PHP syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/UserOpsSettings.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/WithdrawalService.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserOpsSettingsTest.php
```

- [ ] **Step 2: Full tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Review diff**

Run:

```powershell
git diff --check
git diff --stat
git diff -- app/User/UserOpsSettings.php app/User/WithdrawalService.php tests/Feature/User/UserOpsSettingsTest.php docs/superpowers/plans/2026-07-07-p16-user-ops-settings-chinese-errors.md
```

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p16-user-ops-settings-chinese-errors.md app/User/UserOpsSettings.php app/User/WithdrawalService.php tests/Feature/User/UserOpsSettingsTest.php
git commit -m "fix: localize user ops settings validation"
git push origin main
```

Expected: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers admin settings validation JSON, direct service validation, and withdrawal min/max policy messages.
- Placeholder scan: no placeholders remain.
- Scope check: no setting keys, default values, persistence behavior, or money movement rules change.
