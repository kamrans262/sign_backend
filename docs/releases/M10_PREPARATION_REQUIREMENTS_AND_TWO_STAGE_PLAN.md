# Sign2Digital — M10 Addenda Management: preparation audit and two-stage delivery plan

**Prepared:** 2026-11-04  
**Status:** Requirements and repository audit; **NO M10 feature implementation or deployment yet**  
**Source:** `Sign2Digital – Project Scope.docx`, Section 7 (separate addendum after signing), Section 8 (document integrity/history), UI Flow H / Screen 31 (Create Addendum), document details Screen 29. The bilingual scope describes one product; French/English repetitions are not separate requirements.

## Module identity and release baseline

- **M10 = Addenda Management** (the updated M1–M9 delivery sequence supersedes an earlier 25-module roadmap numbering).
- Flutter base: `kamrans262/sign_app` / `feature/m9-document-management-2026-11-04` at `b99a72ea760ff8c85c953fd2deb0cbec7e9aef89`.
- Backend base: `kamrans262/sign_backend` / `feature/m9-document-management-2026-11-04` at `2ad17628a8bf4fcdcdcde9a449ae3b938bc192d3`.
- **Development branches created:** `feature/m10-addenda-management-2026-11-04` on both repos.
- Hostinger is currently on deployed M9. No production migration, SSH action, branch switch, or secret changes belong to this preparation stage.
- Do not merge, overwrite, reset, force push, or change `main` / `development`. Preserve all local iOS/macOS platform edits, `pubspec.lock`, CocoaPods files, and Mac stashes.

## Scope-derived requirement traceability

| ID | Requirement | Baseline / gap | Stage |
|---|---|---|---|
| M10-R01 | Create a *separate* addendum to change signed work/price; signed source remains immutable | Flutter local draft exists; backend addendum entity absent | 1 |
| M10-R02 | Reference the actual original document and preserve exact company, job, client, source revision and SHA when available | M9 unified catalog exists; picker currently demo/local; original linkage uses `quote?.jobId` and may lose M9 catalog job/client fields | 1 |
| M10-R03 | Preserve Create Addendum Screen 31 fields: reference, change description, additional work, quantity, price, VAT, total; also existing number, title, date, logo, review, draft, AI voice controls | Existing screen has these fields, but persistence is `AddendumDraftSession` only | 1 |
| M10-R04 | Calculate subtotal, VAT and total accurately in integer minor units and VAT basis points, validate client and server | Flutter `AddendumDraft` calculates CHF values; no Laravel-side authoritative addendum calculation | 1 |
| M10-R05 | Save and reload drafts across app launches/devices with concurrency/revision protection; no fabricated success | Existing `save` stores only in a static Flutter map | 1 |
| M10-R06 | Review explicitly and approve before sending a document to the customer; freeze authoritative addendum PDF | Existing review is a local bottom sheet; unsigned PDF local-only | 1 |
| M10-R07 | Send *separate addendum* for secure remote/customer signature, optionally in person where existing UX supports it; expiry, revoke, replay protection, receipt and final signed PDF | Existing `AddendumSignatureRequestService` callback is not connected; M8 signing model assumes a `service_quote_id` | 1 |
| M10-R08 | Do not regenerate/replace/overwrite original M7 approval PDF, M8 signed quote PDF, or signed addenda; validate digest on read | M7/M8/M9 immutability protections exist; M10-specific artifact storage absent | 1, 2 |
| M10-R09 | Expose addenda in M9 Documents category, document details, preview, related originals, versions, attachments, history, client and job records | M9 supports generic uploaded PDFs labeled `addends`, *not* structured persisted addenda | 1 |
| M10-R10 | Separate fully manageable admin sidebar **Addenda** with listings/filters, source, status, review, delivery, audit and downloads | Admin currently has Quotes, Signatures and Documents; no Addenda management module | 1 |
| M10-R11 | Tenant isolation, role permissions, permissions enforcement on every read/write/download/admin action | M9 uses `documents.read/write`, M8 `quotes.read/write`; define and enforce addendum permissions compatible with M3 roles | 1, 2 |
| M10-R12 | AI voice (existing UI) calls server proxy with configured OpenAI key; never asks for/stores provider key on client; missing amounts never invented and human accepts suggestions | Existing addendum voice service calls OpenAI directly and asks user for temporary key | 1, 2 |
| M10-R13 | Retain existing typography, colors, components, screens, actions; correct landscape full-width issues while integrating | Current UI partially local/demo, no redesign authorized | 1, 2 |
| M10-R14 | Automated backend and Flutter regression/security tests, controlled backup/preflight/migration/deployment and real-device acceptance | No M10 tests/CI/release scripts yet; M9 backups/releases reusable | 2 |

