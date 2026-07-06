# P4 User Entry UX Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the user login, registration, password recovery, and password reset pages visibly clearer for manual SaaS testing without changing backend business rules.

**Architecture:** Keep the existing Blade + vanilla JavaScript portal. Add small semantic auth-page containers, Chinese helper copy, form-specific loading text, and button label restoration through the existing `setFormBusy()` helper.

**Tech Stack:** Laravel Blade, vanilla JavaScript, PHPUnit feature tests, Node JavaScript syntax check.

---

## File Structure

- Modify: `tests/Feature/User/UserPortalPageTest.php`
  - Add assertions for entry-page Chinese guidance, stable `auth-card` UI hook, and form loading text hooks.
  - Add a static JavaScript assertion that busy buttons use `data-loading-text` and restore original labels.
- Modify: `resources/views/user/portal/layout.blade.php`
  - Add restrained `.auth-shell`, `.auth-card`, `.auth-intro`, `.form-tip`, and `.form-actions` styles.
- Modify: `resources/views/user/portal/login.blade.php`
  - Add a concise Chinese intro and `data-loading-text="登录中..."`.
- Modify: `resources/views/user/portal/register.blade.php`
  - Add a concise Chinese intro, field helper text, and `data-loading-text="注册中..."`.
- Modify: `resources/views/user/portal/forgot-password.blade.php`
  - Add a concise Chinese intro and `data-loading-text="发送中..."`.
- Modify: `resources/views/user/portal/reset-password.blade.php`
  - Add a concise Chinese intro, field helper text, and `data-loading-text="重置中..."`.
- Modify: `public/static/user/js/portal.js`
  - Update `setFormBusy()` so submit buttons show the page-specific loading text while disabled and restore their original labels afterward.

---

## Task 1: RED Tests For Entry Page UX Hooks

**Files:**
- Modify: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Add failing page assertions**

Add a new test:

```php
public function test_auth_entry_pages_render_clear_chinese_guidance_and_loading_hooks(): void
{
    $this->get('/u/login')
        ->assertOk()
        ->assertSee('class="auth-card"', false)
        ->assertSee('登录后查看 VIP、余额、邀请和提现进度。')
        ->assertSee('data-loading-text="登录中..."', false);

    $this->get('/u/register')
        ->assertOk()
        ->assertSee('创建用户账号后会自动生成邀请码。')
        ->assertSee('手机号和邮箱至少填写一项。')
        ->assertSee('邀请码可选，用于绑定邀请关系。')
        ->assertSee('data-loading-text="注册中..."', false);

    $this->get('/u/forgot-password')
        ->assertOk()
        ->assertSee('提交账号后，系统会生成可用于重置密码的记录。')
        ->assertSee('data-loading-text="发送中..."', false);

    $this->get('/u/reset-password')
        ->assertOk()
        ->assertSee('输入账号、新密码，并填写重置令牌或验证码。')
        ->assertSee('令牌和验证码至少填写一项。')
        ->assertSee('data-loading-text="重置中..."', false);
}
```

Add a JavaScript static test:

```php
public function test_portal_forms_show_and_restore_loading_button_labels(): void
{
    $script = file_get_contents(public_path('static/user/js/portal.js'));

    $this->assertStringContainsString('button.dataset.originalText', $script);
    $this->assertStringContainsString('form.dataset.loadingText', $script);
    $this->assertStringContainsString('button.textContent = button.dataset.originalText', $script);
}
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php --filter "auth_entry_pages|portal_forms_show"
```

Expected: FAIL because the new copy and loading-label JS are not implemented yet.

---

## Task 2: Implement Entry Page Copy And Styles

**Files:**
- Modify: `resources/views/user/portal/layout.blade.php`
- Modify: `resources/views/user/portal/login.blade.php`
- Modify: `resources/views/user/portal/register.blade.php`
- Modify: `resources/views/user/portal/forgot-password.blade.php`
- Modify: `resources/views/user/portal/reset-password.blade.php`

- [ ] **Step 1: Add focused auth styles**

