# Sign2Digital M18 — General AI Document Creation
**Status:** PREPARED ONLY; no M18 implementation, migrations, external sends or deployments.  
**Prepared:** 2026-11-05  
**Existing shared development branch (Flutter + Laravel):** `feature/m12-site-reports-meeting-minutes-2026-11-04`

## 1. Canonical scope and correct module numbering

Source: original bilingual `Sign2Digital – Project Scope.docx`, **§16 General Document Creation / Création de documents généraux**, §§19–21 AI, separation, customer access, and **P. General AI Document Creation, screens 57–59**.

- **57 Create Document:** Letter, Statement, Power of Attorney, Acceptance Document, Custom Document; Voice or Text.
- **58 AI Document Editor:** generated document, rich-text edit/formatting, AI suggestions, attachments; Edit, Format, Sign, Save, Generate PDF.
- **59 General Document Preview:** Sign, Generate PDF, Send, Link to Client, Link to Job.
- The user may **manually create** and use documents **without OpenAI credits**. AI suggestions are drafts, not sent or approved autonomously.
- General documents can be optionally linked to the same-company client and/or job. Both are optional, but any provided relation must belong to the active company.
- Original scope §22: first three **new document creations** for trial; edits and signatures do not consume additional creations. Prepare accurate usage events/integration seam; do **not** turn on subscription blocking, prices, Stripe or billing in M18.
- **Current numbering** has M16 Deposits and M17 Invoices. Do not follow the obsolete 25-module historical numbering that names M18 Deposits; current **M18 = General AI Document Creation**.

## 2. Verified pre-M18 code baseline

- Laravel `kamrans262/sign_backend` HEAD `45ef281af4fdc1ba7ac873a3dd5d222c195e4216` (M17 Stage 2 green).
- Flutter `kamrans262/sign_app` HEAD `729f32d4310c1524c904d4d4a363cfd4da840901` (M17 Stage 2 green).
- Repositories already have **M9** `DocumentCatalogService`, `DocumentController`, private document versions/attachments, and `Documents` Admin. These provide catalog/history/download but **do not provide a general document rich-text authoring and approval system**.
- The existing **M8 signature services are bound to approved quotes**. Do not pass an M18 document ID to a quote-signing route or claim general-document signatures are implemented. Add a document-type-safe adapter/new request record while preserving M8.
- Flutter current paths:
  - `lib/features/documents/presentation/screens/ai_documents_screen.dart`: existing AI Documents hub; default fixtures are explicitly demo records.
  - `lib/features/documents/presentation/screens/generate_ai_document_screen.dart`: existing job/section-based generation form; currently needs a connected generator. Keep this workflow; extend into proper general-document creation without replacing its existing UI.
  - `lib/features/documents/presentation/screens/document_details_screen.dart`: existing reusable detail/tabs/history/PDF components.
  - `lib/features/documents/domain/document_record.dart` and `lib/features/documents/data/remote_document_repository.dart`: existing M9 catalog and DTO/read logic.
  - `lib/features/jobs/presentation/screens/convert_document_screen.dart` / existing note-to-document flow: reuse the server-approved conversion boundary if applicable.
- Existing M9 managed uploaded PDFs are **not** M18 authored editable documents. Preserve both.
- M17 invoice PDFs and send semantics are a reference for immutable issue/hash/link/email architecture; **not** a reason to modify invoice billing or PDFs.
- OpenAI API credit/quota previously returned 429 insufficient_quota; manual document authoring must remain fully operational. Live AI acceptance remains conditional on provider funding and explicit configuration.
- All existing screens, admin modules, records, historical PDFs and customer links must remain intact.

## 3. Non-negotiable UI and architecture constraints

