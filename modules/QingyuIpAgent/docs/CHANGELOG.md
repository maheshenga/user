# Changelog

## 1.5.0 - 2026-07-14

- Bound members, activation batches, codes, and redemptions to `qingyu_ip_agent` ownership.
- Switched VIP and activation-code integration to stable host Gateway contracts.
- Added active-module token policy and session revocation on disable or uninstall.
- Added request IDs, idempotent replay, daily quotas, typed errors, and audit correlation.
- Added parser and rewrite timeout budgets without changing desktop IPC channel names.
- Added a reversible request-context migration for operation logs.

Upgrade notes:

- Run the host migration before module activation.
- Version `1.5.0` requires a new administrator review of the exact immutable artifact hash.
- Roll back only to a previous approved release after confirming the request-context migration can run `down()`.
