# Sign2Digital — M3 Stage 3: Admin Panel and Flutter Integration Review

**Module:** Company Management, Switching & Team Permissions  
**Date:** 2026-11-03  
**Source:** Original Sign2Digital scope (company accounts, team permissions and data separation), approved Flutter screen source, and M3 Stage 1/2 implementation notes.

## Scope and approved design retained

- No screens, form fields, dropdown options, demo navigation, company switcher options, or existing visual styling have been removed.
- The existing admin shell (Sign2Digital branding, sidebar, panel classes, inputs and button styles) is reused. Administrator login remains password-only; mobile login still uses email OTP.
- Flutter retains the approved Inter typography, brand palette, shared selection bottom sheet, 20px horizontal spacing, header widgets, and original portrait max-width designs.
- Other business modules still contain demo/session stores until their respective backend modules; M3 company switching is not misrepresented as automatic persistence of Jobs/Clients/Invoices/Quotes.

## Administrator company management — Stage 3

The existing Company page already supports full Swiss business identity fields, company status, owner assignment, users, logo / terms files, and emailed invitations. Stage 3 fills the management gap for existing team memberships:

1. Add an **Edit Access** link beside each non-owner team member in Companies > Manage Company.
2. Reuse the existing admin panel design on the new **Manage Team Member** page; retain the company and user identifiers for context.
3. Authorized platform administrators can edit **company role** (member / admin), **company membership status** (active / inactive) and the same 16 explicit tenant permission grants recognized by the mobile API.
4. Owner edit is always blocked; owner transfer stays in Company Details. Other-company membership cannot be accessed through an unrelated company route.
5. Inactive membership or removal clears `users.active_company_id` if the removed company was selected; unrelated company memberships remain untouched.
6. Backend routes: `GET /admin/companies/{company}/members/{user}/edit` and `PATCH /admin/companies/{company}/members/{user}`. They inherit existing authenticated **platform.admin** middleware.
7. Changes are audit-logged, validated, and transactionally persisted to the same `company_user` pivot used by Flutter APIs. No new table or migration is needed.

The platform-admin user cannot bypass the owner immutability guard merely by submitting an owner URL, and an unauthorized ordinary user cannot access edit routes. Validation rejects unknown grants and invalid roles/statuses.

## Flutter integration and responsive width fixes

| Existing screen | Stage 3 behavior |
| --- | --- |
| Create Your Company | Keeps all nine form fields, image/PDF upload controls and original portrait layout; landscape uses the available width instead of fixed 900px / 520px nested caps |
| Team Members | Preserves role and permission sheets, member actions and invitations; removes 720px landscape cap |
| Company Invitations | Keeps code entry and original form controls; uses landscape width rather than fixed 720px |
| Business Information | Preserves read-only display, edit/team/invitation routes and error retry; removes landscape cap and refreshes the company profile after an accepted invitation |
| Home company switcher | Keeps the exact shared Switch Company sheet and current company flow; removes 720px landscape cap |
| More | Retains all 13 client-review menu entries and existing navigation; removes 560px landscape cap |

Portrait widths remain unchanged. Only M3-linked screens and navigation were adjusted, not unrelated fixed-width screens belonging to later modules.

## Focused acceptance coverage

**Laravel:** `tests/Feature/M3CompanyManagementTest.php` includes role/grant/status editing, cross-company ID guards, owner immutability, platform-admin middleware, active tenant revocation on deactivation/removal, plus pre-existing M3 security and M2 OTP checks. Run only the M3 focused workflow (SQLite and MySQL 8 in CI).

**Flutter:** `test/features/company/m3_landscape_layout_test.dart` checks wide viewport usage and preserved controls for company setup, team, invitations, Home, More and Business Information. The existing company setup, remote company repository and mobile authentication tests continue to run in the M3 focused CI. No full unrelated CI regression sweep is required.

## Deployment / validation boundary

This stage modifies only the isolated M3 feature branches for Flutter and Laravel. No `main` merge or Hostinger production migration/deployment has been performed. Backend and Flutter focused CI results must be read back before declaring the stage verified. User-device portrait/landscape and real Hostinger SMTP/private-storage/DB checks remain Stage 4 gates.

## Stage 4 checklist

1. Confirm both feature branch heads and passing focused CI.
2. Back up Hostinger production MySQL and private uploads; preview migrations and confirm DB driver is MySQL.
3. Deploy only after release checks, with a reversible version and health / API route verification.
4. Verify real mobile OTP, create/switch/edit company, second-company isolation, company-owner protection, invite acceptance, email delivery, private logo and terms access, role change/deactivation and audit entries.
5. Check portrait, landscape phone and tablet using the approved Sign2Digital styles; retain the UI baseline and label remaining backend-unconnected demos accurately.