- **Never replace, redesign, center-narrow, or relabel existing Flutter screens without an explicit requirement.** Preserve theme tokens `#166282`, `#122536`, `#667085`, `#8A9AA8`, `#D9E2EA`, `#E3ECF0`, Inter font, radius 10px, 20px horizontal padding, bottom nav, dropdown bottom sheets, AI voice popup, and shared buttons/cards.
- Use the established **same Flutter and Laravel branches**, making additive changes and committing after green tests. No `main`, `development`, Hostinger production, secrets, `.env` or source UI baseline changes.
- Prefer shared existing document repository, scope guards, event/audit components, client/job pickers, SVG/components and PDF handling. Avoid dual source-of-truth and extra top-level Documents pages.
- Admin must show/manage all M18 documents and their associations, revisions, attachments, signatures and delivery audits with the established Admin sidebar/page styles. Separate permissions for read vs write/approval/send; company isolation always.
- M18 power-of-attorney templates are **draft conveniences, not legal advice or evidence of jurisdictional validity**; no automatic assertion that a particular signature method meets Swiss statutory formalities.

## 4. Stage 1 — Full General Document Authoring, Backend/APIs/Admin/Flutter

### A. Laravel schema and domain
1. Add company-scoped general-document authorship records (suggested: `general_documents`) with type `letter|statement|power_of_attorney|acceptance|custom`; title, locale, created_by/updated_by, client_id/job_id optional, status `draft|reviewed|approved|archived`, revision and optimistic `lock_version`; untrusted input size limits.
2. Preserve editable version history using append-only `general_document_revisions` with canonical sanitized content, author, source `manual|ai_draft|note_conversion`, timestamps and content digest. Draft edits append revisions; never replace a previously approved source.
3. Add `general_document_events` for create/edit/AI suggestion accepted/rejected/review/approval, linked entities, attachment changes, later signature and delivery; append-only. Add private `general_document_attachments` with MIME validation, byte limits, SHA-256, company/job scoping and soft-removed state.
4. **Rich text:** implement a constrained block/inline document schema (paragraphs, headings, lists, bold/italic, line breaks, optional signatures/attachments) and a single canonical renderer. No arbitrary HTML, script, external URL fetch, HTML-to-PDF execution or blind Markdown trust. Explicitly validate UTF-8, total size, forbidden nested structures, and escaping for Blade/PDF.
5. On create/modify, verify active company, authorized user, source client/job company ownership, and that a selected client belongs to the selected job when both are specified. Use optimistic-lock conflict 409, idempotent create/request keys and stable document numbering; no cross-company references.

### B. API and workflow
6. Add `/api/v1/general-documents` list/create, `/{id}` show/update, `/{id}/revisions`, `/{id}/attachments`, review and approve endpoints under existing Sanctum/company-access permission middleware; standardize JSON `data`, no-store responses. Verify 403/404 tenant leakage policies.
7. Separate **draft/edit** from explicit **review/approve**. An AI response is an editable suggestion requiring business owner/admin confirmation. Draft or unreviewed content must not acquire a share link, signature capability, PDF marked official, or send side effect.
8. Integrate M18 authored records into **M9 Documents catalog exactly once**, as a distinct `general` kind (or canonical linked managed-document record), with version, filter, history, job/client and PDF entitlement resolution. Do not copy official PDF bytes into multiple editable catalog entries or rewrite other M9 kinds.
9. Provide PDF preview only as nonauthoritative draft if needed in Stage 1; official issue/freeze belongs to Stage 2. Ensure no local Flutter-generated draft looks like a signed, approved business document.
10. For original scope §22, record **idempotent new-document creation events** once per logical create, with an auditable counter/extension seam; **do not** implement subscription enforcement/Stripe in M18.

### C. AI-assisted text and voice (draft-only)
11. Add a server-owned preparation endpoint with type-safe request fields: document type, optional client/job context, language, rough notes or authorized transcription, user instructions and optional section selections.
12. If OpenAI provider unavailable/429, show an existing-style actionable error and keep manual editing/saving available; never fabricate AI completion. No API keys in Flutter, logs, prompts returned to users or repository.
13. Sanitize AI output into the same rich-text schema; use **only authoritative, company-authorized** client/job source data. Missing names, prices, dates, measurements and legal clauses must remain blank/flagged, never invented. Prevent prompt injection from note content, and cap response length/cost/timeouts.
14. User must explicitly choose whether to accept a suggestion into the draft; every accepted AI output is attributed and revisioned. Translation/summarize controls in the AI hub should only be enabled where the endpoint is genuinely implemented.
15. Reuse current `AiVoiceFlowDialog` and voice recorder patterns; make the voice pathway opt-in and permission aware. Voice and typed text enter the same draft/review lifecycle; don't let recording trigger send/sign automatically.

