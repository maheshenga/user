# User Portal Dashboard Polish Design

## Goal

Make the user dashboard useful for real user testing and early operations by replacing raw JSON boxes with readable summaries for VIP, balance, ledger, invites, invite records, withdrawals, activation code redemption, and withdrawal requests.

## Problem

The current user portal is now reachable and smoke-tested, but the dashboard still behaves like an API debug page:

- VIP, balance, invite, ledger, and withdrawal panels render raw JSON.
- Users cannot quickly understand their current VIP level, balance, invite code, invite records, or withdrawal status.
- Empty states and failed states are generic, so manual testers need to inspect JSON to know whether a flow worked.
- Activation and withdrawal forms submit correctly, but successful submissions do not present a clear refreshed summary.

This is acceptable for endpoint verification, but not for long-running product testing or operational review.

## Scope

Included:

- Keep the existing `/u/dashboard` route and all existing `/user/*` APIs.
- Add semantic dashboard containers for VIP, balance, ledger, invite, invite records, and withdrawals.
- Update `portal.js` to render friendly HTML summaries instead of raw JSON when known payload shapes are returned.
- Preserve a raw fallback for unknown payloads so future API changes remain visible instead of blank.
- Add empty states for no ledger rows, no invite records, and no withdrawals.
- Refresh affected panels after activation code redemption and withdrawal request.
- Add focused tests that assert the dashboard exposes the rendering hooks and that the smoke script still covers the dashboard flow.

Excluded:

- No new backend business rules.
- No new API endpoints.
- No admin changes.
- No payment, SMS, email, or payout provider integration.
- No SPA migration or frontend build tool.
- No broad visual redesign beyond dashboard readability.

## Recommended Approach

Extend the existing Blade dashboard and vanilla `portal.js`. This keeps the phase small and compatible with the current Laravel/Blade structure while making visible behavior materially better.

Alternatives considered:

- Build a SPA layer: too broad for this phase and unnecessary.
- Add backend view models: useful later, but current APIs already return enough data for a readable MVP.
- Keep raw JSON and document it: low effort, but does not solve the user's complaint that visible changes are hard to see.

## UI Behavior

Dashboard panels should render:

- VIP: current level, status, started/expired time if present, and activation result after redeem.
- Balance: available balance, frozen balance, total earned/withdrawn if present.
- Ledger: list of recent balance ledger rows with amount, direction/type, reason, and time.
- Invite summary: default invite code, invite URL if present, parent invite info if present, first-level and second-level totals if present.
- Invite records: readable list/table of invited users and level.
- Withdrawals: readable list/table with amount, account info, status, review/payout fields if present.

Unknown payload fields should remain accessible through a compact raw JSON fallback inside the panel.

## Error Handling

- If `/user/session` fails, protected panels remain disabled as in P11.
- If a known dashboard API returns `code !== 1`, the corresponding panel shows the API message.
- If a payload shape is missing optional fields, the renderer shows `-` rather than throwing.
- If a network or parse error occurs, the panel shows the error message.

## Testing Strategy

Use TDD:

- Add a page-level feature test proving the dashboard has stable semantic hooks for each readable panel.
- Add a smoke fixture/script assertion if a new hook or output marker is needed.
- Run focused user portal tests and the full SQLite suite.
- Run static checks for PHP and JavaScript.
- Run the real Laravel HTTP smoke script after implementation.

## Acceptance Criteria

- `/u/dashboard` still loads and is protected by the P11 session preflight.
- Dashboard API data is rendered in readable HTML summaries instead of only raw JSON.
- Existing activation and withdrawal submissions still work and refresh the right panels.
- Unknown payloads still have a safe raw fallback.
- P12 smoke remains green.
- Full SQLite suite remains green.
- Phase is reviewed, merged to `main`, and pushed.

## Spec Self-Review

- Placeholder scan: no TODO/TBD markers remain.
- Consistency check: all requirements target the existing user dashboard and existing APIs.
- Scope check: this is frontend readability only and does not alter business rules.
- Ambiguity check: included and excluded surfaces are explicit.
