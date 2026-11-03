# Sign2Digital — M3 Stage 2: Security, Tenant Switching and Targeted Verification

**Module:** M3 Company Management, Switching & Team Permissions  
**Date:** 2026-11-03  
**Branches:** `sign_backend/feature/m3-company-management-2026-11-03` and `sign_app/feature/m3-company-management-2026-11-03`

## Scope and boundaries

M3 Stage 1 already established full company management endpoints, team member roles, email invitations, private file storage, admin resources and Flutter company screens. Stage 2 hardens those existing paths and adds negative regression coverage. All previously approved field labels, file controls, widgets, bottom-sheet patterns and navigation are retained.

The current Laravel `main` and Flutter `main` branches remain untouched. Clients, jobs, quotes, invoices and documents continue to use existing demo/session data until their own modules are integrated. A company switch must **never** be interpreted as proof that those later modules are already company-scoped.

## Stage 2 changes completed in the M3 feature branches

### Laravel

- Require non-null verified email ownership for **listing pending invitations** and **accepting an invitation**. A valid invite cannot be consumed by a token-bearing account whose email has never been verified.
- Preserve invitation validity when an unverified account attempts acceptance; the same code works after the recipient legitimately verifies the email, and accepted codes cannot be reused.
- Added focused security tests for missing/foreign company header, database-granted permissions, permission revocation, inactive membership, suspended company, rejected incorrect file type, and 2 MB upload limit.
- Preserve existing owner role immutability, admin/member role ceilings, active membership checks, verified server-side company IDs, private file response headers and invitation token hashing.
- The administrator panel remains session-password-authenticated; the mobile app remains password **plus email OTP** authenticated. No administrator OTP was added.

### Flutter

- Validate server company IDs, duplicate company IDs and selected membership **before** modifying `ActiveCompanySession`.
- A returned company switch must identify both the requested company and the server-acknowledged active ID; inconsistent successful HTTP responses are rejected without changing the current tenant.
- Invitation acceptance similarly checks the returned company against the new active ID.
- Company-list refresh failures retain the last verified tenant and list; a valid successful empty listing clears a revoked tenant. Old in-flight refresh results no longer replace a later successful manual switch.
- Company Information now distinguishes a network/API failure from an account that truly has no company. Retry uses the existing screen and typography.
- When a mobile user starts OTP verification for another account, previous bearer and selected company context are cleared. A failed OTP cannot leave the former company's selection active.
- Added focused Flutter API tests for an unavailable server, malformed list, mismatched switch response, invalid invitation acceptance and an empty authorized list; an M2 auth test covers stale company context.
- M3 Flutter CI includes the existing company setup widgets, Sign API client and M2 mobile-auth repository regression. It is limited to relevant files and tests.

## Verification and release evidence

- **Laravel focused CI:** passed on SHA `6258d740891b02d75da23965f287f6d57120a345`: syntax/view compilation, M3 tests on isolated SQLite **and MySQL 8**, and prior M2 OTP tests.
- **Flutter focused CI:** inspect the newest run on the M3 Flutter feature branch; final success must be verified before claiming all checks passed.
- No live Hostinger migration, SMTP send or actual phone/tablet device check is claimed here. CI uses isolated database, fake mail and file storage.
- No `main` merge, production deploy, existing company row deletion or Flutter redesign is part of this stage.

## Production gate still requiring server access

Before deploying: (1) confirm active database connection uses MySQL for `u777477188_SignApp`; (2) take a recoverable database and file backup; (3) verify private Laravel disk permissions, the configured SMTP transport and queue/scheduler environment; (4) inspect pending migrations and route list; (5) run migrations and health-check only after backup; (6) verify a real owner invites a verified mobile recipient and that revoked access fails; (7) smoke-test phone/landscape company screens and switching between two companies. Do not expose the `.env`, passwords or invitation codes in logs.

## Stage 3 / Stage 4 continuation

Stage 3: perform final user-facing admin/Flutter management review while preserving original Sign2Digital styles, relevant landscape fixes and authorization-gated actions. Stage 4: run bounded acceptance checks, verified Hostinger deployment after backup, and record live endpoints plus release SHA. Do not treat unrun manual release gates as passed.
