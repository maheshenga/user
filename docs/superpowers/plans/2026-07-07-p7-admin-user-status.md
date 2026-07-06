# P7 Admin User Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow administrators to change user account status while keeping all other user-account write actions read-only.

**Architecture:** Reuse the existing EasyAdmin `modify` endpoint shape for table inline updates, but whitelist only the `status` field and only the known `UserAccountStatus` values. This makes admin disable/freeze actions work with the P5 stale-session guard without opening broad account editing.

**Tech Stack:** Laravel controller, Eloquent `UserAccount`, existing `UserAccountStatus`, PHPUnit feature tests, SQLite test runner.

---

## File Structure

- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
  - Change the inherited-write-action test so `modify status` is no longer expected to be rejected.
  - Add tests for allowed status changes.
  - Add tests proving non-status fields and invalid status values are rejected.
- Modify: `app/Http/Controllers/admin/user/AccountController.php`
  - Import `App\User\UserAccountStatus`.
  - Add `#[NodeAnnotation(title: '修改状态', auth: true)]` to `modify()`.
  - Implement status-only update.
  - Keep `add`, `edit`, `delete`, `recycle`, and `export` read-only.
- Modify: `docs/superpowers/plans/2026-07-07-p7-admin-user-status.md`
  - This plan and review evidence.

---

## Task 1: RED Tests For Admin Status Changes

**Files:**
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Add status import**

Add:

```php
use App\User\UserAccountStatus;
```

- [ ] **Step 2: Add allowed status change test**

Add before `test_admin_user_account_controller_rejects_inherited_write_actions()`:

```php
public function test_admin_user_account_modify_allows_status_updates_only(): void
{
    $user = UserAccount::query()->create([
        'mobile' => '13800138012',
        'email' => 'status-update@example.com',
        'password' => 'secret123',
        'nickname' => 'Status User',
        'status' => UserAccountStatus::ACTIVE,
    ]);

    $this->postJson('/admin/user/account/modify', [
        'id' => $user->id,
        'field' => 'status',
        'value' => UserAccountStatus::DISABLED,
    ])->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('msg', '保存成功');

    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'status' => UserAccountStatus::DISABLED,
    ]);

    $this->postJson('/admin/user/account/modify', [
        'id' => $user->id,
        'field' => 'status',
        'value' => UserAccountStatus::ACTIVE,
    ])->assertOk()
        ->assertJsonPath('code', 1);

    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'status' => UserAccountStatus::ACTIVE,
    ]);
}
```

- [ ] **Step 3: Add guard tests**

Add:

```php
public function test_admin_user_account_modify_rejects_non_status_fields_and_invalid_statuses(): void
{
    $user = UserAccount::query()->create([
        'mobile' => '13800138013',
        'email' => 'status-guard@example.com',
        'password' => 'secret123',
        'nickname' => 'Guarded Name',
        'status' => UserAccountStatus::ACTIVE,
    ]);

    $this->postJson('/admin/user/account/modify', [
        'id' => $user->id,
        'field' => 'nickname',
        'value' => 'Changed Name',
    ])->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '用户账号管理仅允许修改账号状态。');

    $this->postJson('/admin/user/account/modify', [
        'id' => $user->id,
        'field' => 'status',
        'value' => 'archived',
    ])->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '账号状态值无效。');

    $this->assertDatabaseHas('user_account', [
        'id' => $user->id,
        'nickname' => 'Guarded Name',
        'status' => UserAccountStatus::ACTIVE,
    ]);
}
```

- [ ] **Step 4: Update inherited-write-action test**

Remove this row from `test_admin_user_account_controller_rejects_inherited_write_actions()`:

```php
['postJson', '/admin/user/account/modify', ['id' => $user->id, 'field' => 'status', 'value' => 'disabled']],
```

Keep assertions that add/edit/delete/recycle/export remain blocked.

- [ ] **Step 5: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php --filter "modify_allows_status|modify_rejects_non_status|inherited_write"
```

Expected: FAIL because `modify()` currently returns read-only errors for status changes.

---

## Task 2: Implement Status-Only Modify

**Files:**
- Modify: `app/Http/Controllers/admin/user/AccountController.php`

- [ ] **Step 1: Add import**

Add:

```php
use App\User\UserAccountStatus;
```

- [ ] **Step 2: Replace `modify()` implementation**

Replace:

```php
public function modify(): JsonResponse
{
    return $this->readOnlyError();
}
```

with:

```php
#[NodeAnnotation(title: '修改状态', auth: true)]
public function modify(): JsonResponse
{
    if (! request()->ajax() && ! request()->expectsJson()) {
        return $this->error();
    }

    $id = (int) request()->post('id', 0);
    $field = (string) request()->post('field', '');
    $value = (string) request()->post('value', '');

    if ($id <= 0 || $field === '' || $value === '') {
        return $this->error('ID、字段和值不能为空');
    }

    if ($field !== 'status') {
        return $this->error('用户账号管理仅允许修改账号状态。');
    }

    if (! in_array($value, $this->allowedStatuses(), true)) {
        return $this->error('账号状态值无效。');
    }

    $user = UserAccount::query()->find($id);

    if ($user === null) {
        return $this->error('用户不存在');
    }

    $user->forceFill([
        'status' => $value,
        'update_time' => time(),
    ])->save();

    return $this->success('保存成功');
}
```

- [ ] **Step 3: Add helper**

Add near the other private helpers:

```php
private function allowedStatuses(): array
{
    return [
        UserAccountStatus::PENDING,
        UserAccountStatus::ACTIVE,
        UserAccountStatus::DISABLED,
        UserAccountStatus::FROZEN,
    ];
}
```

- [ ] **Step 4: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php --filter "modify_allows_status|modify_rejects_non_status|inherited_write"
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\AccountController.php
```

Expected: PASS.

---

## Task 3: Verify P5/P7 Security Loop

**Files:**
- Test only.

- [ ] **Step 1: Run account and stale-session tests together**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php tests\Feature\User\UserPortalFlowHardeningTest.php
```

Expected: PASS. This proves admin status changes and user stale-session rejection both remain covered.

- [ ] **Step 2: Run admin ops smoke/visibility tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminSmokeScriptTest.php tests\Feature\User\UserOpsVisibilityTest.php
```

Expected: PASS.

---

## Task 4: Full Verification, Review, Commit, Push

**Files:**
- Review: `app/Http/Controllers/admin/user/AccountController.php`
- Review: `tests/Feature/User/UserAdminAccountControllerTest.php`
- Review: this plan

- [ ] **Step 1: Run full SQLite suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 2: Run static and diff checks**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app\Http\Controllers\admin\user\AccountController.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserAdminAccountControllerTest.php
git diff --check
git diff --stat
git diff
```

Expected: clean review with no unrelated changes.

- [ ] **Step 3: Commit and push**

```powershell
git add docs/superpowers/plans/2026-07-07-p7-admin-user-status.md app/Http/Controllers/admin/user/AccountController.php tests/Feature/User/UserAdminAccountControllerTest.php
git commit -m "feat: allow admin user status updates"
git push origin main
```

---

## Self-Review

- Spec coverage: Enables admin status changes and keeps all other account writes blocked.
- Placeholder scan: No TODO, TBD, or incomplete sections.
- Type consistency: Allowed statuses match `UserAccountStatus` constants and P5 login/session guard.
- Scope guard: No profile editing, password reset, balance editing, deletion, or account creation is introduced.