### Eligibility and data integrity rules

1. Initial M10 support is **a verified previously signed quote/document with a real signed artifact**, not a fabricated signature inferred from a `status` label. If source is only an approved-but-unsigned quote or a legacy M7 approval without hash, do not silently treat it as signed. A separate eligibility decision and correct future workflow are required before allowing such a source.
2. Reference by **immutable source ID and revision/hash**, not a matching number, customer or job title. Confirm company ownership and job/client associations on the server.
3. Source PDF remains byte-for-byte unchanged; addendum is a separately versioned entity and a separately signed artifact. Changes after signing require another addendum, never an overwrite.
4. Do not present unsigned previews, email actions or client share sheet taps as proof that a customer received or signed a document.
5. Only send actual frozen, revision-bound and hash-verified PDFs. Keep tokens hashed, short-lived and revocable; no account required for authorized customer signing. Require authorization and tamper protection.
6. Use integer minor units / basis points, exact currency and explicit user-entered values; prevent AI from inventing financial data.

## Audited code touchpoints (current M9)

**Flutter:** `lib/features/documents/presentation/screens/create_addendum_screen.dart`, `domain/addendum_draft.dart`, `domain/addendum_signature_request.dart`, `services/addendum_pdf_service.dart`, `services/addendum_voice_extraction_service.dart`, `presentation/screens/document_details_screen.dart`, `presentation/screens/documents_screen.dart`, `data/remote_document_repository.dart`; existing tests `create_addendum_screen_test.dart`, `phase5_addendum_flow_test.dart`. Preserve these and extend them rather than introducing duplicate UI widgets.

- `_repository = widget.repository ?? const DemoDocumentRepository()`: document picker can use demo instead of authenticated M9 catalog.
- `_saveDraft` and `_create` manipulate `AddendumDraftSession`, and receipt map is memory-only.
- Source job/client currently uses `_linked?.quote?.jobId/clientId`, but a server M9 DocumentRecord has first-class `jobId` and no loaded QuoteReviewDraft for signed/verified quotes.
- Current `_chooseDocument` filters out addenda but does not enforce verified signed-source eligibility.
- `_askApiKey` prompts for OpenAI credentials in Flutter; replace with the server-side AI proxy already established for quotes.
- Local `AddendumPdfService` produces explicitly unsigned bytes. This must not become an authoritative approved or signed artifact without server verification and freeze.
- `DocumentDetailsScreen` currently shows Create Addendum for all quote-category documents and Send for Signature only for locally-created session drafts. Adjust to verifiable eligibility and persistent workflow.
- `_dateText`/date selection currently has a hard-coded Figma placeholder; preserve visual style but do not represent placeholder as saved date.
- CHF is hard-coded in current addendum financial UI: bind currency to authoritative source/company as appropriate; do not assume legal Swiss invoice formatting.

**Laravel:** `routes/api.php`, `routes/web.php`, `app/Services/DocumentCatalogService.php`, `app/Services/SignatureWorkflowService.php`, `app/Services/SignatureCompletionService.php`, `app/Http/Controllers/Admin/DocumentController.php`, signature migrations and M9 document-management migration.

- There is **no dedicated addendum API route, controller, migration, signing target or admin module** as of M9.
- M8 `signature_requests` and completion services are tied to `service_quotes`: cannot simply point them at addenda by reusing an ID. Reuse hardened signing logic through a safe addendum-specific model/service or carefully validated abstraction **without modifying existing M8 rows**.
- M9 `managed_documents.category='addends'` is a generic uploaded file, not a signed/approved structured addendum.
- Admin sidebar currently exposes Documents and Signatures, not Addenda.

## Proposed Stage 1 — Full module implementation (one development stage)

