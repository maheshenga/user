# Deployment Acceptance Automation Design

## Goal

Build a single deployment acceptance command that verifies a deployed EasyAdmin8 instance is ready for the long-running user operations surface: local configuration is sane, database migrations can be applied, the user operations menu can be synced, and both user portal and admin smoke scripts pass against the deployed base URL.

## Scope

This phase adds orchestration only. It does not add new user, VIP, balance, withdrawal, risk, module, or admin business behavior. It reuses the existing smoke scripts as the source of truth for HTTP checks.

The command is intended for an operator after deployment:

```bash
php scripts/deploy-acceptance.php --base-url=http://127.0.0.1:8000
```

Composer exposes the same entry point:

```bash
composer deploy:acceptance -- --base-url=http://127.0.0.1:8000
```

## CLI Contract

Required option:

- `--base-url=URL`: base HTTP URL of the deployed app. Required unless both `--skip-portal` and `--skip-admin` are present.

Optional runtime options:

- `--admin-prefix=admin`: admin route prefix passed to `scripts/user-admin-smoke.php`.
- `--admin-username=admin`: admin username passed to `scripts/user-admin-smoke.php`.
- `--admin-password=123456`: admin password passed to `scripts/user-admin-smoke.php`.
- `--portal-email=generated`: portal email passed to `scripts/user-portal-smoke.php`.
- `--portal-password=secret123`: portal password passed to `scripts/user-portal-smoke.php`.
- `--timeout=10`: timeout seconds passed to child checks and process timeout scaling.

Optional control flags:

- `--skip-env`: skip local `.env` and `APP_KEY` checks.
- `--skip-migrate`: skip `php artisan migrate --force`.
- `--skip-menu-sync`: skip `php artisan user:ops-menu:sync`.
- `--skip-portal`: skip the portal smoke script.
- `--skip-admin`: skip the admin smoke script.
- `--dry-run`: print planned checks without running mutating or HTTP commands.

Test-only option:

- `--php-binary=PATH`: overrides the PHP executable used for subprocesses. This keeps tests hermetic by pointing the orchestrator at fixture PHP runners.

## Behavior

The command prints one line per check using the existing smoke style:

- `PASS env APP_KEY present`
- `PASS artisan migrate --force`
- `PASS artisan user:ops-menu:sync`
- `PASS user portal smoke`
- `PASS user admin smoke`
- `OK deployment acceptance passed`

Failures print:

```text
FAIL deployment acceptance failed
<specific reason>
```

Child command output is surfaced when a child command fails, so operators can see the real smoke or artisan error without re-running in debug mode.

Dry-run prints the planned checks and commands with `DRY-RUN` prefixes and exits `0`. It never runs migrations, menu sync, or smoke scripts.

## Architecture

The script is a standalone PHP CLI file under `scripts/`, matching the existing smoke scripts. It intentionally avoids new framework services and new Composer dependencies.

The orchestrator performs:

1. Option parsing and validation.
2. Environment readiness checks.
3. Subprocess execution through `proc_open`.
4. Child command construction for artisan and existing smoke scripts.
5. Consistent PASS, FAIL, and OK output.

No application code is imported by the script. Artisan is called through a child process so the check mirrors operator usage and avoids bootstrapping Laravel just to inspect configuration.

## Testing

Tests use `Symfony\Component\Process\Process`, matching existing smoke script tests.

The first RED test covers a successful dry run, proving the CLI contract and planned output before production code exists.

The second RED test covers orchestration through a fixture PHP runner. The fixture runner records child commands to a JSON-lines file and exits successfully, avoiding real DB or network mutation.

The third RED test covers child failure aggregation. The fixture runner fails on a selected child command and the orchestrator must return a non-zero exit code with `FAIL deployment acceptance failed` and the child output.

The fourth RED test covers validation: missing `--base-url` fails unless both HTTP smoke checks are skipped.

## Non-goals

- Starting a local Laravel server automatically.
- Creating a temporary SQLite deployment database.
- Replacing existing portal or admin smoke assertions.
- Adding third-party marketplace review logic.
- Changing production menu, route, VIP, balance, withdrawal, or risk behavior.

## Self-review

The design has no placeholders. Scope is intentionally limited to a deploy-time wrapper around proven checks. The command is testable without DB/network side effects through dry-run and `--php-binary`.
