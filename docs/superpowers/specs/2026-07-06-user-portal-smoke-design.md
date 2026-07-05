# User Portal Smoke Automation Design

## Goal

Add a repeatable smoke check that proves the visible user portal can run through the same browser-facing pages and user API session flow that a manual tester needs before deeper business testing.

## Problem

The user portal pages now exist and the session boundary is hardened, but the project still lacks one command that answers the practical question: "If I start this Laravel app locally, can a real HTTP client register, log in, load dashboard APIs, and log out?"

Feature tests cover controllers and Blade output inside Laravel's test kernel. They do not exercise the deployed HTTP surface, cookie persistence, CSRF headers, redirects, or a tester's end-to-end path through `/u/*` pages and `/user/*` APIs.

## Scope

Included:

- Add a CLI smoke script for an already running HTTP server.
- Exercise these pages: `/u`, `/u/login`, `/u/register`, `/u/forgot-password`, `/u/reset-password`, and `/u/dashboard`.
- Parse the user portal CSRF token from rendered HTML and send it on mutating API requests.
- Preserve cookies across requests to verify Laravel session behavior.
- Run this flow: logged-out session check, register, login, logged-in session check, VIP/balance/invite/withdrawal dashboard API reads, logout, logged-out session check.
- Add automated tests for the smoke script using a small local fixture HTTP server.
- Document a local Laravel smoke command sequence that starts from SQLite and does not require MySQL.
- Add a Composer script alias so the check is easy to run.

Excluded:

- No new user business rules.
- No page redesign.
- No Playwright or browser-driver dependency.
- No payment, SMS, email, payout, or third-party provider integration.
- No destructive changes to a developer's normal `.env` or tracked schema files.

## Recommended Approach

Build a standalone `scripts/user-portal-smoke.php` command that accepts `--base-url`, `--email`, `--password`, and `--timeout`. This is the narrowest useful layer: it runs against real HTTP, preserves cookies, sends CSRF headers, and can be used against both `php artisan serve` and future staging URLs.

Alternatives considered:

- Add only more PHPUnit feature tests: fast, but still not enough to prove a running site works through HTTP cookies and CSRF.
- Add Playwright: stronger visual coverage, but it brings a new browser dependency and is heavier than this phase needs.
- Add an Artisan command: convenient inside Laravel, but less useful against a running staging URL and more coupled to application boot.

## Smoke Script Contract

Command:

```powershell
E:\code\user\.tools\php-8.3.32\php.exe scripts\user-portal-smoke.php --base-url=http://127.0.0.1:8000
```

Success output must include:

```text
OK user portal smoke passed
```

Failure output must include:

```text
FAIL user portal smoke failed
```

The script exits `0` on success and non-zero on failure.

## Runtime Flow

1. Normalize `--base-url` and create an in-memory cookie jar.
2. `GET /u/register`, parse `<meta name="csrf-token" ...>`.
3. `GET /u`, `/u/login`, `/u/register`, `/u/forgot-password`, `/u/reset-password`, and `/u/dashboard`; assert expected 2xx or redirect shape.
4. `GET /user/session`; assert `code=0`.
5. `POST /user/register` with a unique email and password.
6. `POST /user/login` with the same credentials.
7. `GET /user/session`; assert `code=1` and matching email.
8. `GET /user/vip`, `/user/balance`, `/user/balance/ledger`, `/user/invite`, `/user/invite/records`, and `/user/withdrawal`; assert JSON responses are reachable.
9. `POST /user/logout`; assert `code=1`.
10. `GET /user/session`; assert `code=0`.

If an API returns a refreshed `__token__`, the script updates the CSRF token before the next mutating request.

## Testing Strategy

Use TDD for the smoke script:

- First add a failing PHPUnit test that runs the script against a lightweight fixture router.
- The fixture router returns portal pages, CSRF token HTML, JSON API responses, and session-like cookies.
- Then implement the script until the test passes.
- Add focused assertions that the script fails clearly when an endpoint returns an invalid response.
- Run the new focused tests, the existing user portal tests, the full SQLite suite, and PHP syntax checks.

## Local Verification Strategy

For real Laravel smoke verification, use a temporary SQLite runtime:

1. Create `database/database.sqlite` if missing.
2. Run `php artisan migrate:fresh --force` with `DB_CONNECTION=sqlite` and `DB_DATABASE=<absolute sqlite path>`.
3. Start `php artisan serve` on a local port with the same SQLite environment.
4. Run `scripts/user-portal-smoke.php --base-url=http://127.0.0.1:<port>`.
5. Stop the server after the script completes.

This avoids depending on the developer's MySQL server and keeps the smoke run reproducible.

## Acceptance Criteria

- A developer can run one script against a local server and get a clear pass/fail result.
- The script checks pages, cookies, CSRF, register, login, session, dashboard APIs, and logout.
- The script does not require new Composer packages or Node packages.
- The script has automated test coverage.
- Existing user portal and full SQLite tests remain green.
- The phase is committed, reviewed, merged to `main`, and pushed to `origin/main`.

## Spec Self-Review

- Placeholder scan: no TODO/TBD markers remain.
- Consistency check: scope, script contract, runtime flow, and tests all target HTTP-level portal smoke verification.
- Scope check: this is one focused phase and does not alter business rules.
- Ambiguity check: success/failure output, exit codes, and checked endpoints are explicit.