In `layout.blade.php`, add these styles before `.panel`:

```css
.auth-shell {
    max-width: 520px;
}
.auth-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 16px;
}
.auth-intro {
    margin: -6px 0 18px;
    color: var(--muted);
}
.form-tip {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 400;
}
.form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
button[disabled] {
    cursor: not-allowed;
    opacity: 0.7;
}
```

- [ ] **Step 2: Update login page**

Wrap content with:

```blade
<div class="auth-shell">
    <h1 class="page-title">登录</h1>
    <section class="auth-card">
        <p class="auth-intro">登录后查看 VIP、余额、邀请和提现进度。</p>
        <form data-portal-form data-endpoint="/user/login" data-success-redirect="/u/dashboard" data-loading-text="登录中...">
            ...
            <div class="form-actions">
                <button type="submit">登录</button>
                <a href="/u/forgot-password">找回密码</a>
            </div>
        </form>
        <p class="muted">还没有账号？<a href="/u/register">立即注册</a>。</p>
    </section>
</div>
```

- [ ] **Step 3: Update register page**

Add intro, helper text, and `data-loading-text="注册中..."`:

```blade
<p class="auth-intro">创建用户账号后会自动生成邀请码。</p>
<small class="form-tip">手机号和邮箱至少填写一项。</small>
<small class="form-tip">邀请码可选，用于绑定邀请关系。</small>
```

- [ ] **Step 4: Update password pages**

For forgot password add:

```blade
<p class="auth-intro">提交账号后，系统会生成可用于重置密码的记录。</p>
```

For reset password add:

```blade
<p class="auth-intro">输入账号、新密码，并填写重置令牌或验证码。</p>
<small class="form-tip">令牌和验证码至少填写一项。</small>
```

- [ ] **Step 5: Verify page test is still failing only on JS if applicable**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php --filter "auth_entry_pages|portal_forms_show"
```

Expected: Page-copy assertions pass; JavaScript loading-label assertions may still fail.

---

## Task 3: Implement Loading Button Labels

**Files:**
- Modify: `public/static/user/js/portal.js`

- [ ] **Step 1: Update `setFormBusy()`**

Replace the current helper with:

```js
function setFormBusy(form, busy) {
    form.querySelectorAll('button, input[type="submit"]').forEach((element) => {
        if (element.tagName === 'BUTTON') {
            if (!element.dataset.originalText) {
                element.dataset.originalText = element.textContent;
            }

            element.textContent = busy
                ? (form.dataset.loadingText || element.dataset.originalText)
                : element.dataset.originalText;
        }

        element.disabled = busy;
    });
}
```

- [ ] **Step 2: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php --filter "auth_entry_pages|portal_forms_show"
node --check public\static\user\js\portal.js
```

Expected: PASS.

---

## Task 4: Full Verification, Review, Commit

**Files:**
- Review all changed files.

- [ ] **Step 1: Run focused user portal tests**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalSmokeScriptTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full SQLite suite**

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run static and diff checks**

```powershell
node --check public\static\user\js\portal.js
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserPortalPageTest.php
git diff --check
git diff --stat
git diff
```

Expected: clean review with no unrelated changes.

- [ ] **Step 4: Commit**

```powershell
git add docs/superpowers/plans/2026-07-06-p4-user-entry-ux-polish.md resources/views/user/portal/layout.blade.php resources/views/user/portal/login.blade.php resources/views/user/portal/register.blade.php resources/views/user/portal/forgot-password.blade.php resources/views/user/portal/reset-password.blade.php public/static/user/js/portal.js tests/Feature/User/UserPortalPageTest.php
git commit -m "feat: polish user entry pages"
```

---

## Self-Review

- Spec coverage: Addresses visible entry-page clarity, Chinese guidance, loading feedback, tests, review, and commit.
- Placeholder scan: No TODO, TBD, or incomplete sections.
- Type consistency: `data-loading-text` is read from `form.dataset.loadingText`; `auth-card` is asserted in the Blade output.
- Scope guard: No API contracts, migrations, admin pages, payment flows, or business rules are changed.
