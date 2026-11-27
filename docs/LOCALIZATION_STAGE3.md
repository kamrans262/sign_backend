# Stage 3 — four-language backend and customer delivery

Supported locale codes: en, fr, de, it. The Flutter API client supplies
`Accept-Language` and `X-App-Language`. Laravel applies request-scoped
localization. English remains the fallback. The authenticated account
preference API accepts the same four languages. No authentication, tenancy,
payment, PDF permissions, signature grant or user-entered content is changed
by the locale.

Implemented paths:
- Existing AI extraction and support system prompts request language-specific
  user-facing text, but retain canonical JSON keys, money, dates, enum values
  and identifiers. AI output must still be reviewed and is not guaranteed.
- Newly created report, work-acceptance, addendum, invoice and general
  document PDFs use translated STATIC labels, with frozen issued bytes and
  existing signatures untouched. Client-authored content remains in its
  source language unless created using the AI workflow in that language.
- New Flutter quotation PDFs and local unsigned addendum/demo invoice PDFs
  use translated static labels.
- Future quote, signature, report, invoice, addendum, acceptance and general
  document delivery emails have translated standard copy.
- Customer-facing capability pages for signing, work acceptance, addenda,
  invoices and documents have translated static labels.
- The German/Italian in-app and push notification templates are added through
  an additive migration without overwriting older template revisions. Push
  delivery is not enabled by this work.

Deliberate boundaries:
- Do not rewrite historical approved, issued or signed PDFs or signatures.
- Do not translate existing user-written paragraphs/terms, billing values,
  bank details or authoritative source documents automatically.
- The existing Flutter and Admin visual designs are unchanged.
- Notification templates are seeded only on database migration, not on fetch.
- English fallback remains for any static copy not in the catalogs and for
  user-provided dynamic content. Browser language differs from app locale
  unless forwarded in a link or specified in the browser preferences.
- Release acceptance still requires native device tests and visual PDF
  and email inspection. Do not claim the whole application fully translated
  until the following test steps pass.

Nondeploy verification:
```
php -l app/Support/AppLocale.php
php -l app/Support/PublicPageText.php
php artisan test --filter=Stage3LocalizationTest
```

Deployment (ONLY after explicit authorization and a database backup):
`php artisan migrate --force` after updating the backend active branch.
Do not enable push, change Apple/APNs/Firebase, replace Stripe settings
or reissue signed documents.
