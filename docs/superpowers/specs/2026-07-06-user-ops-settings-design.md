# User Operations Settings Design

## Goal

Add an administrator-operated configuration center for long-running user operations. The first slice makes invite defaults, password reset expiry, risk thresholds, and withdrawal amount limits configurable through the existing EasyAdmin `system_config` mechanism while preserving current default behavior.

## Context

The user operations system now has registration/login, invite binding, password reset, VIP activation codes, two-level commissions, balance ledger, withdrawal review, risk events, admin operations pages, smoke checks, and deployment acceptance automation.

Current configurable infrastructure already exists:

- `sysconfig($group, $name)` reads `system_config` and caches values.
- `app/Http/Controllers/admin/system/ConfigController.php` persists grouped config values.
- `TriggerService::updateSysConfig()` clears config cache.

The P16 design reuses this infrastructure instead of introducing a new configuration storage system.

## Scope

P16 adds one focused `user_ops` settings surface:

- A typed settings service with safe defaults.
- An admin page under User Operations for viewing and saving settings.
- A menu entry under the existing User Operations menu group.
- Business-service reads for a small set of operational values.
- Tests proving defaults, overrides, validation, menu sync, and unchanged behavior when no settings are present.

## Settings

All settings live in `system_config` with `group = user_ops`.

| Name | Type | Default | Meaning |
| --- | --- | --- | --- |
| `invite_default_max_uses` | integer | `0` | Max uses for a newly created user invite code. `0` means unlimited, matching current behavior. |
| `invite_default_expires_days` | integer | `0` | Expiry days for a newly created user invite code. `0` means no expiry, matching current behavior. |
| `password_reset_expires_minutes` | integer | `30` | Expiry window for newly requested password reset token/code. |
| `risk_invite_burst_threshold` | integer | `5` | Same-IP invited-registration count that opens an invite burst risk event. |
| `risk_invite_burst_window_hours` | integer | `24` | Time window used for invite burst counting. |
| `risk_activation_failure_threshold` | integer | `5` | Recent activation-code failure count that raises severity from low to medium. |
| `risk_activation_failure_window_minutes` | integer | `10` | Time window used for activation failure counting. |
| `withdrawal_min_amount` | money string | `0.01` | Minimum allowed withdrawal amount after money normalization. |
| `withdrawal_max_amount` | money string | `0.00` | Maximum allowed withdrawal amount. `0.00` means unlimited, matching current behavior. |

P16 intentionally does not move VIP plan prices, VIP duration, activation batch rewards, or activation batch expiry into global settings because those are already data-backed per-plan or per-batch controls.

## Architecture

Add `App\User\UserOpsSettings`, a small typed reader around `sysconfig('user_ops', ...)`.

The service owns:

- Default values.
- Integer parsing and min/max clamps.
- Money parsing and formatting.
- A `publicSettings()` method for admin page rendering.
- A `validateForSave()` method for admin save requests.

Business services depend on `UserOpsSettings` through constructor injection:

- `InviteService` reads default invite max uses and expiry days in `createDefaultCode()`.
- `PasswordResetService` reads reset expiry minutes in `requestReset()`.
- `RiskService` reads invite burst and activation failure thresholds/windows.
- `WithdrawalService` validates request amount against min/max before freezing balance.

Add `app/Http/Controllers/admin/user/SettingsController.php` under the existing admin user namespace. The controller renders a compact form and saves via AJAX. It writes to `system_config` with `group=user_ops`, then calls `TriggerService::updateSysConfig()`.

Add `resources/views/admin/user/settings/index.blade.php` using EasyAdmin/Layui-style form fields. No heavy JavaScript is needed beyond the existing `ea.listen()` save pattern.

Add a `Settings` child entry to `UserOpsMenuService` so `artisan user:ops-menu:sync` exposes the page.

## Validation

Save validation rejects:

- Negative integer settings.
- Threshold/window values below `1` where zero would disable security behavior.
- Money values below `0`.
- `withdrawal_max_amount` lower than `withdrawal_min_amount` when max is not `0.00`.
- Unexpected field names. Unknown config keys are ignored and not persisted.

Validation errors return the existing EasyAdmin JSON error format.

## Backward Compatibility

With no `user_ops` rows in `system_config`, behavior remains equivalent:

- Invite codes stay unlimited and non-expiring.
- Password reset tokens expire in 30 minutes and return `expires_in = 1800`.
- Invite burst threshold remains `5` over 24 hours.
- Activation failure severity becomes medium at the fifth failure within 10 minutes.
- Withdrawals still accept any positive normalized amount.

## Tests

Add `tests/Feature/User/UserOpsSettingsTest.php`.

Coverage:

- Typed defaults are returned when no config exists.
- Existing `system_config` rows override defaults.
- Invalid save payloads are rejected.
- Valid save payloads persist only allowlisted `user_ops` keys and clear config cache.
- Menu sync creates a `user/settings/index` entry.
- Invite code creation uses configured max uses and expiry.
- Password reset uses configured expiry and `expires_in`.
- Risk thresholds/windows use configured values.
- Withdrawal min/max limits reject out-of-policy requests.

## Non-goals

- No third-party module marketplace settings.
- No new database table.
- No live feature flags or rollout engine.
- No automatic payout provider integration.
- No replacement of per-plan VIP or per-batch activation-code controls.

## Self-review

The design is intentionally narrow. It makes already-built operations safer to run over time without changing default behavior or introducing a second configuration system.
