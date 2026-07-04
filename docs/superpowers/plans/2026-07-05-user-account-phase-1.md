# User Account Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first ordinary user account layer with phone/email registration, password login, logout, login logs, and a read-only admin user list/detail surface independent from `system_admin`.

**Architecture:** Keep the existing EasyAdmin8 admin authentication untouched and add a separate user-domain boundary under `App\User`. User-facing endpoints are explicit Laravel routes outside the admin dynamic route group, while admin management follows existing EasyAdmin controller/model/view conventions.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12, Eloquent, existing EasyAdmin8 `BaseModel`, existing `JumpTrait`, SQLite test runner through `composer run test:sqlite`.

---

## Scope

This plan implements Phase 1 from `docs/superpowers/specs/2026-07-05-user-vip-affiliate-auth-design.md`.

Included:
- User account tables and models.
- Phone or email registration, with at least one contact method required.
- Password login by phone or email.
- Logout for the current user session.
- Login success and failure logs.
- Basic user status checks.
- Admin list/detail for ordinary users.
- Automated tests for the above.

Excluded from Phase 1:
- Invitation binding.
- Password reset.
- VIP plans and VIP records.
- Activation code redemption.
- Affiliate commission.
- Balance ledger.
- Withdrawals.

---

## File Structure

- Create `database/migrations/2026_07_05_000001_create_user_account_phase_1_tables.php`: creates `user_account`, `user_profile`, and `user_login_log`.
- Create `app/Models/UserAccount.php`: EasyAdmin-style model for `user_account`.
- Create `app/Models/UserProfile.php`: model for optional user profile data.
- Create `app/Models/UserLoginLog.php`: model for login attempts.
- Create `app/User/UserAccountStatus.php`: status constants and helpers.
- Create `app/User/UserAuthService.php`: register, login, logout, and log-writing service.
- Create `app/Http/Controllers/user/AuthController.php`: user-facing JSON auth endpoints.
- Create `app/Http/Controllers/admin/user/AccountController.php`: admin read-only list/detail for ordinary users.
- Create `resources/views/admin/user/account/index.blade.php`: admin table shell.
- Create `resources/views/admin/user/account/detail.blade.php`: admin detail view.
- Create `public/static/admin/js/user/account.js`: admin table configuration.
- Modify `routes/web.php`: add explicit `/user/*` routes before the admin dynamic route group.
- Create `tests/Feature/User/UserAuthTest.php`: registration, login, logout, and log tests.
- Create `tests/Feature/User/UserAdminAccountControllerTest.php`: admin list/detail tests.

### Shared Interfaces

```php
namespace App\User;

final class UserAccountStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const FROZEN = 'frozen';

    public static function canLogin(string $status): bool;
}

final class UserAuthService
{
    /** @return array<string, mixed> */
    public function register(array $payload, string $ip): array;

    /** @return array<string, mixed> */
    public function login(array $payload, string $ip): array;

    public function logout(): void;
}
```

---

## Task 1: Persistence and Models

**Files:**
- Create: `database/migrations/2026_07_05_000001_create_user_account_phase_1_tables.php`
- Create: `app/Models/UserAccount.php`
- Create: `app/Models/UserProfile.php`
- Create: `app/Models/UserLoginLog.php`
- Test: `tests/Feature/User/UserAuthTest.php`

- [ ] **Step 1: Write failing schema/model tests**

Create `tests/Feature/User/UserAuthTest.php` with the initial schema test:

```php
<?php

namespace Tests\Feature\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use App\Models\UserProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createSystemConfigTable();
    }

    public function test_user_account_phase_1_tables_exist_and_models_query(): void
    {
        $this->assertTrue(Schema::hasTable('user_account'));
        $this->assertTrue(Schema::hasTable('user_profile'));
        $this->assertTrue(Schema::hasTable('user_login_log'));

        $this->assertTrue(Schema::hasColumns('user_account', [
            'mobile',
            'email',
            'password',
            'status',
            'available_balance',
            'frozen_balance',
            'vip_level',
            'vip_expires_at',
            'delete_time',
        ]));

        $this->assertSame(0, UserAccount::query()->count());
        $this->assertSame(0, UserProfile::query()->count());
        $this->assertSame(0, UserLoginLog::query()->count());
    }

    private function createSystemConfigTable(): void
    {
        Schema::create('system_config', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 80);
            $table->string('name', 120);
            $table->text('value')->nullable();
        });

        \DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => 'testing'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin Test'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'wangEditor'],
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter tables_exist
```

