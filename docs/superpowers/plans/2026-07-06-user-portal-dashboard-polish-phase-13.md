# User Portal Dashboard Polish Phase 13 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace raw dashboard JSON output with readable user-facing summaries while keeping existing user APIs unchanged.

**Architecture:** Add stable semantic hooks to the existing Blade dashboard and extend the existing vanilla `portal.js` with small rendering helpers for known dashboard payloads. Keep a raw JSON fallback for unknown payloads so future API changes remain inspectable.

**Tech Stack:** PHP 8.3, Laravel 13, Blade, vanilla JavaScript, PHPUnit 12, SQLite test runner, existing P12 HTTP smoke script.

---

## File Structure

- Modify `resources/views/user/portal/dashboard.blade.php`
  - Add stable `data-dashboard-render="<name>"` hooks inside each panel.
  - Keep existing endpoint hooks and protected controls unchanged.
- Modify `public/static/user/js/portal.js`
  - Add HTML escaping, field formatting, list/table render helpers, known renderers, and raw fallback.
  - Continue using the existing `request()`, `loadBox()`, and session preflight structure.
- Modify `tests/Feature/User/UserPortalPageTest.php`
  - Add assertions for semantic dashboard rendering hooks.
- Modify `tests/Feature/User/UserPortalSmokeScriptTest.php` only if the smoke fixture needs new dashboard hook coverage.
  - Prefer no change unless needed.

---

## Task 1: Dashboard Semantic Hooks

**Files:**

- Modify: `tests/Feature/User/UserPortalPageTest.php`
- Modify: `resources/views/user/portal/dashboard.blade.php`

- [ ] **Step 1: Write failing page test assertions**

In `tests/Feature/User/UserPortalPageTest.php`, extend `test_dashboard_renders_existing_user_api_endpoint_hooks()` with:

```php
->assertSee('data-dashboard-render="vip"', false)
->assertSee('data-dashboard-render="balance"', false)
->assertSee('data-dashboard-render="ledger"', false)
->assertSee('data-dashboard-render="invite"', false)
->assertSee('data-dashboard-render="inviteRecords"', false)
->assertSee('data-dashboard-render="withdrawals"', false)
```

- [ ] **Step 2: Verify RED**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php --filter dashboard_renders_existing_user_api_endpoint_hooks
```

Expected: FAIL because the new render hooks do not exist yet.

- [ ] **Step 3: Add semantic hooks to Blade**

In `resources/views/user/portal/dashboard.blade.php`, update each data box:

```html
<div class="data-box" data-dashboard-box="vip" data-dashboard-render="vip">Waiting for VIP summary.</div>
<div class="data-box" data-dashboard-box="balance" data-dashboard-render="balance">Waiting for balance summary.</div>
<div class="data-box" data-dashboard-box="ledger" data-dashboard-render="ledger">Waiting for ledger.</div>
<div class="data-box" data-dashboard-box="invite" data-dashboard-render="invite">Waiting for invite summary.</div>
<div class="data-box" data-dashboard-box="inviteRecords" data-dashboard-render="inviteRecords">Waiting for invite records.</div>
<div class="data-box" data-dashboard-box="withdrawals" data-dashboard-render="withdrawals">Waiting for withdrawals.</div>
```

- [ ] **Step 4: Verify GREEN**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```powershell
git add tests/Feature/User/UserPortalPageTest.php resources/views/user/portal/dashboard.blade.php
git commit -m "feat: add user dashboard render hooks"
```

---

## Task 2: Friendly Dashboard Renderers

**Files:**

- Modify: `public/static/user/js/portal.js`

- [ ] **Step 1: Add small rendering helpers**

Add these helpers after `pretty()`:

```js
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function valueOrDash(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    return escapeHtml(value);
}

function row(label, value) {
    return `<div class="summary-row"><span>${escapeHtml(label)}</span><strong>${valueOrDash(value)}</strong></div>`;
}

