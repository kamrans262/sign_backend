# Sign2Digital M6 — Stage 1: AI Quote Creation

Date: 2026-11-03. Development uses exactly two stages per module. M5 device testing is deferred at the user's request.

## Required original workflow

Preserve the original Create Quote, AI Processing, Review Quote, Preview, PDF, Send Quote and Job Details screens and every existing input/option. The bilingual scope requires typed text, recorded voice or handwritten images, extraction only of actual user-supplied work, quantities and measurements, then explicit human review, manual pricing/VAT/deposit, approval, document preview and customer delivery. AI must NEVER invent prices, tax, dimensions, quantities, jobs or details.

## Git branches

Flutter: kamrans262/sign_app, feature/m6-ai-quote-creation-2026-11-03 (M5 parent bd9fd1ff804b256e51823b8deebb9ca62eb25a26).
Laravel: kamrans262/sign_backend, feature/m6-ai-quote-creation-2026-11-03 (M5 parent 1ec0800f5a9c67e1c8177051e8f9ada8e36b1bad).

## Implemented backend

- Add-only database migration 2026_11_03_500000 creates service_quotes, service_quote_items and service_quote_revisions. Quote references, company ownership, date, line items, integer currency minor-unit totals and immutable revisions are server-owned.
- Active-member quotes.read / quotes.write permissions are independently checked from jobs.read and clients.read; unknown or foreign IDs return 404. Client/job relationships validated against the selected company.
- Quote list, create, update draft, show, approve, share, archive and revision endpoints plus signed customer view. Actual quote history appears in Jobs and Clients only for authorized readers.
- Existing admin sidebar now includes Quote Management with search, company filters, editor, item lines, approved state, archive/restore, revisions and audit.
- Server-only text/voice/handwritten-photo OpenAI adapter, throttling, input validation, no client API key. AI returns only work-source suggestions and never generated prices or tax.
- Secure expiring customer-facing quote page provides approved items, company issuer and terms with print/save PDF; email sends a signed viewing link, NOT an attached PDF. Generating a link alone is NOT delivery.

## Flutter integration

Real logged-in Create Quote uses authenticated Laravel AI instead of asking for a temporary client key; Review Save Draft and Approve require server responses; original PDF and Send Quote UI are reused. Email confirmation/secure-link generation uses real API, and existing Job Quotes section offers saved drafts and approved previews. All demo-only functionality remains isolated and labeled.

## OpenAI key and testing

No OpenAI API key was provided, requested or saved. It must be configured only in the private Hostinger Laravel .env as OPENAI_API_KEY (optionally OPENAI_QUOTE_MODEL) before LIVE AI testing. Do not paste the key in chat, publish it in GitHub, or embed it in Flutter. Without a key the backend returns a clear 503 unavailable response. Test fixtures do not need a key.

Stage 1 automated PHP/Blade SQLite+MySQL tests and Flutter analyzer/quote UI tests are required. Stage 2 still covers physical Android and iPhone, actual transcription/vision, SMTP receipt, signed-link expiry and PDF consistency, admin UX and full security/regressions.

## Deployment status

M6 is NOT DEPLOYED. M5 backend remains deployed at 1ec0800. Never run M6 migration until a user-approved release with real MySQL/private-storage backup, PHP and migration checks, exact consolidated SHA and recovery plan. Do not reset local Flutter changes, regenerate APP_KEY or use migrate:fresh.