Expected: FAIL because `user_account`, `user_profile`, and `user_login_log` do not exist.

- [ ] **Step 3: Create migration**

Create `database/migrations/2026_07_05_000001_create_user_account_phase_1_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account', function (Blueprint $table): void {
            $table->id();
            $table->string('mobile', 32)->nullable()->unique();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('email', 180)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('nickname', 120)->default('');
            $table->string('avatar', 500)->default('');
            $table->string('status', 32)->default('active')->index();
            $table->string('register_channel', 80)->default('');
            $table->string('register_ip', 45)->default('');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->default('');
            $table->decimal('available_balance', 12, 2)->default(0);
            $table->decimal('frozen_balance', 12, 2)->default(0);
            $table->unsignedInteger('vip_level')->default(0);
            $table->timestamp('vip_expires_at')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });

        Schema::create('user_profile', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('real_name', 120)->default('');
            $table->string('company', 180)->default('');
            $table->string('country', 80)->default('');
            $table->string('province', 80)->default('');
            $table->string('city', 80)->default('');
            $table->json('metadata_json')->nullable();
            $table->unsignedBigInteger('create_time')->nullable();
            $table->unsignedBigInteger('update_time')->nullable();
            $table->unsignedBigInteger('delete_time')->nullable();
        });

        Schema::create('user_login_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('account', 180)->default('');
            $table->string('login_type', 32)->default('');
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 500)->default('');
            $table->string('result', 32)->index();
            $table->string('error_message', 500)->default('');
            $table->unsignedBigInteger('create_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_log');
        Schema::dropIfExists('user_profile');
        Schema::dropIfExists('user_account');
    }
};
```

- [ ] **Step 4: Create models**

Create `app/Models/UserAccount.php`:

```php
<?php

namespace App\Models;

class UserAccount extends BaseModel
{
    protected $table = 'user_account';

    protected $hidden = ['password'];

    protected $casts = [
        'mobile_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'vip_expires_at' => 'datetime',
        'available_balance' => 'decimal:2',
        'frozen_balance' => 'decimal:2',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
```

Create `app/Models/UserProfile.php`:

```php
<?php

namespace App\Models;

class UserProfile extends BaseModel
{
    protected $table = 'user_profile';

    protected $casts = [
        'metadata_json' => 'array',
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'update_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
        'delete_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
```

Create `app/Models/UserLoginLog.php`:

```php
<?php

namespace App\Models;

class UserLoginLog extends BaseModel
{
    protected $table = 'user_login_log';

    public static function bootSoftDeletes() {}

    protected $casts = [
        'create_time' => 'App\Casts\CarbonDate:Y-m-d H:i:s',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter tables_exist
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_05_000001_create_user_account_phase_1_tables.php app/Models/UserAccount.php app/Models/UserProfile.php app/Models/UserLoginLog.php tests/Feature/User/UserAuthTest.php
git commit -m "feat: add user account persistence"
```

---

## Task 2: User Registration Service

**Files:**
- Create: `app/User/UserAccountStatus.php`
- Create: `app/User/UserAuthService.php`
- Modify: `tests/Feature/User/UserAuthTest.php`

- [ ] **Step 1: Add failing registration tests**

Append these tests to `tests/Feature/User/UserAuthTest.php`:

