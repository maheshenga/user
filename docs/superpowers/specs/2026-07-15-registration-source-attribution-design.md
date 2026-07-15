# Registration Source Attribution Design

## Goal

Make `user_account.source_module` a server-controlled registration context instead of a client-controlled profile field.

## Problem

The public `/user/register` endpoint currently accepts `source_module`, and `UserAuthService::register()` reads it from the same payload as email and password. Any anonymous caller can therefore claim that a core registration belongs to `qingyu_ip_agent` or another module. This corrupts module membership reports and makes future attribution-based policy or settlement unsafe.

The versioned module API and Qingyu module also need to preserve legitimate attribution. Their controller/service layer already knows the selected module, so that context must be passed separately from user input.

## Trusted Boundary

`UserAuthService::register()` changes to:

```php
public function register(array $payload, string $ip, string $sourceModule = 'core'): array
```

The service never reads `source_module` from `$payload`. It normalizes and validates only the explicit third argument. This makes the call site responsible for establishing module context and keeps the default safe for core registration and existing internal callers.

## Call-Site Rules

- `App\Http\Controllers\user\AuthController` does not accept or validate `source_module`; it calls the service without the third argument, producing `core`.
- `App\Http\Controllers\Api\V1\AuthController` validates and authorizes its required `module` selector, then passes it as the third service argument. It does not copy the selector into user payload data.
- `Modules\QingyuIpAgent\Services\ClientApiService` passes its private `MODULE` constant as the third argument. A client-supplied `source_module` is ignored.
- Internal code that intentionally creates a module-owned user must pass the explicit third argument. Passing a `source_module` payload key alone produces `core`.

The generic versioned API treats `module` as its route-level module selector, not as editable member profile data. `ModuleApiPolicy::assertAvailable()` remains mandatory before registration.

## Compatibility

- The database column and existing records are unchanged; no migration is required.
- Core registration response shapes are unchanged except spoofed `source_module` input now returns `core`.
- Versioned Qingyu registration and Qingyu module registration continue to return `qingyu_ip_agent`.
- Direct callers that omit the new argument continue to create core users.
- Existing records cannot be classified reliably as legitimate or spoofed, so this change does not rewrite history. The system is not yet public, allowing operators to inspect or reset test records separately.

## Error Handling

The existing source format rule remains: lowercase letters, numbers, dots, hyphens, and underscores, with a maximum length of 80 characters. Invalid trusted source arguments continue to throw the existing Chinese `InvalidArgumentException`.

## Testing

- Public registration ignores a spoofed `source_module` and stores `core`.
- A direct service payload cannot spoof attribution when the third argument is omitted.
- An explicit trusted service argument stores the requested module.
- Invalid explicit module context is rejected.
- Versioned module API registration still stores its validated `module` selector.
- Qingyu module registration still stores the private module constant.
- User authentication, API token, and Qingyu module regressions remain green.