function rawFallback(data) {
    return `<details class="raw-payload"><summary>Raw data</summary><pre>${escapeHtml(pretty(data))}</pre></details>`;
}
```

- [ ] **Step 2: Add list rendering helper**

Add after `rawFallback()`:

```js
function renderList(items, emptyText, renderer) {
    if (!Array.isArray(items) || items.length === 0) {
        return `<div class="empty-state">${escapeHtml(emptyText)}</div>`;
    }

    return `<div class="summary-list">${items.map(renderer).join('')}</div>`;
}
```

- [ ] **Step 3: Add known renderers**

Add after `renderList()`:

```js
const renderers = {
    vip(data) {
        const user = data.user || data.vip || data;
        return [
            row('VIP Level', user.vip_level ?? user.level),
            row('Status', user.vip_status ?? user.status),
            row('Started At', user.vip_started_at ?? user.started_at),
            row('Expired At', user.vip_expired_at ?? user.expired_at),
            rawFallback(data),
        ].join('');
    },
    balance(data) {
        return [
            row('Available', data.available_balance ?? data.available),
            row('Frozen', data.frozen_balance ?? data.frozen),
            row('Total Earned', data.total_earned),
            row('Total Withdrawn', data.total_withdrawn),
            rawFallback(data),
        ].join('');
    },
    ledger(data) {
        const rows = data.rows || data.list || data.ledger || [];
        return renderList(rows, 'No balance ledger records.', (item) => [
            '<article class="summary-item">',
            row('Amount', item.amount),
            row('Type', item.type ?? item.direction),
            row('Reason', item.reason ?? item.remark),
            row('Time', item.create_time ?? item.created_at),
            '</article>',
        ].join('')) + rawFallback(data);
    },
    invite(data) {
        const code = data.invite_code || data.default_code || data.code || {};
        return [
            row('Invite Code', code.code ?? data.code),
            row('Invite URL', code.url ?? data.invite_url),
            row('Level 1 Total', data.level1_total ?? data.first_level_total),
            row('Level 2 Total', data.level2_total ?? data.second_level_total),
            rawFallback(data),
        ].join('');
    },
    inviteRecords(data) {
        const rows = data.rows || data.list || data.records || [];
        return renderList(rows, 'No invite records.', (item) => [
            '<article class="summary-item">',
            row('User', item.email ?? item.mobile ?? item.nickname ?? item.user_id),
            row('Level', item.level),
            row('Registered At', item.registered_at ?? item.create_time),
            '</article>',
        ].join('')) + rawFallback(data);
    },
    withdrawals(data) {
        const rows = data.rows || data.list || data.withdrawals || [];
        return renderList(rows, 'No withdrawal records.', (item) => [
            '<article class="summary-item">',
            row('Amount', item.amount),
            row('Status', item.status),
            row('Account', item.account_no ?? item.account?.account_no),
            row('Requested At', item.create_time ?? item.created_at),
            '</article>',
        ].join('')) + rawFallback(data);
    },
};
```

- [ ] **Step 4: Update `loadBox()` to use renderers**

Replace the current success assignment:

```js
box.textContent = `${result.msg || ''}\n${pretty(result.data)}`.trim();
```

with:

```js
if (Number(result.code) !== 1) {
    box.textContent = result.msg || 'Request failed.';
    return;
}

const rendererName = box.dataset.dashboardRender || name;
const renderer = renderers[rendererName];
if (renderer) {
    box.innerHTML = renderer(result.data || {});
    return;
}

box.textContent = `${result.msg || ''}\n${pretty(result.data)}`.trim();
```

- [ ] **Step 5: Verify JavaScript syntax**

Run:

```powershell
node --check public\static\user\js\portal.js
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```powershell
git add public/static/user/js/portal.js
git commit -m "feat: render readable user dashboard summaries"
```

---

## Task 3: Verification And Review

**Files:**

- Review: `resources/views/user/portal/dashboard.blade.php`
- Review: `public/static/user/js/portal.js`
- Review: `tests/Feature/User/UserPortalPageTest.php`

- [ ] **Step 1: Run focused user portal tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\UserPortalPageTest.php tests\Feature\User\UserPortalFlowHardeningTest.php tests\Feature\User\UserPortalSmokeScriptTest.php
```

Expected: PASS.

- [ ] **Step 2: Run full SQLite suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Run static checks**

Run:

```powershell
node --check public\static\user\js\portal.js
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\UserPortalPageTest.php
git diff --check
```

Expected: clean.

- [ ] **Step 4: Run real Laravel HTTP smoke**

Use the P12 direct PHP built-in server pattern with SQLite:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts\user-portal-smoke.php --base-url=http://127.0.0.1:<port>
```

Expected: `OK user portal smoke passed`.

- [ ] **Step 5: Request reviews**

Dispatch:

- Spec compliance reviewer for `docs/superpowers/specs/2026-07-06-user-portal-dashboard-polish-design.md`.
- Code quality reviewer for the full P13 diff.

Fix Critical/Important findings and re-review.

- [ ] **Step 6: Commit review checkpoint**

Run:

```powershell
git commit --allow-empty -m "chore: review user portal dashboard polish phase"
```

---

## Finalization

- Merge to `main`.
- Re-run focused tests, full SQLite suite, static checks, and real HTTP smoke on merged `main`.
- Push `main` to `origin`.
- Continue to the next priority P.

---

## Plan Self-Review

- Spec coverage: dashboard semantic hooks, readable renderers, empty states, fallback raw payloads, activation/withdrawal refresh preservation, tests, real smoke, review, merge, and push are covered.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: render hook names match existing `loadBox()` names and endpoint map keys.
- Scope guard: no backend business rules, admin changes, new routes, or new dependencies are included.
