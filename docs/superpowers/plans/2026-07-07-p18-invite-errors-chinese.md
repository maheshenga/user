# P18 Invite Errors Chinese Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This session executes inline without subagents per user instruction.

**Goal:** Localize invitation mechanism errors to Chinese so registration with invalid, disabled, expired, exhausted, self, duplicate, or circular invite relationships does not expose English service messages.

**Architecture:** Keep `App\User\InviteService` public methods, transaction boundaries, relation creation, invite-code counters, and response structures unchanged. Change only user-facing exception strings and tests that assert those messages. Add endpoint coverage for invalid invitation code returned through `/user/register`.

**Tech Stack:** PHP 8.3, Laravel feature tests, SQLite test runner, existing `UserInviteTest` and `UserAuthService` registration flow.

## Global Constraints

- Do not change invite-code generation format or collision loop behavior.
- Do not change invite relation hierarchy, two-level distribution data, or risk evaluation behavior.
- Do not change API response shape.
- Preserve storage status values such as `active`, `disabled`, and relation status `active`.
- Execute directly in this session; do not dispatch subagents.

---

### Task 1: RED tests for Chinese invite service and endpoint errors

**Files:**
- Modify: `tests/Feature/User/UserInviteTest.php`

**Interfaces:**
- Consumes: `App\User\UserAuthService::register(array $payload, string $ip): array`
- Consumes: `POST /user/register`
- Produces: failing expectations for Chinese invite error strings.

- [ ] **Step 1: Update service invalid invite matrix expectations**

In `test_invalid_disabled_expired_and_exhausted_invite_codes_are_rejected`, replace the matrix with:

```php
foreach ([
    [fn (): string => 'missing-code', '邀请码无效。'],
    [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['status' => 'disabled']), '邀请码未启用。'],
    [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['expires_at' => Carbon::now()->subMinute()]), '邀请码已过期。'],
    [fn (): string => $this->mutatedCode($owner['invite_code']['id'], ['max_uses' => 1, 'used_count' => 1]), '邀请码使用次数已达上限。'],
] as $index => [$codeFactory, $message]) {
```

- [ ] **Step 2: Add endpoint assertion for invalid invitation code**

Append to `test_register_endpoint_accepts_invite_code_and_user_can_query_invites` after the successful registration assertions:

```php
$invalidRegisterResponse = $this->postJson('/user/register', [
    'mobile' => '13900000022',
    'password' => 'secret123',
    'invite_code' => 'missing-code',
]);

$invalidRegisterResponse->assertOk()
    ->assertJsonPath('code', 0)
    ->assertJsonPath('msg', '邀请码无效。');
```

- [ ] **Step 3: Add self-invite, duplicate relation, and circular relation assertions**

Add a new test method to `UserInviteTest`:

```php
public function test_self_duplicate_and_circular_invite_relations_return_chinese_errors(): void
{
    $auth = app(UserAuthService::class);

    $parent = $auth->register([
        'mobile' => '13900000030',
        'password' => 'secret123',
    ], '127.0.0.1');

    try {
        app(InviteService::class)->bindRegistration(
            UserAccount::query()->findOrFail($parent['user']['id']),
            $parent['invite_code']['code']
        );
        $this->fail('Expected self invite to fail.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('不能邀请自己。', $exception->getMessage());
    }

    $child = $auth->register([
        'mobile' => '13900000031',
        'password' => 'secret123',
        'invite_code' => $parent['invite_code']['code'],
    ], '127.0.0.1');

    try {
        app(InviteService::class)->bindRegistration(
            UserAccount::query()->findOrFail($child['user']['id']),
            $parent['invite_code']['code']
        );
        $this->fail('Expected duplicate invite relation to fail.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('邀请关系已存在。', $exception->getMessage());
    }

    $childRelation = UserInviteRelation::query()
        ->where('user_id', $child['user']['id'])
        ->firstOrFail();
    $childRelation->forceFill([
        'level_path' => $child['user']['id'].'/'.$parent['user']['id'],
    ])->save();

    try {
        app(InviteService::class)->bindRegistration(
            UserAccount::query()->findOrFail($parent['user']['id']),
            $child['invite_code']['code']
        );
        $this->fail('Expected circular invite relation to fail.');
    } catch (InvalidArgumentException $exception) {
        $this->assertSame('邀请关系不能形成循环。', $exception->getMessage());
    }
}
```

- [ ] **Step 4: Run RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserInviteTest"
```

Expected result: FAIL because `InviteService` still returns English invite errors.

### Task 2: GREEN implementation in InviteService

**Files:**
- Modify: `app/User/InviteService.php`

**Interfaces:**
- Keeps `createDefaultCode`, `bindRegistration`, `inviteSummary`, and `inviteRecords` signatures unchanged.
- Produces Chinese `InvalidArgumentException` messages.

- [ ] **Step 1: Replace invite service messages**

Use these exact replacements:

```text
Unable to generate invite code. => 无法生成邀请码。
Invite code is invalid. => 邀请码无效。
User cannot invite self. => 不能邀请自己。
Invite relation already exists. => 邀请关系已存在。
Invite relation cannot be circular. => 邀请关系不能形成循环。
Invite code is not active. => 邀请码未启用。
Invite code is expired. => 邀请码已过期。
Invite code usage limit reached. => 邀请码使用次数已达上限。
```

- [ ] **Step 2: Run focused GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserInviteTest"
```

Expected result: PASS.

### Task 3: Verification, review, commit, push

**Files:**
- Review all P18 changes.

**Interfaces:**
- Produces a pushed commit on `origin/main`.

- [ ] **Step 1: Syntax checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/InviteService.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserInviteTest.php
```

- [ ] **Step 2: Full test suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

- [ ] **Step 3: Diff review**

Run:

```powershell
git diff --check
git diff --stat
git diff -- app/User/InviteService.php tests/Feature/User/UserInviteTest.php docs/superpowers/plans/2026-07-07-p18-invite-errors-chinese.md
```

- [ ] **Step 4: Commit and push**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p18-invite-errors-chinese.md app/User/InviteService.php tests/Feature/User/UserInviteTest.php
git commit -m "fix: localize invite validation"
git push origin main
```

Expected result: push succeeds to `origin/main`.

## Self-review

- Spec coverage: covers invalid invite code, disabled code, expired code, exhausted code, self-invite, duplicate relation, circular relation, and registration endpoint error propagation.
- Placeholder scan: no placeholders, TODOs, or vague implementation steps remain.
- Scope check: message strings and tests only; no invite lifecycle, hierarchy, risk, or distribution behavior changes.