### D. Fully manageable Admin module
16. Add **General AI Documents** admin list/filter/search, company/user/linked job/client/type/status, detail/editor (when authorized), revision timeline and read-only audit, attachments and draft/review/approval controls. Reuse existing Admin components and sidebar style.
17. Keep signed/approved snapshots read-only; privileged operator may revoke/unpublish using an explicit, audited corrective workflow, not silently mutate source material.
18. Add staff roles/permission checks and export where scope authorizes; never put customer private URLs, raw tokens, full document bodies or personal data into general audit log text.

### E. Flutter integration and tests
19. Connect existing `ai_documents_screen.dart`, `generate_ai_document_screen.dart` and reusable document detail/preview flow to a `RemoteGeneralDocumentRepository` with company-switch rejection, malformed response rejection, slow-network retry/error states and no merge with demo fixtures after authentication.
20. Complete the missing **general letter/custom-document form and rich-text editor** inside established design patterns; don't erase the existing six-section job document form. Support type picker, manual entry, AI suggestions, optional client/job links, file attachments, Save Draft, Review and Approve.
21. Show accurate statuses; disable Sign/Official PDF/Send until Stage 2 provides server-authoritative flows. Preserve the selected document and its unsaved edited text across controlled navigation/error conditions.
22. Tests: migrations, permission boundaries, company-switch race, client/job mismatch, version concurrency, duplicate retry, valid/invalid rich text, XSS escaping, attachments and hashes, quota-event idempotency, AI failure/no hallucinated business facts, Admin navigation, Flutter widget/responsive/screen regression, M9/M11/M17 regressions.

**Stage 1 exit gate:** all three original flows are connected to a real server-authoritative authoring record; creation, editing, AI draft/manual fallback, linking, revisions, attachments and Admin are code-complete with green CI. No production deployment.

## 5. Stage 2 — Authoritative PDF, Signing, Customer Access, Sending, Release Hardening

1. Render server-owned A4 official PDF from **approved, canonical revision** using reusable document typography, accurate company branding/metadata, page breaks, optional attachments, locale-safe text/Unicode. Store immutable private PDF path, SHA-256, length, revision ID and source digest.
2. Separate **Preview (draft)** from **Issue official PDF**. Issue idempotently; verified downloads and future references always recheck PDF bytes against hash. Missing/tampered file fails closed, no Flutter-supplied PDF substitutes.
3. Extend signature domain to handle **general documents** without spoofing a quote. Implement sign requests with per-doc approval-revision binding, role/recipient, 256-bit bearer token hash, expiry, revocation, consent evidence, signature draw/type as existing policy allows, and signed artifact with source digest/audit; original approved PDF stays immutable. Reuse visual components and valid existing M8 consent behavior.
4. Public customer view/receipt and signed PDF via short-lived, revoked/expired token checks; link generation separate from delivery. Rate limiting, noindex/no-referrer/no-store, strict tenant separation, no raw IDs/paths; no account required.
5. Explicit email send (link-only or attach exact approved/signed PDF) with deterministic idempotency, attempts and outcomes, failure/unknown state reconciliation, verified transport settings and no false proof of inbox delivery. Send may only follow explicit human approval. No auto-send by AI.
6. Publish final approved/signed document in M9 catalog with correct version, type, linked client/job, signature and delivery events. Make document history and audit consistent across Flutter, API and Admin.
7. Flutter existing **General Document Preview** actions: preview, sign, generate/download/share verified PDF, send confirmation, link client/job, history. Preserve established UI, bottom sheets and responsiveness. Reuse document preview renderer instead of a visually divergent new screen.
8. Detailed security/regressions: cross-company/no-link access, stale revision sign links, link expiry/revocation, tamper/hard deletes, double-submit/race, email failures/ambiguous retries, PDF page break/font/encoding, wrong recipient, signature evidence integrity, document count unchanged for revisions/signatures.
9. Run CI on the shared branches (PHP lint, view/route compile, Laravel tests, Flutter analyzer/unit/widget/portrait/landscape, M8/M9/M11/M13/M17 regression suites). Conduct separate *non-production* MySQL migration rehearsal and device acceptance; no production external email signing without explicit test approval.

