# Qingyu Audit Secret Masking Design

## Goal

Prevent credential and activation secrets from reaching Qingyu operation logs regardless of whether request keys use camelCase, snake_case, kebab-case, or nested payloads.

## Problem

`AuditLogService` masks an explicit lowercase key list. It lowercases input keys but does not remove separators, so aliases are inconsistent: `newPassword` is covered by a manually listed `newpassword`, while `activationCode` becomes `activationcode` and is not covered by `activation_code`. The Qingyu client accepts `activationCode`, making an unmasked one-time code possible in `masked_payload_json`.

Exact alias lists also age poorly as desktop and third-party clients introduce naming variants.

## Canonical Key Policy

Before classification, convert a key to lowercase ASCII and remove every non-alphanumeric character. For example:

- `activationCode`, `activation_code`, and `activation-code` become `activationcode`.
- `refreshToken` and `refresh_token` become `refreshtoken`.
- `api-key` and `api_key` become `apikey`.

A key is sensitive when its canonical form:

- contains `password`, `passwd`, `token`, `secret`, or `credential`;
- is `authorization`, `cookie`, `session`, `apikey`, or `privatekey`;
- is `code` or ends in `code`.

The code rule intentionally prefers confidentiality over audit detail. It may mask non-secret fields such as `statusCode`; audit action/result/error-code columns retain the operational outcome separately.

## Recursive Behavior

Sensitive-key classification happens before array recursion. A sensitive key masks its entire value, including arrays or objects. Non-sensitive arrays are traversed recursively so secrets nested under ordinary containers are still masked.

Email and mobile masking continue after canonical classification. Safe scalar and structured values remain unchanged.

EA8 activation codes keep the existing operationally useful representation `EA8-****-LAST4`; all other sensitive values become `******`.

## Compatibility

- No migration or response change is required.
- Existing exact sensitive keys remain masked.
- Existing email, mobile, and EA8 last-four behavior remains unchanged.
- The change affects only future audit rows; historical logs are not rewritten in this batch.

## Testing

- `activationCode` and separator variants never retain the full activation code.
- Camel, snake, and kebab variants for passwords, tokens, API keys, secrets, credentials, authorization, cookies, and sessions are masked.
- Sensitive array values are masked as a whole.
- Secrets nested inside non-sensitive arrays are masked recursively.
- Safe notes remain visible and email/mobile masking remains unchanged.
- The full Qingyu module test remains green.
