# Versioned Desktop Token API Design

## Status

Approved for implementation on 2026-07-14. The system is pre-launch, so the desktop client will move to the durable API contract before public operation instead of extending the legacy cookie session.

## Problem

The Qingyu desktop client currently calls dynamic `/admin/qingyu_ip_agent/client/*` routes with a Laravel session cookie and CSRF token. The cookie remains encrypted on disk after the server-side session expires. A later write request reaches the server but fails with HTTP 419, while the renderer only reports a generic registration failure.

Keeping a socket open does not solve this problem. Desktop authentication needs a stateless, versioned API with independently renewable credentials.

## Architecture

The application has two explicit authentication boundaries:

- Admin and browser user portal: existing Laravel web session and CSRF protection.
- Desktop and module API: `/api/v1/*`, JSON only, Laravel Sanctum bearer access tokens, rotating refresh tokens, no cookie dependency.

The shared `user_account` record remains the source of identity, invitation, VIP, balance, affiliate, risk, and module ownership data. The new API only changes transport authentication; it does not create a second member system.

## API Contract

Successful responses use:

```json
{
  "success": true,
  "code": 0,
  "message": "ok",
  "data": {}
}
```

Errors use an appropriate HTTP status and the same stable envelope with `success: false`. Validation is 422, bad credentials are 401, disabled accounts are 403, duplicate registration is 409, lockout is 429, and unexpected failures are 500 without internal details.

Authentication routes:

```text
POST /api/v1/auth/register
POST /api/v1/auth/login
POST /api/v1/auth/refresh
POST /api/v1/auth/logout
GET  /api/v1/auth/profile
POST /api/v1/auth/password/forgot
POST /api/v1/auth/password/reset
```

Qingyu routes:

```text
GET  /api/v1/modules/qingyu-ip-agent/bootstrap
GET  /api/v1/modules/qingyu-ip-agent/sample-audio
POST /api/v1/modules/qingyu-ip-agent/activation-codes/redeem
POST /api/v1/modules/qingyu-ip-agent/content/parse
POST /api/v1/modules/qingyu-ip-agent/content/rewrite
```

Legacy `/admin/qingyu_ip_agent/client/*` routes remain temporarily available for rollback, but the desktop client stops using them.

## Token Lifecycle

- Access token lifetime: 15 minutes.
- Refresh token lifetime: 30 days.
- Access tokens are Sanctum personal access tokens stored as hashes.
- Refresh tokens are random opaque secrets stored only as SHA-256 hashes.
- Every refresh consumes the presented refresh token and issues a new one.
- Reusing a consumed refresh token revokes the whole device session.
- Logout revokes the current device session, its access token, and all active refresh tokens.
- Disabled, frozen, or deleted users cannot issue or refresh tokens.

Each device session records module, device ID, device name, IP, user agent, last use, and revocation time. The desktop stores the token bundle with Electron `safeStorage`; renderer local storage receives only the compatibility token already expected by the packaged UI.

## Module Scopes

The Qingyu desktop token receives only:

```text
profile:read
vip:read
activation:redeem
content:parse
content:rewrite
module:qingyu_ip_agent
```

Protected routes require both the module scope and their operation scope. Future modules must be added to the central module API policy before tokens can be issued for them.

## Desktop Behavior

The desktop client sends bearer tokens instead of Cookie and `X-CSRF-TOKEN` headers. Registration and login persist the returned access/refresh bundle. Protected requests retry once after a 401 by rotating the refresh token. A second 401, an explicitly rejected refresh token, or a revoked session clears the encrypted token bundle and returns the server's real message. Network, rate-limit, and server failures preserve the encrypted state so users are not logged out by transient outages.

Only authentication expiry is retried. Validation, duplicate account, VIP, rate-limit, and provider errors are returned directly. This prevents duplicate registration and hides no actionable error behind a generic message.

The main process removes the server access and refresh tokens before returning authentication responses to the renderer. The encrypted state may retain a device ID after logout so the physical device remains stable, but no usable credential remains.

## Security Boundaries

- HTTPS is mandatory in production.
- Admin routes keep CSRF protection; no global CSRF exclusion is added.
- API credentials are never logged.
- Refresh operations use database transactions and row locks.
- Password verification and login lockout continue through `UserAuthService`.
- VIP and account status are read from the database for every protected operation.
- Token abilities are enforced server-side and are not trusted from client input.
- Disabled users are rejected on every protected route and all of their device sessions are revoked.
- A successful password reset revokes every access and refresh token for the account.
- Password reset requests use a non-enumerating public response; reset secrets are never logged and are removed from the notification outbox immediately after delivery.

## Migration And Rollback

1. Deploy Sanctum and token/session migrations.
2. Deploy `/api/v1` while legacy routes remain intact.
3. Switch and restart the Qingyu desktop shim.
4. Run real register, login, refresh, profile, activation, parse, and rewrite tests.
5. Keep legacy routes for one release as rollback protection; remove them in a later reviewed release.

Rollback restores the previous desktop shim. The token tables are additive and do not alter existing member, VIP, invitation, or financial records.

## Verification

- Backend feature tests cover token issuance, no-CSRF registration, scoped access, rotation, reuse revocation, logout, disabled users, and Qingyu routes.
- Desktop tests cover endpoint mapping, encrypted token persistence, refresh decisions, one retry, and error propagation.
- Production smoke testing uses a unique test account and verifies an actual API request path without exposing secrets.
