# User Portal Flow Hardening Design

## Goal

Make the new user portal reliably support a browser tester moving through register, login, dashboard, session check, VIP summary, balance, invite, withdrawal, and logout without needing to infer backend state manually.

## Problem

P10 added visible `/u` pages and JavaScript hooks, but the browser flow still has a weak session boundary:

- The dashboard renders from Blade session data, but JavaScript has no dedicated `/user/session` endpoint to confirm the current login state.
- A logged-out dashboard currently attempts multiple protected API calls and each widget independently reports `User login required.`
- There is no focused backend test proving the visible portal can complete the basic register -> login -> session -> dashboard API -> logout loop.

This is enough for a first page shell, but not enough for long-running manual testing where the tester needs clear state and recoverable flows.

## Scope

Included:

- Add a current-user session API at `GET /user/session`.
- Teach the dashboard JavaScript to check session first.
- If logged out, show one clear dashboard status and skip protected widget fetches.
- If logged in, show the current user and load existing dashboard widgets.
- Add feature tests covering session API behavior and the register/login/logout browser flow at the HTTP layer.
- Update page tests to assert the dashboard has a session endpoint hook.

Excluded:

- New profile editing.
- New business rules for VIP, invite, balance, activation code, or withdrawal.
- Payment, SMS, email, or payout provider integration.
- A full frontend build system or SPA migration.
- Visual redesign beyond small status/state text needed for flow clarity.

## Recommended Approach

Add a small method to the existing user `AuthController` instead of creating a separate profile controller. Session state is part of authentication, and the existing controller already owns login and logout.

Alternatives considered:

- Use Blade-only session state: simple, but stale after AJAX logout/login and not testable as a JSON boundary.
- Add a full `/user/profile` API: useful later, but broader than the current session problem.
- Add `GET /user/session`: narrow, testable, and directly supports the portal JavaScript.

## API Contract

`GET /user/session`

Logged in response:

```json
{
  "code": 1,
  "msg": "User session",
  "data": {
    "user": {
      "id": 1,
      "mobile": "13800138000",
      "email": "user@example.com",
      "nickname": "user@example.com"
    }
  }
}
```

Logged out response:

```json
{
  "code": 0,
  "msg": "User login required.",
  "data": {}
}
```

The endpoint reads only `session('user')`. It must not query password fields or expose additional internal data.

## Portal Behavior

On `/u/dashboard`, `portal.js` should:

1. Read `data-session="/user/session"` from `data-dashboard-endpoints`.
2. Call `/user/session` before loading protected widgets.
3. If the response has `code !== 1`, show a single dashboard status message and skip widget loading.
4. If logged in, update the current-user display from the returned payload and load VIP, balance, ledger, invite, invite records, and withdrawals.
5. Continue using existing endpoints for activation code redemption, withdrawal request, and logout.

Register behavior remains as introduced in P10: after successful registration, JavaScript logs the user in with the same account/password through `/user/login` before redirecting to `/u/dashboard`.

## Testing Strategy

Add focused feature tests:

- `GET /user/session` returns `User login required.` when logged out.
- `GET /user/session` returns the current session user when logged in.
- A user can register through `/user/register`, login through `/user/login`, read `/user/session`, access `/user/vip`, logout through `/user/logout`, and then see `/user/session` return logged-out state.
- `/u/dashboard` renders `data-session="/user/session"` along with existing endpoint hooks.

Run focused portal/auth tests, then the full SQLite suite.

## Acceptance Criteria

- `/user/session` exists and is protected by the same install and throttle group as the other `/user/*` APIs.
- The dashboard does not spam protected widget calls when there is no user session.
- Register/login/session/logout flow is covered by automated feature tests.
- Existing `/user/*` API contracts remain unchanged.
- Full SQLite test suite remains green.

## Spec Self-Review

- Placeholder scan: no TODO/TBD markers remain.
- Consistency check: the API, JavaScript behavior, tests, and acceptance criteria all target the same session-boundary problem.
- Scope check: this is one focused hardening phase and does not add new business domains.
- Ambiguity check: logged-in and logged-out JSON contracts are explicit.