```php
public function test_user_can_register_with_mobile_only(): void
{
    $result = app(\App\User\UserAuthService::class)->register([
        'mobile' => '13800000001',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->assertSame('13800000001', $result['user']['mobile']);
    $this->assertSame('active', $result['user']['status']);
    $this->assertDatabaseHas('user_account', [
        'mobile' => '13800000001',
        'email' => null,
        'status' => 'active',
        'register_ip' => '127.0.0.1',
    ]);
}

public function test_user_can_register_with_email_only(): void
{
    $result = app(\App\User\UserAuthService::class)->register([
        'email' => 'person@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->assertSame('person@example.com', $result['user']['email']);
    $this->assertDatabaseHas('user_account', [
        'email' => 'person@example.com',
        'status' => 'active',
    ]);
}

public function test_register_requires_mobile_or_email(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Mobile or email is required.');

    app(\App\User\UserAuthService::class)->register([
        'password' => 'secret123',
    ], '127.0.0.1');
}

public function test_register_rejects_duplicate_mobile_or_email(): void
{
    $service = app(\App\User\UserAuthService::class);
    $service->register([
        'mobile' => '13800000002',
        'email' => 'dup@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Mobile already exists.');

    $service->register([
        'mobile' => '13800000002',
        'email' => 'other@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter register
```

Expected: FAIL because `App\User\UserAuthService` does not exist.

- [ ] **Step 3: Create status constants**

Create `app/User/UserAccountStatus.php`:

```php
<?php

namespace App\User;

final class UserAccountStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const FROZEN = 'frozen';

    public static function canLogin(string $status): bool
    {
        return $status === self::ACTIVE;
    }
}
```

- [ ] **Step 4: Implement registration service**

Create `app/User/UserAuthService.php`:

```php
<?php

namespace App\User;

use App\Models\UserAccount;
use App\Models\UserLoginLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class UserAuthService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(array $payload, string $ip): array
    {
        $mobile = $this->normalizeNullableString($payload['mobile'] ?? null);
        $email = $this->normalizeEmail($payload['email'] ?? null);
        $password = (string) ($payload['password'] ?? '');

        if ($mobile === null && $email === null) {
            throw new InvalidArgumentException('Mobile or email is required.');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Password must be at least 6 characters.');
        }
        if ($mobile !== null && UserAccount::query()->where('mobile', $mobile)->exists()) {
            throw new InvalidArgumentException('Mobile already exists.');
        }
        if ($email !== null && UserAccount::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('Email already exists.');
        }

        $user = DB::transaction(function () use ($mobile, $email, $password, $ip): UserAccount {
            $now = time();

            return UserAccount::query()->create([
                'mobile' => $mobile,
                'email' => $email,
                'password' => Hash::make($password),
                'nickname' => $mobile ?? (string) $email,
                'status' => UserAccountStatus::ACTIVE,
                'register_ip' => $ip,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        });

        return ['user' => $this->publicUser($user)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function login(array $payload, string $ip): array
    {
        throw new \BadMethodCallException('Login is implemented in Task 3.');
    }

    public function logout(): void
    {
        session()->forget('user');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = $this->normalizeNullableString($value);

        return $value === null ? null : strtolower($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUser(UserAccount $user): array
    {
        return [
            'id' => (int) $user->id,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'nickname' => $user->nickname,
            'status' => $user->status,
        ];
    }
}
```

