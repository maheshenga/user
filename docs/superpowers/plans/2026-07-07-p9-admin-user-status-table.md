# P9 Admin User Status Table Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect the admin user account status controls to the real EasyAdmin table so administrators can update account status from the list page without enabling general account editing.

**Architecture:** Keep the backend status-only `modify()` contract from P7 unchanged. Add a conservative table interaction in `public/static/admin/js/user/account.js` that renders localized status badges, shows allowed status transition buttons, posts only `{id, field: "status", value}` to the existing status endpoint, and reloads the table after success.

**Tech Stack:** Laravel feature tests, Blade, EasyAdmin/Layui AMD JavaScript, jQuery.

---

### Files

- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`
  - Add tests that assert the account page exposes modify authorization metadata and the account table JavaScript contains the status template, endpoint lookup, status action buttons, and the status-only request payload.
- Modify: `resources/views/admin/user/account/index.blade.php`
  - Add `data-auth-modify="{{auths('user/account/modify')}}"` on the table so the custom JavaScript can hide status actions for admins without modify permission.
- Modify: `public/static/admin/js/user/account.js`
  - Add `modify_url`, status labels, HTML escaping, endpoint resolution from `data-status-endpoint`, permission check from `data-auth-modify`, status action rendering, click handler, and `templet: '#userStatusTpl'` for the status column.

---

### Task 1: RED Tests

**Files:**
- Modify: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Add the failing Blade metadata assertion**

Add this assertion to `test_admin_user_account_index_exposes_status_management_ui_hooks()` after the existing `data-auth-detail`/endpoint assertions:

```php
$response->assertSee('data-auth-modify="1"', false);
```

- [ ] **Step 2: Add the failing JavaScript contract test**

Add this test method after `test_admin_user_account_index_exposes_status_management_ui_hooks()`:

```php
public function test_admin_user_account_js_wires_status_table_actions(): void
{
    $script = file_get_contents(public_path('static/admin/js/user/account.js'));

    $this->assertIsString($script);
    $this->assertStringContainsString("modify_url: 'user/account/modify'", $script);
    $this->assertStringContainsString("templet: '#userStatusTpl'", $script);
    $this->assertStringContainsString("data-status-endpoint", $script);
    $this->assertStringContainsString("data-auth-modify", $script);
    $this->assertStringContainsString("data-account-status", $script);
    $this->assertStringContainsString("field: 'status'", $script);
    $this->assertStringContainsString("value: status", $script);
    $this->assertStringContainsString("ea.table.reload(init.table_render_id)", $script);
    $this->assertStringNotContainsString("edit_url: 'user/account/edit'", $script);
    $this->assertStringNotContainsString("delete_url: 'user/account/delete'", $script);
}
```

- [ ] **Step 3: Run the focused test to verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe artisan test --filter=UserAdminAccountControllerTest
```

Expected: FAIL because the page does not yet expose `data-auth-modify="1"` and `account.js` does not yet contain the P9 status action wiring.

---

### Task 2: GREEN Implementation

**Files:**
- Modify: `resources/views/admin/user/account/index.blade.php`
- Modify: `public/static/admin/js/user/account.js`

- [ ] **Step 1: Add modify auth metadata to the account table**

Change the account table opening tag to include:

```blade
data-auth-modify="{{auths('user/account/modify')}}"
```

The resulting table metadata should keep `data-auth-detail` and add `data-auth-modify`.

- [ ] **Step 2: Add status helpers to `account.js`**

Add these helpers after `detailUrl()`:

```javascript
var statusLabels = {
    pending: '待审核',
    active: '正常',
    disabled: '已禁用',
    frozen: '已冻结'
};

function escapeAttr(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char];
    });
}

function statusEndpoint() {
    return $('[data-user-status-admin]').data('status-endpoint') || ea.url(init.modify_url);
}

function canModifyStatus() {
    return CONFIG.IS_SUPER_ADMIN || $(init.table_elem).attr('data-auth-modify') === '1';
}

function statusActions(row) {
    if (!canModifyStatus()) {
        return '';
    }

    var id = escapeAttr(row.id);
    var currentStatus = row.status || 'pending';
    var actions = [];

    $.each(statusLabels, function (status, label) {
        if (status === currentStatus) {
            return;
        }

        actions.push(
            '<a class="layui-btn layui-btn-primary layui-btn-xs" data-account-status="' + status + '" data-account-id="' + id + '">' + label + '</a>'
        );
    });

    return actions.join(' ');
}
```

- [ ] **Step 3: Wire status column and operation column**

Update `init` to include:

```javascript
modify_url: 'user/account/modify'
```

Update the status column to:

```javascript
{field: 'status', width: 110, title: '状态', search: 'select', selectList: statusLabels, templet: '#userStatusTpl'}
```

Update the operation column width and template so it appends `statusActions(d)` after the existing detail button.

- [ ] **Step 4: Wire the status click handler**

Add this listener after `ea.table.render(...)`:

```javascript
$('body').on('click', '[data-account-status]', function () {
    var status = $(this).data('account-status');
    var id = $(this).data('account-id');
    var label = statusLabels[status] || status;

    ea.msg.confirm('确认将账号状态改为「' + label + '」？', function () {
        ea.request.post({
            url: statusEndpoint(),
            data: {
                id: id,
                field: 'status',
                value: status
            }
        }, function () {
            ea.table.reload(init.table_render_id);
        });
    });
});
```

- [ ] **Step 5: Run focused tests to verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe artisan test --filter=UserAdminAccountControllerTest
```

Expected: PASS.

---

### Task 3: Verification, Review, Commit, Push

**Files:**
- Review all modified files.

- [ ] **Step 1: Static-check the JavaScript**

Run:

```powershell
node --check public/static/admin/js/user/account.js
```

Expected: no syntax errors.

- [ ] **Step 2: Run the full SQLite test suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Review diff and whitespace**

Run:

```powershell
git diff --check
git diff --stat
git diff -- public/static/admin/js/user/account.js resources/views/admin/user/account/index.blade.php tests/Feature/User/UserAdminAccountControllerTest.php
```

Expected: `git diff --check` exits 0 and diff scope is limited to this P9 task plus the plan file.

- [ ] **Step 4: Commit**

Run:

```powershell
git add docs/superpowers/plans/2026-07-07-p9-admin-user-status-table.md resources/views/admin/user/account/index.blade.php public/static/admin/js/user/account.js tests/Feature/User/UserAdminAccountControllerTest.php
git commit -m "feat: wire admin user status table actions"
```

- [ ] **Step 5: Push**

Run:

```powershell
git push origin main
```

Expected: push succeeds to `origin/main`.

---

### Self-Review

- Spec coverage: P9 covers visible status badges, real list-table action buttons, backend status-only payload, permission metadata, endpoint reuse, tests, review, commit, and push.
- Placeholder scan: no placeholder steps remain.
- Scope check: this plan does not enable account add/edit/delete/export and does not change the backend status whitelist.
