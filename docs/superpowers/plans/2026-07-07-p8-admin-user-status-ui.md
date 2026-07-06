# P8 Admin User Status UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the P7 user status management capability visible and discoverable on the admin user account list page.

**Architecture:** Keep the existing EasyAdmin table shell and backend `modify` endpoint. Add a compact status-operation panel and a status template hook to the Blade page so administrators can see the supported statuses and the endpoint contract without enabling broad editing.

**Tech Stack:** Laravel Blade, PHPUnit feature tests, existing admin user account controller.

---

## File Structure

- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
  - Add assertions that the account list page exposes status-management UI hooks and supported status values.
- Modify: `resources/views/admin/user/account/index.blade.php`
  - Add a compact panel explaining allowed status changes.
  - Add data attributes for `modify` endpoint and supported status values.
  - Add a small Layui template for status labels.
- Modify: `docs/superpowers/plans/2026-07-07-p8-admin-user-status-ui.md`
  - This plan and review evidence.

---

## Task 1: RED Test For Visible Status Management UI

**Files:**
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Add page UI test**

Add after `test_admin_user_account_detail_renders_user()`:

```php
public function test_admin_user_account_index_exposes_status_management_ui_hooks(): void
{
    $response = $this->get('/admin/user/account/index');

    $response->assertOk();
    $response->assertSee('账号状态管理');
    $response->assertSee('data-status-endpoint="/admin/user/account/modify"', false);
    $response->assertSee('data-status-values="pending,active,disabled,frozen"', false);
    $response->assertSee('待审核');
    $response->assertSee('正常');
    $response->assertSee('已禁用');
    $response->assertSee('已冻结');
    $response->assertSee('id="userStatusTpl"', false);
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php --filter "status_management_ui_hooks"
```

Expected: FAIL because the list page does not expose these hooks yet.

---

## Task 2: Add Status UI Hooks To The Account List Page

**Files:**
- Modify: `resources/views/admin/user/account/index.blade.php`

- [ ] **Step 1: Add status operation panel**

Inside `.layuimini-main`, before the table, add:

```blade
<div class="layui-card" data-user-status-admin
     data-status-endpoint="{{ __url('user/account/modify') }}"
     data-status-values="pending,active,disabled,frozen">
    <div class="layui-card-header">账号状态管理</div>
    <div class="layui-card-body">
        <span class="layui-badge layui-bg-gray">待审核 pending</span>
        <span class="layui-badge layui-bg-green">正常 active</span>
        <span class="layui-badge">已禁用 disabled</span>
        <span class="layui-badge layui-bg-orange">已冻结 frozen</span>
        <p class="layui-font-12" style="margin-top: 8px;">
            仅支持通过列表操作修改账号状态；其他账号资料保持只读。
        </p>
    </div>
</div>
```

- [ ] **Step 2: Add status template hook**

After the table, add:

```blade
<script type="text/html" id="userStatusTpl">
    @{{#  if(d.status === 'active'){ }}
    <span class="layui-badge layui-bg-green">正常</span>
    @{{#  } else if(d.status === 'disabled'){ }}
    <span class="layui-badge">已禁用</span>
    @{{#  } else if(d.status === 'frozen'){ }}
    <span class="layui-badge layui-bg-orange">已冻结</span>
    @{{#  } else { }}
    <span class="layui-badge layui-bg-gray">待审核</span>
    @{{#  } }}
</script>
```

- [ ] **Step 3: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php --filter "status_management_ui_hooks"
```

Expected: PASS.

---

## Task 3: Verification And Review

**Files:**
- Review: `resources/views/admin/user/account/index.blade.php`
- Review: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Run account controller tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserAdminAccountControllerTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full SQLite suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run diff checks**

```powershell
git diff --check
git diff --stat
git diff
```

Expected: clean review with no unrelated changes.

- [ ] **Step 4: Commit and push**

```powershell
git add docs/superpowers/plans/2026-07-07-p8-admin-user-status-ui.md resources/views/admin/user/account/index.blade.php tests/Feature/User/UserAdminAccountControllerTest.php
git commit -m "feat: expose admin user status controls"
git push origin main
```

---

## Self-Review

- Spec coverage: Makes account status management visible on the admin account list page.
- Placeholder scan: No TODO, TBD, or incomplete sections.
- Type consistency: Status values match `UserAccountStatus` constants and P7 backend whitelist.
- Scope guard: Does not add account editing, deletion, password changes, balance changes, or new routes.
