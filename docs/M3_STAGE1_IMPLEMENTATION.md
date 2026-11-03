# Sign2Digital — Module 3 Stage 1
## Company Management, Switching & Team Permissions

**Scope source:** Sign2Digital Project Scope, Section 2 (User Account & Company Management), plus Section 20 (Security & Data Separation). The scope requires multiple companies per user, distinct company data, company logo, address, phone/email, Swiss ID, VAT, IBAN, numbering, terms, and strict authorization.

### Current branch boundaries

- Laravel: feature/m3-company-management-2026-11-03 (based on approved M2 feature code)
- Flutter: feature/m3-company-management-2026-11-03 (based on approved M2 feature code)
- Neither main branch is modified by M3.
- Administrator session login remains password-only. Mobile login still requires password + email OTP.
- No change to the existing company setup fields, Home Switch Company bottom sheet, profile shell, colors, typography, bottom navigation, or other approved UI.

### API contract (authenticated)

| Method | Route | Purpose |
| --- | --- | --- |
| GET | /api/v1/companies | Companies visible to signed-in user; current active ID |
| POST | /api/v1/companies | Create company, assign owner, select company |
| GET | /api/v1/companies/{id} | Company details for active member |
| PATCH | /api/v1/companies/{id} | Edit business fields (owner/admin) |
| POST | /api/v1/companies/{id}/switch | Server-authorized active company context |
| GET | /api/v1/companies/{id}/members | Members, roles, permissions (active members) |
| PATCH | /api/v1/companies/{id}/members/{userId} | Owner/admin role & permission edit, with role ceiling |
| DELETE | /api/v1/companies/{id}/members/{userId} | Revoke membership; cannot remove owner |
| GET | /api/v1/companies/{id}/invitations | Manage company invitations (owner/admin) |
| POST | /api/v1/companies/{id}/invitations | Send expiring invitation by email |
| DELETE | /api/v1/companies/{id}/invitations/{inviteId} | Revoke unaccepted invite |
| GET | /api/v1/companies/invitations/mine | Invitations addressed to authenticated email |
| POST | /api/v1/companies/invitations/accept | Accept 64-character code matched to authenticated email |
| POST | /api/v1/companies/{id}/files/{logo|terms} | 2 MB private company file upload |
| GET | /api/v1/companies/{id}/files/{logo|terms} | Authorized attachment download (no public path) |
| DELETE | /api/v1/companies/{id}/files/{logo|terms} | Remove private company file |

### Data model

- Existing companies and company_user tables retained. No existing columns removed.
- New company_invitations table: company, inviter, invited email, role, permissions, SHA-256 invitation-token hash, expiry, acceptance and revocation timestamps.
- Additional users.active_company_id stores server-approved current selection.
- Additional companies.logo_original_name and terms_original_name preserve upload filename metadata; actual files remain in private Laravel storage.
- Company owner is never demoted or removed by a team role API.
- Admin and member permissions come from company_user row and are enforced using the database, not by client text.
- Middleware company.permission is available for upcoming company-scoped Clients/Jobs/Documents modules.

### Flutter integration

- Existing Create Your Company screen saves to Laravel on an authenticated session; preserves all company fields.
- Existing Home Switch Company sheet uses true company IDs with server verification; duplicate company names remain distinguishable.
- Existing Business Information section reads a real company and opens the same company form for updates.
- New Team Members screen is built using approved Sign2Digital header, shadow panels, bottom sheets and colors, not a new design system.
- New Company Invitations screen lets a verified user enter their code and join without skipping mobile OTP.
- Files use authenticated multipart upload; 2 MB client/server limit and backend MIME verification.
- DemoCompanyRepository is still injectable for isolated existing UI tests and previews. This must not be mistaken for live tenant data.

### Authorization and safety

- All APIs behind auth:sanctum and active.user.
- Every company-specific endpoint checks active membership and active company; server IDs are authoritative.
- Only owner/admin can edit company data, invite users or manage files.
- Admin cannot remove the owner; company admin cannot elevate member to admin.
- Invitation codes never appear in API listing or admin UI; a valid code can only be accepted by a signed-in user of the invited email.
- Company files are stored on a private disk. Uploaded SVG files are downloadable attachments, not rendered inline in the browser.
- Company invitation emails use existing Laravel mail transport; no credentials in code.
- Admin company form gains invitation and file sections while preserving existing management fields and styles.

### Stage 1 verification

- Bounded Laravel M3 tests run against SQLite and MySQL 8, plus M2 email-OTP regression.
- Bounded Flutter M3 API repository and existing Company Setup tests; targeted analyzer for changed files.
- These isolated tests use fake mail and fake storage; production Hostinger SMTP, private filesystem permissions, iOS/Android upload UX, actual company invites and multi-device switching require Stage 2/release testing.

### Future module boundary

- Clients, Jobs, Documents, Quotes and their data repositories remain on approved demo implementations until their corresponding modules are connected. M3 provides tenant ID + backend authorization for those modules; M3 by itself does not convert their records to multi-tenant storage.
- Stage 2 covers security audit, further negative authorization tests, offline/mobile edge cases, MySQL verification, UI regressions and deployment readiness.

### Release gate

Do not deploy before verifying the current backend and Flutter branch heads, existing Hostinger MySQL database backup, migrations preview, MAIL_MAILER for company invitation emails, filesystem write access to private Laravel storage, and migration success. No production deployment or main merge is performed as part of M3 Stage 1.
