# Sign2Digital M17 — Invoicing & Payment Management
Status: PREPARED ONLY — implementation not started, no production deployment.
Date: 2026-11-05
Branch (Flutter + Laravel): `feature/m12-site-reports-meeting-minutes-2026-11-04`

## Source of scope
Sign2Digital Project Scope, section O, screens 53–56:
- 53 Invoices List: Draft, Sent, Paid, Partially Paid, Overdue.
- 54 Create Invoice: accepted quote, addenda, deposits, completed work; deposit and final invoice types.
- 55 Invoice Review: customer, invoice number, items, VAT, deposits, amount due, payment info, terms; edit, approve, preview.
- 56 Invoice Preview & Send: PDF, send, download, share.

## Verified baseline (before M17)
- Flutter `kamrans262/sign_app`, head `0708bc5101b51844d04489dcd50be9b44488f984`:
  - Invoice domain: `lib/features/invoices/domain/invoice_record.dart`.
  - Demo/local repository: `lib/features/invoices/data/demo_invoice_repository.dart`.
  - Existing approved UI: `invoices_screen.dart`, `add_invoice_screen.dart`,
    `invoice_details_screen.dart`, `invoice_preview_screen.dart`.
  - Existing components: `mark_invoice_paid_dialog.dart`, `invoice_pdf_service.dart`,
    `invoice_voice_extraction_service.dart`.
  - Existing invoice UI currently shows a Pending tab, whereas the supplied scope
    specifies Partially Paid; reconcile semantics without redesign.
- Laravel `kamrans262/sign_backend`, head `adfa97c9bc4b2ca21b50aa342b59b6b63408e9e3`:
  - M7 quotes, M10 addenda, M13 acceptance, M16 deposit ledger; no M17 invoice
    tables or invoice controllers found in the current repository tree.
  - M16 supports verified received deposits, manual refunds, tenant isolation,
    approved-quote deposit capacity, immutable events, and safe create retries.
- M16 Stage 2 remains NOT deployed. Its additive migrations must be sequenced
  before M17 in any separately authorized production release.

## Stage 1 — Complete Invoice Ledger, Admin and Flutter Integration
Implement additively; do not change the existing screen design.
1. Company-scoped, immutable financial IDs and invoice-number sequencing.
   Add `service_invoices`, `service_invoice_items`, `service_invoice_payments`,
   `service_invoice_deposit_allocations`, `service_invoice_events`, private
   `service_invoice_attachments`, and supporting unique/idempotency indexes
   as needed by the audited schema. Never infer that a paid deposit is an invoice
   payment; use explicit same-company, same-job allocations.
2. Server-calculated CHF integer-minor-unit amounts, quantity/price/VAT
   validation and rounding. The ledger must satisfy:
   `amount_due = max(0, invoice_total - valid_allocated_deposits - recorded_net_payments)`.
   Never double-count an allocated deposit on deposit and final invoices.
   Treat refunds and voided payments as separate auditable events, not negative
   invoice modifications. Prevent credits above invoice totals.
3. Draft and editable review workflow, with types `deposit` and `final`.
   Source snapshots only from existing approved/accepted quote, addenda,
   completed work, and M16 verified deposit records. No invented line items,
   prices, addresses, VAT percentages or paid statuses. Warn when links to
   required upstream records are missing; no demo fallbacks for signed-in users.
4. Authenticated Laravel API endpoints, strict tenant scope, active-membership
   and role checks, request validation, concurrency/lock versions, pagination,
   idempotent draft creates, complete invoice/payment/event history,
   private validated attachments and verified download.
5. Fully manageable Admin Invoicing module: list/search/filter, create/edit,
   approve/issue when valid, manual payment/reconciliation, partial payments,
   overdue views, void/corrections with audit, inspect originals, CSV export.
   Preserve the platform's existing Admin layout and permissions.
6. Integrate all four existing Flutter invoice screens and paid dialog through
   the real repository. Use remote jobs, company details, real M16 received
   deposits, quote/addendum sources. Handle company switching, late responses
   and lost-network retries; preserve current Inter/#166282 UI and all
   approved spacing/padding. Authenticated users must never see demo payments.
7. Stage 1 automated gates: PHP lint, migrations in disposable test DB,
   API feature tests (tenant/roles/rounding/overpayment/idempotency/refunds/
   concurrency) and Flutter analyzer/widget/contract/regression tests. Commit
   same existing branches. NO DEPLOY.

## Stage 2 — Approval, Issued PDF, Delivery, Reconciliation and Release Hold
1. Invoice approval and issuance: immutable approved snapshot, number, hashes;
   versioned revisions for later legitimate corrections, never silent mutation.
2. Authoritative server-generated invoice PDFs matching existing mobile
   preview; accurate company/client identities, Swiss CHF/VAT, due date,
   payment details/IBAN and Swiss QR when required validated data exists.
   Explicitly distinguish deposit invoices and final invoices.
3. Signed, expiring customer access to invoice PDFs; authenticated send,
   link-only and PDF-attachment email; delivery audit, retry/idempotency.
   Flutter Preview, Download, Share and Send must map to real issued artifacts.
4. Payment reconciliation: partial/paid/overdue status derived from verified
   ledger + due date; manual payments only after explicit operator confirmation;
   no Stripe charge or automatic money movement. Do not imply delivery is
   payment. Verify quote-to-invoice amount parity and deposit application.
5. Read-only release preflight: route/middleware/Admin guards, migration order
   M12..M17, same-company links, accounting invariants, sample original PDFs
   and attachment SHA-256, production HTTPS/security configuration; verify
   CI on MySQL where feasible and physical iOS/Android portrait/landscape.
6. Commit, verify, and HOLD production deployment until explicit instruction,
   backups, production preflight and migration plan are separately approved.

## Out-of-scope or separately authorized
- Stripe or any online card gateway: NOT required to implement M17.
- Automatic bank transfers, live bank-feed reconciliation or bank refunds.
- Production schema migrations, deploy, billing configuration changes.
- Replacing/re-styling any existing approved Flutter screen.
- Live OpenAI voice transcription requires a credited server API key; manual
  invoicing must remain fully testable without it.

## Acceptance criteria
- A logged-in user creates an invoice for their own company/job with a unique
  stable number, not a local-only demo invoice.
- Server totals and payment states match exact CHF minor units.
- Recorded M16 deposits apply only once and refunds are not counted as received.
- Partial payments reduce balance but do not claim the invoice is fully paid.
- Draft->approved->sent workflow and issued PDFs are auditable.
- Cross-company IDs, stale versions and duplicate submission keys are rejected
  or safely replayed; no premature status transitions.
- PDFs, links, attachments and history cannot leak across tenants.
- Admin can manage the entire lifecycle without changing the existing style.
- Flutter and Laravel CI green on same final development branches;
  explicitly no production deploy.
