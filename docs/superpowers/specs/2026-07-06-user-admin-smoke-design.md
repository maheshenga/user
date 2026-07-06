# User Admin Smoke Design

## Goal

Add a repeatable smoke check for the EasyAdmin user-operations backend so the team can verify that an installed environment exposes the admin login, synchronized user-ops menu, dashboard JSON metrics, and key user admin pages.

## Problem

The user subsystem now has backend operations pages and a public user portal, but browser-level verification is uneven. P12 covers the user portal. P13 makes the user dashboard readable. The admin side still relies mostly on PHPUnit feature tests and manual clicking after `user:ops-menu:sync`. For long-running operations, this leaves a practical gap: a deployment can pass service tests while the real admin menu or dynamic admin routes are broken.

## Scope

Included:

- Add `scripts/user-admin-smoke.php`.
- Add a composer alias `smoke:user-admin`.
- Add a fixture HTTP router and feature tests for the smoke script.
- Verify admin login, menu initialization, user operations menu visibility, dashboard metrics JSON, and representative admin pages.
- Keep the script black-box: it talks to HTTP only and does not mutate application data directly.

Excluded:

- New admin pages or menu rows.
- Changes to user business rules.
- Changes to admin authentication, permissions, or middleware.
- Browser automation with Playwright.
- Production seeding or install workflow changes.

## Smoke Flow

The script accepts:

- `--base-url`: required target URL.
- `--admin-prefix`: optional, default `admin`.
- `--username`: optional, default `admin`.
- `--password`: optional, default `123456`.
- `--timeout`: optional, default `10`.

It performs:

1. `GET /{admin-prefix}/login` and extracts CSRF token from the admin login page.
2. `POST /{admin-prefix}/login` with AJAX headers and admin credentials.
3. `GET /{admin-prefix}/ajax/initAdmin` and confirms the menu contains `User Operations` plus `user/dashboard/index`.
4. `GET /{admin-prefix}/user/dashboard/index` with JSON accept headers and confirms required metric keys exist.
5. `GET` representative admin pages:
   - `user/dashboard/index`
   - `user/account/index`
   - `user/withdrawal/index`
   - `user/risk-event/index`
   - `user/notification-outbox/index`

The script prints `PASS ...` lines for each checkpoint and ends with `OK user admin smoke passed`. Failures print `FAIL user admin smoke failed` plus a concrete reason.

## Architecture

Reuse the simple stream-based HTTP client pattern from `scripts/user-portal-smoke.php`, but keep the admin script separate to avoid coupling two independent smoke surfaces. Add an internal client that stores cookies and CSRF token, supports AJAX requests, and decodes JSON responses.

Add `tests/Fixtures/user-admin-smoke-router.php` to emulate admin login, menu JSON, dashboard JSON, and failure modes. Add `tests/Feature/User/UserAdminSmokeScriptTest.php` to prove success, option parsing, and clear failure output.

## Error Handling

The script must fail clearly when:

- `--base-url` is missing or empty.
- Admin login does not return JSON `code=1`.
- The menu JSON lacks `User Operations`.
- The menu JSON lacks `user/dashboard/index`.
- Dashboard JSON lacks required metric keys.
- Any representative admin page returns a non-200 HTTP status.

## Testing Strategy

Add PHPUnit tests that start the fixture router with PHP's built-in server and run the script as a subprocess. Tests cover:

- Successful smoke run.
- Space-separated CLI option values.
- Clear failure when menu data is missing `User Operations`.
- Clear failure when dashboard metrics are incomplete.

After implementation, run the focused smoke-script tests, the existing user ops visibility tests, the full SQLite suite, static syntax checks, and a real Laravel HTTP smoke where practical.

## Acceptance Criteria

- `composer smoke:user-admin -- --base-url=http://127.0.0.1:8000` works against an installed environment with valid admin credentials.
- Fixture tests prove the script catches missing menu and missing dashboard metrics.
- Existing admin/user feature tests remain green.
- No source files outside script/test/docs/composer alias are changed.

## Spec Self-Review

- Placeholder scan: no TODO or TBD markers remain.
- Consistency check: script options, tested paths, fixture routes, and acceptance criteria use the same admin-prefix model.
- Scope check: this is one validation phase, not a business-feature phase.
- Ambiguity check: the script is intentionally black-box HTTP and does not create admin users or run menu sync itself.
