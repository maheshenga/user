# P17 Activation Code Chinese Errors Implementation Plan

> Execution note: implement directly in the current workspace without subagents.

## Goal

Localize activation-code service validation and redemption errors to Chinese so user-facing redemption failures, redemption audit records, and admin activation-code operations no longer expose English messages.

## Architecture

Keep `App\User\ActivationCodeService` behavior and return structures unchanged. Only replace English `InvalidArgumentException` strings, internal redemption error strings, and controller validation messages with Chinese equivalents. Update feature tests that assert service exceptions, controller JSON, risk events, affiliate failure behavior, and redemption audit `error_message`.

## Constraints

- Do not change activation-code hashing, generation, redemption, VIP extension, commission, or audit persistence behavior.
- Do not change API response shape.
- Preserve status values such as `unused`, `used`, `disabled`, `success`, and `failed`.
- Execute directly in this session; do not dispatch subagents.

## File Structure

- Modify `app/User/ActivationCodeService.php`: replace English validation and redemption errors with Chinese messages.
- Modify `app/Http/Controllers/user/ActivationCodeController.php`: add Chinese validator messages for `/user/activation-code/redeem`.
- Modify `tests/Feature/User/UserVipActivationTest.php`: update expected activation-code redemption errors and audit error messages.
- Modify `tests/Feature/User/UserAffiliateBalanceTest.php`: update expected invalid activation-code redemption error.
- Modify `tests/Feature/User/UserRiskOpsTest.php`: update expected activation failure error used for risk records.
- Modify `tests/Fixtures/user-portal-smoke-router.php`: keep smoke fixture aligned with Chinese activation-code validation.

## Task 1: RED Tests

- Update reused single-use activation-code expectation to `激活码当前不可用。`.
- Update failed audit assertion to `激活码当前不可用。`.
- Update unusable activation-code matrix messages:
  - disabled: `激活码当前不可用。`
  - expired: `激活码已过期。`
  - usage limit reached: `激活码使用次数已达上限。`
  - bound to another user: `激活码不属于当前用户。`
- Update empty redeem payload expectation to assert JSON `msg` is `激活码不能为空。`.
- Update affiliate invalid-code exception expectation to `激活码无效。`.
- Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserVipActivationTest|UserAffiliateBalanceTest"
```

Expected result: fails because the service/controller still return English or default validation messages.

## Task 2: GREEN Implementation

Use these exact replacements:

```text
VIP plan is not active. => VIP 套餐未启用。
Batch name is required. => 批次名称不能为空。
Total count must be greater than zero. => 生成总数必须大于 0。
Activation code batch is not active. => 激活码批次未启用。
Generate count must be greater than zero. => 生成数量必须大于 0。
Generate count exceeds remaining batch capacity. => 生成数量超过批次剩余容量。
Activation code is required. => 激活码不能为空。
Activation code is invalid. => 激活码无效。
Activation code is not usable. => 激活码当前不可用。
Activation code is expired. => 激活码已过期。
Activation code usage limit reached. => 激活码使用次数已达上限。
Activation code is not bound to this user. => 激活码不属于当前用户。
Unable to generate activation code. => 无法生成激活码。
```

Add controller validator messages:

```text
code.required => 激活码不能为空。
code.string => 激活码格式不正确。
code.max => 激活码不能超过 80 个字符。
```

Run:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite -- --filter="UserVipActivationTest|UserAffiliateBalanceTest|UserRiskOpsTest"
```

Expected result: passes.

## Task 3: Verification

Run syntax checks:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe -l app/User/ActivationCodeService.php
E:\code\user\.tools\php-8.3.32\php.exe -l app/Http/Controllers/user/ActivationCodeController.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserVipActivationTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserAffiliateBalanceTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Feature/User/UserRiskOpsTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests/Fixtures/user-portal-smoke-router.php
```

Run full tests:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```

Review:

```powershell
git diff --check
git diff --stat
git diff -- app/User/ActivationCodeService.php app/Http/Controllers/user/ActivationCodeController.php tests/Feature/User/UserVipActivationTest.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserRiskOpsTest.php tests/Fixtures/user-portal-smoke-router.php docs/superpowers/plans/2026-07-07-p17-activation-code-chinese-errors.md
```

## Task 4: Commit and Push

```powershell
git add docs/superpowers/plans/2026-07-07-p17-activation-code-chinese-errors.md app/User/ActivationCodeService.php app/Http/Controllers/user/ActivationCodeController.php tests/Feature/User/UserVipActivationTest.php tests/Feature/User/UserAffiliateBalanceTest.php tests/Feature/User/UserRiskOpsTest.php tests/Fixtures/user-portal-smoke-router.php
git commit -m "fix: localize activation code validation"
git push origin main
```

## Self-review

- Spec coverage: activation-code batch validation, generation validation, user redemption validation, failed redemption audit, risk failure message, and affiliate failure expectations.
- Scope check: message strings only; no activation-code lifecycle, VIP, commission, hashing, or audit behavior changes.
- Operational check: full SQLite test suite must pass before commit.
