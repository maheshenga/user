# Module Admin Review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an internal administrator approval gate before first module installation.

**Architecture:** Reuse `system_module.status` for review states and `system_module_log` for audit history. Keep review logic in `ModuleRepository` and lifecycle enforcement in `ModuleInstaller`; expose two small admin controller actions and row buttons.

**Tech Stack:** Laravel 13, Eloquent, Blade/Layui, PHPUnit through `composer run test:sqlite`.

---

## File Structure

- Modify `app/Modules/ModuleRepository.php`
  - New modules discovered by manifest become `pending_review`.
  - Add `approve()` and `reject()` helpers.
- Modify `app/Modules/ModuleInstaller.php`
  - Reject first-time install unless module status is `approved`.
- Modify `app/Http/Controllers/admin/system/ModuleController.php`
  - Add POST-only `approve` and `reject` actions.
- Modify `public/static/admin/js/system/module.js`
  - Add approve/reject row actions.
- Modify `tests/Feature/Modules/ModuleLifecycleTest.php`
  - Cover install approval gate at service level.
- Modify `tests/Feature/Modules/ModuleCenterControllerTest.php`
  - Cover admin approve/reject actions.
- Modify `docs/modules/phase-2-module-center.md`
  - Document administrator review gate.

---

### Task 1: Repository Review States

**Files:**
- Modify: `app/Modules/ModuleRepository.php`
- Test: `tests/Feature/Modules/ModuleLifecycleTest.php`

- [ ] **Step 1: Write failing discovery status test**

Add to `tests/Feature/Modules/ModuleLifecycleTest.php`:

```php
public function test_discovered_module_starts_pending_review(): void
{
    Config::set('modules.path', base_path('tests/Fixtures/modules'));

    app(\App\Modules\ModuleManager::class)->discover();
    app(\App\Modules\ModuleRepository::class)->upsertDiscovered(
        app(\App\Modules\ModuleManager::class)->manifest('blog')
    );

    $this->assertDatabaseHas('system_module', [
        'name' => 'blog',
        'status' => 'pending_review',
    ]);
}
```

- [ ] **Step 2: Run red test**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleLifecycleTest.php --filter pending_review
```

Expected: fail because discovery still creates `discovered`.

- [ ] **Step 3: Change new discovery status**

In `ModuleRepository::upsertDiscovered()`, change the fallback status:

```php
'status' => SystemModule::query()->where('name', $manifest->name())->value('status') ?: 'pending_review',
```

- [ ] **Step 4: Add approve/reject helpers**

Add to `ModuleRepository`:

```php
public function approve(string $name, ?int $actorId = null): void
{
    $module = SystemModule::query()->where('name', $name)->firstOrFail();
    $oldState = (string) $module->status;
    if (! in_array($oldState, ['pending_review', 'rejected'], true)) {
        throw new \InvalidArgumentException("Module [{$name}] cannot be approved from status [{$oldState}]");
    }

    $module->update(['status' => 'approved', 'last_error' => null, 'update_time' => time()]);
    $this->log('approve', $name, $oldState, 'approved', 'success', null, $actorId);
}

public function reject(string $name, string $reason, ?int $actorId = null): void
{
    $module = SystemModule::query()->where('name', $name)->firstOrFail();
    $oldState = (string) $module->status;
    if (! in_array($oldState, ['pending_review', 'approved'], true)) {
        throw new \InvalidArgumentException("Module [{$name}] cannot be rejected from status [{$oldState}]");
    }

    $module->update(['status' => 'rejected', 'last_error' => $reason, 'update_time' => time()]);
    $this->log('reject', $name, $oldState, 'rejected', 'success', $reason, $actorId);
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleLifecycleTest.php --filter pending_review
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/ModuleRepository.php tests/Feature/Modules/ModuleLifecycleTest.php
git commit -m "feat: add module admin review states"
```

---

### Task 2: Install Approval Gate

**Files:**
- Modify: `app/Modules/ModuleInstaller.php`
- Test: `tests/Feature/Modules/ModuleLifecycleTest.php`

- [ ] **Step 1: Write failing install gate tests**

Add to `ModuleLifecycleTest`:

```php
public function test_install_requires_admin_approval_for_first_install(): void
{
    Config::set('modules.path', base_path('tests/Fixtures/modules'));
    app(\App\Modules\ModuleRepository::class)->upsertDiscovered(
        app(\App\Modules\ModuleManager::class)->manifest('blog')
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must be approved before install');

    app(\App\Modules\ModuleInstaller::class)->install('blog');
}

public function test_approved_module_can_be_installed(): void
{
    Config::set('modules.path', base_path('tests/Fixtures/modules'));
    $repository = app(\App\Modules\ModuleRepository::class);
    $repository->upsertDiscovered(app(\App\Modules\ModuleManager::class)->manifest('blog'));
    $repository->approve('blog', 1);

    app(\App\Modules\ModuleInstaller::class)->install('blog', 1);

    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'installed']);
}
```

- [ ] **Step 2: Run red tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleLifecycleTest.php --filter "requires_admin_approval|approved_module"
```

Expected: first test fails because install still allows pending review.

- [ ] **Step 3: Add install status guard**

In `ModuleInstaller::install()`, after `$current` is loaded:

