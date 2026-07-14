# Qingyu IP Agent Review Checklist

Current review target: `1.5.0`

Record and approve the exact SHA-256 artifact hash shown by the module center. Do not reuse an earlier version approval.

- `module.json` name is `qingyu_ip_agent`.
- `admin_prefix` is `qingyu_ip_agent` and does not collide with reserved prefixes.
- Module type is `private`.
- Menus are declared in `module.json`.
- Controllers use `ControllerAnnotation` and actions use `NodeAnnotation`.
- Dangerous actions require POST.
- Migrations are module-local and reversible.
- Module-owned tables only store settings and masked audit logs.
- Full activation codes, tokens, passwords, full mobile numbers, and full emails are not written to module logs.
- Member registration must preserve `source_module=qingyu_ip_agent`.
- Member, dashboard, activation batch, code, and redemption queries must remain scoped to `qingyu_ip_agent`.
- Balance, commission, VIP, activation code, invite, audit, and notification behavior must go through `App\Contracts\Modules\*Gateway`.
- Bearer APIs require an active module device session and manifest-derived abilities.
- `X-Request-ID` must be reused across the single refresh retry; parser and rewrite requests use the request ledger and daily quotas.
- Disabling or uninstalling the module must revoke all `qingyu_ip_agent` API sessions.
- Affiliate commission settlement remains administrator-reviewed.
