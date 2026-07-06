# User Admin Smoke Test

## Purpose

Use this smoke test after local deployment or a pull from `origin/main` to confirm the admin user-operations surface is visible and wired.

## Prerequisites

- The Laravel app is running, for example at `http://127.0.0.1:8000`.
- The app is installed and has a valid admin account.
- The admin user can access the `用户运营` menu.

## Command

```powershell
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run smoke:user-admin -- --base-url=http://127.0.0.1:8000 --admin-prefix=admin --username=admin --password=123456 --timeout=10
```

Composer also supports the shorter form when PHP and Composer are already on `PATH`:

```powershell
composer run smoke:user-admin -- --base-url=http://127.0.0.1:8000 --admin-prefix=admin --username=admin --password=123456
```

## Coverage

- Logs in through `/admin/login` with CSRF.
- Checks `/admin/ajax/initAdmin` includes the `用户运营` menu.
- Requests every user-operations admin page.
- Checks the account page includes `账号状态管理`, status labels, `data-auth-modify`, and `id="userStatusTpl"`.
- Checks `/static/admin/js/user/account.js` includes status buttons, `data-account-status`, `field: 'status'`, `value: status`, and table reload wiring.
- Sends safe rejected probes to `/admin/user/account/modify` for non-status fields and invalid statuses; this is reported as `status endpoint guards` and does not mutate real accounts.

## Expected Result

The final line should be:

```text
OK user admin smoke passed
```

## Failure Triage

- If login fails, verify `--admin-prefix`, `--username`, `--password`, install state, and CSRF/session configuration.
- If the menu check fails, run the menu sync/seed process and confirm `用户运营` is assigned to the admin role.
- If a page looks like a login page, the admin session expired or middleware rejected the request.
- If `账号状态管理` or `/static/admin/js/user/account.js` checks fail, refresh assets and confirm the latest pushed code is deployed.
- If `/admin/user/account/modify` guard checks fail, stop testing account status changes until the backend status-only boundary is restored.
