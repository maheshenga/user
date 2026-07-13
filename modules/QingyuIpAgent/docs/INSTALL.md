# Qingyu IP Agent Install Notes

This module must follow the EasyAdmin8 module lifecycle:

1. Discover the module.
2. Review the manifest, migrations, menus, permissions, and host integrations.
3. Approve the module in the module center.
4. Install the approved module.
5. Enable the installed module.

The module must not be installed before administrator review approval.

## Database

The module creates:

- `qingyu_ip_agent_settings`
- `qingyu_ip_agent_operation_logs`

Both migrations include `down()` and can be rolled back by the host module migration runner. Uninstall preserves business data.

## Host Services

The module does not write host user-domain tables directly. VIP grants use `App\User\VipService`; activation code batches and generated codes use `App\User\ActivationCodeService`.
