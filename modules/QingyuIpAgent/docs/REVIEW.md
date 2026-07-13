# Qingyu IP Agent Review Checklist

- `module.json` name is `qingyu_ip_agent`.
- `admin_prefix` is `qingyu_ip_agent` and does not collide with reserved prefixes.
- Module type is `private`.
- Menus are declared in `module.json`.
- Controllers use `ControllerAnnotation` and actions use `NodeAnnotation`.
- Dangerous actions require POST.
- Migrations are module-local and reversible.
- Module-owned tables only store settings and masked audit logs.
- Full activation codes, tokens, passwords, full mobile numbers, and full emails are not written to module logs.
- Member registration, if added later, must call `UserAuthService::register()` with `source_module=qingyu_ip_agent`.
- Balance, withdrawal, commission, VIP, activation code, invite, log, and notification behavior must go through host services.
- Affiliate commission settlement remains administrator-reviewed.
