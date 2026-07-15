# Module Admin Smoke Production Parity Design

## Problem

The production module-center Blade view contains the EasyAdmin page shell and the
`currentTable` table, but no visible `模块中心` heading. The admin smoke fixture adds
a synthetic title and heading, so the smoke suite passes locally while the same
script fails against production after all real page checks succeed.

## Decision

Keep the production module-center UI unchanged. Align the fixture with the real
Blade view and make the smoke contract assert the durable page structure:

- the response is a valid authenticated admin page;
- `id="currentTable"` is present;
- `lay-filter="currentTable"` is present;
- the separate module JavaScript check continues to cover lifecycle action tokens.

Do not replace the title assertion with a status-only assertion, and do not add a
heading solely to satisfy the smoke script.

## Verification

1. Remove the synthetic fixture heading and run the fixture-backed admin smoke
   test to reproduce the production failure.
2. Remove the obsolete title assertion from the smoke script and rerun the test.
3. Run the module-center and deployment-acceptance regression tests.
4. Deploy the two script/fixture changes and rerun the production admin smoke.

## Scope

This change does not alter module lifecycle behavior, permissions, routes, views,
database state, or the active Qingyu release.