```php
if ($current !== null && $current->status === 'pending_review') {
    throw new InvalidArgumentException("Module [{$name}] must be approved before install.");
}
if ($current !== null && $current->status === 'rejected') {
    throw new InvalidArgumentException("Module [{$name}] must be approved before install.");
}
```

Keep existing reinstall behavior for `installed`, `enabled`, and `disabled`.

- [ ] **Step 4: Run tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleLifecycleTest.php --filter "requires_admin_approval|approved_module"
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/ModuleInstaller.php tests/Feature/Modules/ModuleLifecycleTest.php
git commit -m "fix: require admin approval before module install"
```

---

### Task 3: Admin Review Controller and UI

**Files:**
- Modify: `app/Http/Controllers/admin/system/ModuleController.php`
- Modify: `public/static/admin/js/system/module.js`
- Test: `tests/Feature/Modules/ModuleCenterControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Add to `ModuleCenterControllerTest`:

```php
public function test_admin_can_approve_pending_module(): void
{
    $this->createBlogModule(['status' => 'pending_review']);

    $response = $this->postJson('/admin/system/module/approve', ['name' => 'blog']);

    $response->assertOk()->assertJsonPath('code', 1);
    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'approved']);
    $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'approve', 'result' => 'success']);
}

public function test_admin_can_reject_pending_module_with_reason(): void
{
    $this->createBlogModule(['status' => 'pending_review']);

    $response = $this->postJson('/admin/system/module/reject', ['name' => 'blog', 'reason' => 'manual review failed']);

    $response->assertOk()->assertJsonPath('code', 1);
    $this->assertDatabaseHas('system_module', [
        'name' => 'blog',
        'status' => 'rejected',
        'last_error' => 'manual review failed',
    ]);
    $this->assertDatabaseHas('system_module_log', ['module' => 'blog', 'action' => 'reject', 'error_message' => 'manual review failed']);
}

public function test_review_actions_reject_get_requests(): void
{
    $this->createBlogModule(['status' => 'pending_review']);

    $response = $this->getJson('/admin/system/module/approve?name=blog');

    $response->assertOk()->assertJsonPath('msg', 'Lifecycle actions require POST.');
    $this->assertDatabaseHas('system_module', ['name' => 'blog', 'status' => 'pending_review']);
}
```

- [ ] **Step 2: Run red tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleCenterControllerTest.php --filter "approve|reject|review_actions"
```

Expected: fail because actions do not exist.

- [ ] **Step 3: Add controller actions**

Add to `ModuleController`:

```php
#[NodeAnnotation(title: '瀹℃牳閫氳繃', auth: true)]
public function approve(): Response|JsonResponse|View
{
    return $this->runLifecycleAction(fn () => app(ModuleRepository::class)->approve($this->moduleName(), $this->actorId()));
}

#[NodeAnnotation(title: '瀹℃牳鎷掔粷', auth: true)]
public function reject(): Response|JsonResponse|View
{
    return $this->runLifecycleAction(function (): void {
        $reason = trim((string) request()->input('reason', 'Rejected by administrator.'));
        app(ModuleRepository::class)->reject($this->moduleName(), $reason === '' ? 'Rejected by administrator.' : $reason, $this->actorId());
    });
}
```

- [ ] **Step 4: Add UI actions**

In `public/static/admin/js/system/module.js`, add row action buttons for review states:

```js
if (row.status === 'pending_review' || row.status === 'rejected') {
    actions.push('<a class="layui-btn layui-btn-xs" lay-event="approve">Approve</a>');
}
if (row.status === 'pending_review' || row.status === 'approved') {
    actions.push('<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="reject">Reject</a>');
}
```

Handle events:

```js
if (obj.event === 'approve') {
    ea.request.post({url: 'approve', data: {name: data.name}, prefix: true}, function () {
        table.reload('currentTable');
    });
}
if (obj.event === 'reject') {
    layer.prompt({title: 'Reject reason'}, function (value, index) {
        layer.close(index);
        ea.request.post({url: 'reject', data: {name: data.name, reason: value}, prefix: true}, function () {
            table.reload('currentTable');
        });
    });
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/Modules/ModuleCenterControllerTest.php --filter "approve|reject|review_actions"
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/admin/system/ModuleController.php public/static/admin/js/system/module.js tests/Feature/Modules/ModuleCenterControllerTest.php
git commit -m "feat: add module admin review actions"
```

---

### Task 4: Documentation and Verification

**Files:**
- Modify: `docs/modules/phase-2-module-center.md`

- [ ] **Step 1: Update operator docs**

Add this section to `docs/modules/phase-2-module-center.md`:

```markdown
## Administrator Review

Newly discovered modules enter `pending_review`.

An administrator must approve the module before first install. Rejected modules cannot be installed until approved again. Review decisions are recorded in module logs with actions `approve` and `reject`.
```

- [ ] **Step 2: Run full tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add docs/modules/phase-2-module-center.md
git commit -m "docs: document module admin review"
```

---

## Self-Review Checklist

- Spec coverage:
  - New modules require admin approval before install: Tasks 1 and 2.
  - Admin approve/reject actions: Task 3.
  - Logs contain review actions and reject reason: Tasks 1 and 3.
  - UI exposes approve/reject: Task 3.
  - Docs explain operator workflow: Task 4.
- Out of scope:
  - No third-party marketplace review.
  - No signatures.
  - No dedicated review table.
  - No multi-reviewer workflow.
- Test command:
  - `E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite`