1. Baseline both M10 branches from confirmed M9 heads; inventory UI routes/actions and verify expected fields/approved styles. Add traceability tests.
2. Define additive schema and migrations for addenda, draft/edit revisions, frozen PDF artifacts, signature requests/signed artifacts, and audit history; tie to source document ID, digest/revision, company, job and client. Ensure normal users can access only their company, and admin can manage all with transparent audit. Use unique constraints to stop duplicate addendum numbers and repeated signing.
3. Implement authenticated Laravel API: list/get/create/update draft, review/approve, canonical PDF preview/download, sign request status/resend/revoke, attachments/history, separate public customer view/sign/receipt/download. Add server-side money validation, optimistic revisions and state transitions; never mutate signed original.
4. Extend M8 safe signing infrastructure without breaking quote signing, and persist immutable signed addendum PDFs with SHA-256/private storage, proper email/link delivery and backend-verified status.
5. Build **Addenda** as its own admin sidebar section with list, filters, view, create/edit draft, review/approve, secure send/status, versions, attachments, history, and downloads. No duplicate platform console, no irrelevant dashboard cards.
6. Wire the existing Create Addendum screen and original Document Details/More action to live repository, scoped picker, persisted drafts, review/approval, signature request, signed PDF and job/client document relationships. Keep 20px horizontal padding, Inter, #166282 and existing components.
7. Route existing AI Voice flow via backend OpenAI proxy, preserving edits and requiring review; use server environment configured earlier if available. Do **not** request an API key inside Flutter or invent missing amounts.
8. Add stage-one feature tests and a clear schema/API/UI/admin traceability checklist. **No Hostinger deployment in Stage 1.**

**Candidate API contract (to finalize while implementing Stage 1):**

- Authenticated read: `GET /api/v1/addenda`, `GET /api/v1/addenda/{id}`, `GET /api/v1/addenda/{id}/pdf`, `GET /api/v1/addenda/{id}/history`, `GET /api/v1/addenda/{id}/signature-requests`.
- Authenticated write: `POST /api/v1/addenda`, `PATCH /api/v1/addenda/{id}` (draft, revision-guarded), `POST /api/v1/addenda/{id}/approve`, `POST /api/v1/addenda/{id}/signature-requests`; secure resend/revoke and admin management.
- AI voice via authenticated backend `POST /api/v1/addenda/prepare/voice` (rate-limited and company-scoped).
- Public secure addendum signing capability: separately scoped token preview, submit, receipt and signed-PDF endpoints, with expiration/revocation and no anonymous listing.
- Names are proposed; **not deployed and not asserted to exist**.

## Stage 2 — Security, regression, acceptance, release and Hostinger deployment

1. Laravel test matrix: cross-company/role IDOR, source eligibility, quote/addendum SHA integrity, immutable PDFs, missing originals, canceled/expired/reused links, concurrent edit/approve/sign races, duplicate requests, unapproved or stale PDF denial, non-ASCII names, amount/VAT rounding and currency.
2. Flutter tests: save/reopen persisted drafts, source picker and job/client cross-links, review, approved PDF, sign request, receipts, document history, no fake status, with iOS/Android phone and landscape widths (320px, 430px, 720x390, tablet).
3. Admin test matrix: list/filter/create/edit/approve/download/send/revoke/permissions/audit; no old M7/M8/M9 functionality regression. Include automated CI in both repos and preexisting baseline comparisons.
4. Production release gate: additive migrations, M9 preflight, exact branch/commit, clean worktree and **fresh backup** of MySQL, private original+signed PDFs and configuration with SHA checks; isolated database restore test where possible. Never use `migrate:fresh`, destructive resets, force pushes, or automatic destructive rollback.
5. Controlled Hostinger migration/deployment, then read-only post-deploy preflight and real signed addendum end-to-end acceptance (test data isolated from genuine client records); verify delivery separately if SMTP is available.
6. Document completion/known limitations and handoff; **no claim of production-ready until tested**.

## M10 dependencies and out-of-scope boundaries

- Depends on M3 company permissions, M5 jobs/client association, M6 structured quotes/AI proxy, M7 immutable approved PDFs, M8 signed-document workflow and M9 document catalog/history.
- A legacy approved quote without an intact original PDF is not upgraded by regenerating a visually similar file.
- Do not create a new addendum screen/design, a new documents module, a second signing subsystem detached from integrity controls, or store raw OpenAI credentials in Flutter.
- No OpenAI key is required merely to prepare this plan. Backend AI extraction in Stage 1 must use a server-configured credential; request only if the backend has no usable key.

## Exit criteria

**Stage 1 exit:** all specified server, admin and Flutter actions implemented on the M10 feature branches, tests/API/UI smoke checks in CI, no server production modifications.  
**Stage 2 exit:** security + regression gates pass, verified backup/restoration plan, controlled Hostinger release, real-device and customer signing acceptance, correct history/relationship mapping, and exact commit/release record.