**Stage 2 exit gate:** approved revisions render verifiable official PDFs; authenticated user can explicitly send and sign; public customer can access only secure, expiring resources; audited, tamper-evident, no unauthorized sends; all relevant CI green. Deployment remains separately authorized.

## 6. API design proposal (verify against current middleware before implementation)

- `GET /api/v1/general-documents` — company, pagination, status/type/client/job filters.
- `POST /api/v1/general-documents` — create draft with idempotency key.
- `GET/PATCH /api/v1/general-documents/{id}` — details or lock-version draft update.
- `GET /api/v1/general-documents/{id}/revisions` — immutable history.
- `POST/DELETE /api/v1/general-documents/{id}/attachments[/{attachment}]` — private files.
- `POST /api/v1/general-documents/ai/prepare` — **draft suggestion only**, never a send action.
- `POST /api/v1/general-documents/{id}/review`, `/approve` — human approval.
- Stage 2: `POST .../{id}/issue`; `GET .../{id}/pdf`; `POST .../{id}/signature-requests`; `POST .../{id}/customer-link`; `POST .../{id}/send`.
- Public Stage 2: separate token-based view/sign/receipt/pdf routes; never reuse quote-bound `/sign/{token}` with a general-document raw ID.
- Admin routes fit existing platform-admin `/admin` structure, with general-document list/detail/create/update/review/approval/versions/attachments/delivery/signatures audit. Do not create duplicate standalone Documents nav.

Endpoint/DB names are preparation recommendations; reconcile exact shared abstractions during Stage 1 before schema creation.

## 7. Dependencies and explicit exclusions

- Required: M3 company and permissions, M4 Clients, M5 Jobs, M8 signature basis, M9 document catalog, M11 notes/conversion, Laravel private storage, email config for Stage 2.
- **Not required for Stage 1:** Stripe, Swiss QR bill, live SMTP deliveries, funded OpenAI API (manual path must pass). **Required to verify live AI:** an actually credited server-side OpenAI API key; do not expose it to Flutter.
- No backend or mobile production release during preparation or Stage 1/Stage 2 coding. No Hostinger SSH, migrations, tests against production database, external signing invitations, real customer emails, Stripe charges or change to `main`.
- Power of attorney and other legally sensitive documents require actual jurisdiction-specific review by the business; do not present a non-qualified electronic signature as a qualified legal signature.
- Subscription prices, trial paywall and payment gateway belong to the later subscription/billing module; equipment loans are next module after M18.

## 8. Acceptance matrix / first verification path

1. Company A manager creates letter manually; saves then revises twice; history displays three separate revisions; no AI service needed.
2. Company A user tries Company B client/job/document/attachment: blocked without revealing content; switching company mid-request discards stale data.
3. User requests AI letter with incomplete client details: missing data remains explicit; editing and review required; 429 produces graceful manual fallback.
4. Job note conversion creates one M18 authored draft with traceable original, never two M9 catalog rows. Draft appears exactly once in AI Documents and M9 Documents.
5. Review and approve lock a canonical revision; subsequent changes require explicit new draft/revision, never overwrite issued or signed originals.
6. Official PDF, signature requests, public read, sending cannot occur before approval; Stage 2 proves all links/revocations/hashes and signature evidence.
7. Admin can filter, inspect revisions, manage authorized drafts and attachments, see audit/send/signature history without cross-company leakage.
8. Create a **new** document counts once toward three free creations; edit/sign/reopen does **not** consume a second credit; enforcement held until subscription module.
9. Existing M1–M17 screens and data persist unchanged and tests remain passing.
10. All final commits on **the same shared branch**; `main`/`development`/Hostinger never touched without separate approval.

## 9. Ready-to-start instruction

`Start Sign2Digital M18 Stage 1 — General AI Document Creation. Follow docs/M18_GENERAL_AI_DOCUMENT_CREATION_PREPARED_PLAN_2026-11-05.md. Work on the same existing Flutter and Laravel branches; implement full manual+AI draft authoring with versioned secure backend, APIs, fully manageable Admin, M9 document catalog integration and the existing Flutter AI Documents screens. Preserve the established UI exactly. Add focused tests, fix failures, commit both repos, do not deploy.`
