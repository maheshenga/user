# Qingyu IP Agent Changelog

## 1.1.0 - 2026-07-08

- Clarified that the EasyAdmin8 module center path is lifecycle management only.
- Confirmed the module business entry is `qingyu_ip_agent/dashboard/index` and all module pages use `qingyu_ip_agent/*` business routes.
- Added route-level coverage for `/admin/qingyu_ip_agent/dashboard/index` as the operational dashboard entry.
- Kept legacy standalone admin paths such as `/admin/codes` and `/admin/users` out of the module boundary.

## 1.0.0 - 2026-07-07

- Added independent EasyAdmin8 module `qingyu_ip_agent`.
- Added module-owned settings and operation audit log tables.
- Added admin menus for dashboard, members, activation codes, redemptions, settings, and audit logs.
- Added host-service adapters for VIP grant and activation code batch/code generation.
- Added sensitive payload masking for module audit logs.