- [ ] **Step 5: Run registration tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter register
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/User/UserAccountStatus.php app/User/UserAuthService.php tests/Feature/User/UserAuthTest.php
git commit -m "feat: add user registration service"
```

---

## Task 3: Login, Logout, and Login Logs

**Files:**
- Modify: `app/User/UserAuthService.php`
- Modify: `tests/Feature/User/UserAuthTest.php`

- [ ] **Step 1: Add failing login/logout tests**

Append these tests to `tests/Feature/User/UserAuthTest.php`:

```php
public function test_user_can_login_with_mobile_and_logout(): void
{
    $service = app(\App\User\UserAuthService::class);
    $service->register([
        'mobile' => '13800000003',
        'password' => 'secret123',
    ], '127.0.0.1');

    $result = $service->login([
        'account' => '13800000003',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->assertSame('13800000003', $result['user']['mobile']);
    $this->assertSame($result['user']['id'], session('user.id'));
    $this->assertDatabaseHas('user_login_log', [
        'user_id' => $result['user']['id'],
        'account' => '13800000003',
        'login_type' => 'mobile',
        'result' => 'success',
    ]);

    $service->logout();
    $this->assertNull(session('user'));
}

public function test_user_can_login_with_email(): void
{
    $service = app(\App\User\UserAuthService::class);
    $service->register([
        'email' => 'login@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $result = $service->login([
        'account' => 'LOGIN@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->assertSame('login@example.com', $result['user']['email']);
    $this->assertDatabaseHas('user_login_log', [
        'account' => 'login@example.com',
        'login_type' => 'email',
        'result' => 'success',
    ]);
}

public function test_login_rejects_wrong_password_and_logs_failure(): void
{
    $service = app(\App\User\UserAuthService::class);
    $service->register([
        'mobile' => '13800000004',
        'password' => 'secret123',
    ], '127.0.0.1');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid account or password.');

    try {
        $service->login([
            'account' => '13800000004',
            'password' => 'badpass',
        ], '127.0.0.1');
    } finally {
        $this->assertDatabaseHas('user_login_log', [
            'account' => '13800000004',
            'login_type' => 'mobile',
            'result' => 'failed',
            'error_message' => 'Invalid account or password.',
        ]);
    }
}

public function test_disabled_user_cannot_login(): void
{
    $service = app(\App\User\UserAuthService::class);
    $service->register([
        'email' => 'disabled@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');
    \App\Models\UserAccount::query()->where('email', 'disabled@example.com')->update(['status' => 'disabled']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('User account is not active.');

    $service->login([
        'account' => 'disabled@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter "login|logout|disabled"
```

Expected: FAIL because `login()` throws `BadMethodCallException`.

- [ ] **Step 3: Replace login implementation**

Replace the `login()` method in `app/User/UserAuthService.php` with:

```php
/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
public function login(array $payload, string $ip): array
{
    $account = $this->normalizeNullableString($payload['account'] ?? null);
    $password = (string) ($payload['password'] ?? '');
    if ($account === null || $password === '') {
        throw new InvalidArgumentException('Account and password are required.');
    }

    [$loginType, $normalizedAccount] = $this->loginTypeAndAccount($account);
    $query = UserAccount::query();
    $user = $loginType === 'email'
        ? $query->where('email', $normalizedAccount)->first()
        : $query->where('mobile', $normalizedAccount)->first();

    if ($user === null || ! Hash::check($password, (string) $user->password)) {
        $this->writeLoginLog(null, $normalizedAccount, $loginType, $ip, 'failed', 'Invalid account or password.');
        throw new InvalidArgumentException('Invalid account or password.');
    }

    if (! UserAccountStatus::canLogin((string) $user->status)) {
        $this->writeLoginLog($user, $normalizedAccount, $loginType, $ip, 'failed', 'User account is not active.');
        throw new InvalidArgumentException('User account is not active.');
    }

    $now = now();
    $user->update([
        'last_login_at' => $now,
        'last_login_ip' => $ip,
        'update_time' => time(),
    ]);

    $this->writeLoginLog($user, $normalizedAccount, $loginType, $ip, 'success', '');

    $publicUser = $this->publicUser($user->refresh());
    session(['user' => $publicUser]);

    return ['user' => $publicUser];
}
```

Add these private helpers to `UserAuthService`:

```php
/**
 * @return array{0:string,1:string}
 */
private function loginTypeAndAccount(string $account): array
{
    if (str_contains($account, '@')) {
        return ['email', strtolower($account)];
    }

    return ['mobile', $account];
}

private function writeLoginLog(?UserAccount $user, string $account, string $loginType, string $ip, string $result, string $error): void
{
    UserLoginLog::query()->create([
        'user_id' => $user?->id,
        'account' => $account,
        'login_type' => $loginType,
        'ip' => $ip,
        'user_agent' => substr((string) request()->userAgent(), 0, 500),
        'result' => $result,
        'error_message' => $error,
        'create_time' => time(),
    ]);
}
```

- [ ] **Step 4: Run login/logout tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter "login|logout|disabled"
```

Expected: PASS.

- [ ] **Step 5: Run full user auth test**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/User/UserAuthService.php tests/Feature/User/UserAuthTest.php
git commit -m "feat: add user login and login logs"
```

---

## Task 4: User-Facing Auth Routes and Controller

**Files:**
- Create: `app/Http/Controllers/user/AuthController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/User/UserAuthTest.php`

- [ ] **Step 1: Add failing HTTP endpoint tests**

Append these tests to `tests/Feature/User/UserAuthTest.php`:

```php
public function test_register_endpoint_returns_user_payload(): void
{
    $response = $this->postJson('/user/register', [
        'mobile' => '13800000005',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.user.mobile', '13800000005');
}

public function test_login_endpoint_sets_user_session(): void
{
    app(\App\User\UserAuthService::class)->register([
        'email' => 'endpoint@example.com',
        'password' => 'secret123',
    ], '127.0.0.1');

    $response = $this->postJson('/user/login', [
        'account' => 'endpoint@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.user.email', 'endpoint@example.com');
    $this->assertSame('endpoint@example.com', session('user.email'));
}

public function test_logout_endpoint_clears_user_session(): void
{
    $this->withSession(['user' => ['id' => 10, 'email' => 'old@example.com']]);

    $response = $this->postJson('/user/logout');

    $response->assertOk()->assertJsonPath('code', 1);
    $this->assertNull(session('user'));
}
```

- [ ] **Step 2: Run endpoint tests to verify they fail**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter endpoint
```

Expected: FAIL with 404 for `/user/register` or `/user/login`.

- [ ] **Step 3: Create controller**

Create `app/Http/Controllers/user/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Http\JumpTrait;
use App\User\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AuthController extends Controller
{
    use JumpTrait;

    public function register(UserAuthService $auth): JsonResponse
    {
        $payload = request()->only(['mobile', 'email', 'password']);
        $validator = Validator::make($payload, [
            'mobile' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:180',
            'password' => 'required|string|min:6|max:72',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('注册成功', $auth->register($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function login(UserAuthService $auth): JsonResponse
    {
        $payload = request()->only(['account', 'password']);
        $validator = Validator::make($payload, [
            'account' => 'required|string|max:180',
            'password' => 'required|string|max:72',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('登录成功', $auth->login($payload, request()->ip()));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function logout(UserAuthService $auth): JsonResponse
    {
        $auth->logout();

        return $this->success('退出登录成功');
    }
}
```

- [ ] **Step 4: Add routes**

Modify `routes/web.php`, adding these explicit user routes after the installer route and before module asset/admin routes:

```php
Route::prefix('user')->group(function(): void {
    Route::post('/register', [\App\Http\Controllers\user\AuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\user\AuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\user\AuthController::class, 'logout']);
});
```

- [ ] **Step 5: Run endpoint tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php --filter endpoint
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/user/AuthController.php routes/web.php tests/Feature/User/UserAuthTest.php
git commit -m "feat: add user auth endpoints"
```

---

## Task 5: Admin User Account List and Detail

**Files:**
- Create: `app/Http/Controllers/admin/user/AccountController.php`
- Create: `resources/views/admin/user/account/index.blade.php`
- Create: `resources/views/admin/user/account/detail.blade.php`
- Create: `public/static/admin/js/user/account.js`
- Create: `tests/Feature/User/UserAdminAccountControllerTest.php`

- [ ] **Step 1: Write failing admin tests**

Create `tests/Feature/User/UserAdminAccountControllerTest.php`:

```php
<?php

namespace Tests\Feature\User;

use App\Http\Middleware\CheckInstall;
use App\Http\Middleware\RateLimiting;
use App\Models\UserAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesModuleTestSchema;
use Tests\TestCase;

class UserAdminAccountControllerTest extends TestCase
{
    use CreatesModuleTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            CheckInstall::class,
            RateLimiting::class,
        ]);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->createEasyAdminHostTables();
        $this->createSystemConfigTable();
        $this->withSession(['admin.id' => 1, 'admin.expire_time' => true]);
    }

    public function test_admin_user_account_index_returns_rows(): void
    {
        UserAccount::query()->create([
            'mobile' => '13800000006',
            'email' => 'admin-list@example.com',
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'nickname' => 'Admin List User',
            'status' => 'active',
            'create_time' => time(),
        ]);

        $response = $this->getJson('/admin/user/account/index');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.mobile', '13800000006')
            ->assertJsonPath('data.0.email', 'admin-list@example.com');
    }

    public function test_admin_user_account_detail_renders_user(): void
    {
        $user = UserAccount::query()->create([
            'mobile' => '13800000007',
            'email' => 'admin-detail@example.com',
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'nickname' => 'Detail User',
            'status' => 'active',
            'create_time' => time(),
        ]);

        $response = $this->get('/admin/user/account/detail?id='.$user->id);

        $response->assertOk()
            ->assertSee('admin-detail@example.com')
            ->assertSee('Detail User');
    }

    private function createSystemConfigTable(): void
    {
        Schema::create('system_config', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 80);
            $table->string('name', 120);
            $table->text('value')->nullable();
        });

        \DB::table('system_config')->insert([
            ['group' => 'site', 'name' => 'site_version', 'value' => 'testing'],
            ['group' => 'site', 'name' => 'site_name', 'value' => 'EasyAdmin Test'],
            ['group' => 'site', 'name' => 'site_ico', 'value' => ''],
            ['group' => 'site', 'name' => 'editor_type', 'value' => 'wangEditor'],
        ]);
    }
}
```

- [ ] **Step 2: Run admin tests to verify they fail**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAdminAccountControllerTest.php
```

Expected: FAIL with 404 or missing `AccountController`.

- [ ] **Step 3: Create admin controller**

Create `app/Http/Controllers/admin/user/AccountController.php`:

```php
<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: '用户账号')]
class AccountController extends AdminController
{
    public function initialize()
    {
        parent::initialize();
        $this->model = new UserAccount();
    }

    #[NodeAnnotation(title: '列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where] = $this->buildTableParams();
        $query = UserAccount::query()->where($where);

        return json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => $query
                ->orderBy($this->order, $this->orderDirection)
                ->paginate($limit, ['*'], 'page', (int) $page)
                ->items(),
        ]);
    }

    #[NodeAnnotation(title: '详情', auth: true)]
    public function detail(): View|JsonResponse
    {
        $id = (int) request()->input('id', 0);
        $user = UserAccount::query()->find($id);
        if ($user === null) {
            return $this->error('用户不存在');
        }

        return $this->fetch('', ['user' => $user]);
    }
}
```

- [ ] **Step 4: Create admin views**

Create `resources/views/admin/user/account/index.blade.php`:

```php
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide" lay-filter="currentTable"></table>
    </div>
</div>
@include('admin.layout.foot')
```

Create `resources/views/admin/user/account/detail.blade.php`:

```php
@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table class="layui-table">
            <tbody>
            <tr><th width="160">ID</th><td>{{ $user->id }}</td></tr>
            <tr><th>手机号</th><td>{{ $user->mobile }}</td></tr>
            <tr><th>邮箱</th><td>{{ $user->email }}</td></tr>
            <tr><th>昵称</th><td>{{ $user->nickname }}</td></tr>
            <tr><th>状态</th><td>{{ $user->status }}</td></tr>
            <tr><th>注册 IP</th><td>{{ $user->register_ip }}</td></tr>
            <tr><th>最后登录 IP</th><td>{{ $user->last_login_ip }}</td></tr>
            <tr><th>可用余额</th><td>{{ $user->available_balance }}</td></tr>
            <tr><th>冻结余额</th><td>{{ $user->frozen_balance }}</td></tr>
            <tr><th>VIP 等级</th><td>{{ $user->vip_level }}</td></tr>
            <tr><th>VIP 到期时间</th><td>{{ $user->vip_expires_at }}</td></tr>
            </tbody>
        </table>
    </div>
</div>
@include('admin.layout.foot')
```

- [ ] **Step 5: Create admin JS**

Create `public/static/admin/js/user/account.js`:

```javascript
define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        table_elem: '#currentTable',
        table_render_id: 'currentTableRenderId',
        index_url: 'user/account/index',
        detail_url: 'user/account/detail'
    };

    return {
        index: function () {
            ea.table.render({
                init: init,
                cols: [[
                    {field: 'id', width: 80, title: 'ID', search: false},
                    {field: 'mobile', minWidth: 140, title: '手机号'},
                    {field: 'email', minWidth: 180, title: '邮箱'},
                    {field: 'nickname', minWidth: 140, title: '昵称'},
                    {field: 'status', width: 110, title: '状态', search: 'select', selectList: {
                        pending: 'pending',
                        active: 'active',
                        disabled: 'disabled',
                        frozen: 'frozen'
                    }},
                    {field: 'vip_level', width: 110, title: 'VIP等级', search: false},
                    {field: 'available_balance', width: 120, title: '可用余额', search: false},
                    {field: 'last_login_at', minWidth: 160, title: '最后登录', search: false},
                    {
                        width: 100,
                        title: '操作',
                        search: false,
                        templet: function (d) {
                            return '<a class="layui-btn layui-btn-xs" data-open="' + init.detail_url + '?id=' + d.id + '" data-title="用户详情">详情</a>';
                        }
                    }
                ]]
            });
            ea.listen();
        }
    };
});
```

- [ ] **Step 6: Run admin tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAdminAccountControllerTest.php
```

Expected: PASS.

- [ ] **Step 7: Run JS syntax check**

Run:

```powershell
node --check public/static/admin/js/user/account.js
```

Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/admin/user/AccountController.php resources/views/admin/user/account/index.blade.php resources/views/admin/user/account/detail.blade.php public/static/admin/js/user/account.js tests/Feature/User/UserAdminAccountControllerTest.php
git commit -m "feat: add admin user account list"
```

---

## Task 6: Full Verification and Review

**Files:**
- Review all Phase 1 files.

- [ ] **Step 1: Run focused user tests**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAuthTest.php
E:\code\user\.tools\php-8.3.32\php.exe scripts/phpunit-sqlite.php tests/Feature/User/UserAdminAccountControllerTest.php
```

Expected: both commands PASS.

- [ ] **Step 2: Run full suite**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS with all tests.

- [ ] **Step 3: Run static checks**

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/UserAccountStatus.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/UserAuthService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/user/AuthController.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/admin/user/AccountController.php
node --check public/static/admin/js/user/account.js
git diff --check
```

Expected: all commands exit 0.

- [ ] **Step 4: Review implementation against Phase 1 scope**

Confirm:
- No changes touch `system_admin` login behavior.
- User-facing routes live under `/user/*`.
- Admin routes live under `/admin/user/account/*`.
- Registration requires mobile or email.
- Login supports mobile and email.
- Login failures write `user_login_log`.
- Disabled or frozen users cannot log in.
- Admin list/detail is read-only.

- [ ] **Step 5: Commit final cleanup if needed**

If review required cleanup, commit it:

```bash
git add <changed-files>
git commit -m "chore: polish user account phase 1"
```

If no cleanup was needed, do not create an empty commit.

---

## Plan Self-Review

- Spec coverage: This plan covers Phase 1 only: user tables, registration, login, logout, login logs, and admin user list/detail. Password reset, invite, VIP, activation code, 2-level affiliate, balance, and withdrawal are intentionally deferred to separate plans.
- Placeholder scan: The plan contains no unfinished placeholder markers or unspecified implementation steps.
- Type consistency: `UserAuthService`, `UserAccountStatus`, model names, table names, routes, and test names are consistent across tasks.
- Test strategy: Each behavior starts with failing tests, then minimal implementation, then focused and full verification.
