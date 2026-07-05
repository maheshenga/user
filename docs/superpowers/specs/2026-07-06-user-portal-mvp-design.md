# User Portal MVP Design

## Goal

Add a minimal user-facing web portal so the completed registration, login, password reset, VIP, activation code, invite, balance, withdrawal, and ledger APIs can be exercised from browser pages.

## Problem

The user subsystem is currently API-only. Administrators can now see operational pages in the EasyAdmin backend, but ordinary users do not have a visible entry point. This makes the product feel unchanged during manual testing even though the account, VIP, invite, balance, withdrawal, and risk services exist.

## Scope

Included:

- Public page routes under `/u`.
- Login, registration, forgot password, reset password, and dashboard pages.
- A compact Blade layout and static JavaScript that calls the existing `/user/*` JSON endpoints.
- Session-aware dashboard loading through the existing user session set by `UserAuthService::login()`.
- Feature tests proving the pages render and expose the expected forms and dashboard hooks.

Excluded:

- New business APIs or changed `/user/*` contracts.
- Payment provider integration.
- SMS or email delivery provider integration.
- A SPA build pipeline.
- Visual redesign of the EasyAdmin backend.
- New user profile editing.

## Recommended Approach

Use Blade pages with one small static JavaScript file.

Alternatives considered:

- Full SPA: richer later, but it would add a build pipeline and slow down the first visible user slice.
- Server-posted Blade forms: simpler HTML, but it would duplicate controller validation and response handling already covered by the JSON APIs.
- Blade shell plus fetch calls: best first step because it preserves current APIs and gives testers visible workflows quickly.

## Routes

Add these routes behind `CheckInstall`:

- `GET /u` redirects to `/u/dashboard`.
- `GET /u/login` renders the login page.
- `GET /u/register` renders the registration page.
- `GET /u/forgot-password` renders the forgot-password page.
- `GET /u/reset-password` renders the reset-password page.
- `GET /u/dashboard` renders the user dashboard shell.

The routes do not add a new auth middleware in this phase. The dashboard page may render when logged out; its JavaScript should show the existing API message `User login required.` and direct the user back to login.

## User Experience

The user sees a small, work-focused portal:

- Login page: account and password fields, links to register and forgot password.
- Register page: mobile, email, password, and optional invite code fields.
- Forgot-password page: account field that requests a reset token/code through the existing endpoint.
- Reset-password page: account, new password, token, and optional code fields.
- Dashboard: current session user label, VIP summary, activation code redemption, invite summary, invite records, balance summary, balance ledger, withdrawal request form, withdrawal list, and logout.

The page text should be plain and operational. It is not a marketing landing page.

## Architecture

Add `App\Http\Controllers\user\PortalController` for page rendering only. It should not call business services directly. It passes only the current session user and a CSRF token into views.

Add Blade views under `resources/views/user/portal`:

- `layout.blade.php` for shared markup and assets.
- `login.blade.php`
- `register.blade.php`
- `forgot-password.blade.php`
- `reset-password.blade.php`
- `dashboard.blade.php`

Add `public/static/user/js/portal.js` to submit forms and fetch dashboard data from existing JSON APIs. The script should read endpoint URLs from `data-*` attributes so tests and routes stay simple.

## Error Handling

For form submissions, the JavaScript should display the API `msg` value and keep the user on the same page when `code !== 1`.

For dashboard widgets, failed unauthenticated calls should show the returned message instead of throwing a script error.

For reset password, either token or code may be entered because the current API accepts both optional fields and validates in the service.

## Security

Use the existing Laravel session and CSRF middleware. The layout should include a CSRF token meta tag and `portal.js` should send `X-CSRF-TOKEN` on POST requests.

Do not expose password hashes, internal IDs beyond existing public payloads, or admin-only endpoints.

## Testing Strategy

Add `tests/Feature/User/UserPortalPageTest.php` covering:

- `/u` redirects to `/u/dashboard`.
- Login page renders the account/password form and points at `/user/login`.
- Register page renders mobile/email/password/invite fields and points at `/user/register`.
- Forgot/reset pages render the correct fields and endpoint hooks.
- Dashboard page renders the widget shells and existing endpoint hooks.
- Dashboard receives the current `session('user')` payload when present.

Run focused portal tests first, then the full SQLite suite.

## Acceptance Criteria

- A tester can open `/u/login`, register, login, and reach `/u/dashboard`.
- The dashboard can call existing VIP, activation, invite, balance, ledger, withdrawal, and logout endpoints.
- Existing `/user/*` API tests remain green.
- No business service behavior is changed.
- Full SQLite test suite remains green.

## Spec Self-Review

- Placeholder scan: no TODO/TBD markers remain.
- Consistency check: routes, controller, Blade views, JavaScript, and tests all target the same minimal visible user portal.
- Scope check: this is one phase focused on browser visibility, not provider integration or business rule changes.
- Ambiguity check: unauthenticated dashboard rendering is explicit; API endpoints remain the source of auth truth.
