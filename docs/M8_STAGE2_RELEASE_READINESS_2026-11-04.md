# Sign2Digital M8 — Stage 2 release readiness (2026-11-04)

**STATUS: Automated acceptance substantially passed; live production deployment remains ON HOLD because on-server backup/restore, existing-customer PDF compatibility, live SMTP receipt, and physical devices have not been verified.**

## Repository and safe ancestry

- Backend: kamrans262/sign_backend, feature/m8-digital-signatures-2026-11-04
- Flutter: kamrans262/sign_app, feature/m8-digital-signatures-2026-11-04
- Last user-confirmed production M7 backend: 934eae5dcff53b2d185671cac7d5cfd479fd699f (clean and M7 migrated). Not independently reread from Hostinger today.
- GitHub comparison confirmed this M7 production SHA is an ancestor of the backend M8 branch. No production, main or development branch changed in Stage 2.

## Automated verification

| Gate | Result | GitHub run |
|:--|:--|:--|
| Laravel M7/M8 PHP syntax, Blade, SQLite + MySQL 8 | PASS | 41743967431 |
| Synthetic customer web page, CSRF, consent, single-shot signed PDF, Playwright Chromium mobile/tablet/desktop draw/type/upload | PASS | 41743283800 |
| Flutter feature regression and analyzer after PDF version change | PASS | 41744158505 |
| Real Flutter builder's synthetic approved PDF imported with PHP 8.4 FPDI 2.6.9; evidence appended, original digest unchanged | PASS | 41744158720 |
| Android debug APK + iOS Simulator no-codesign compilation | PASS | 41742906137 |
| Historic live customer PDF compatibility | UNKNOWN — Hostinger direct access missing |
| Real SMTP inbox attachment verification, both parties | UNKNOWN — mocked mail tests only |
| Physical Android and iOS signing in portrait/landscape | NOT PERFORMED |
| MySQL+private PDF+.env backup and isolated database restore | NOT PERFORMED |
| Production M8 migration and live deployment | NOT PERFORMED |

CI pass is not equivalent to a live production or physical-device acceptance.

## Historical PDF compatibility gate

The M7 approved PDF is frozen in private storage by revision and SHA-256. The free FPDI library does not parse all modern PDFs, particularly compressed object/xref streams. The M8 Flutter builder now outputs a classic PDF 1.4 format that passed real Flutter-to-PHP parser interoperability, but historical frozen customer PDFs may remain incompatible.

Use read-only scripts/m8_approved_pdf_audit.php on existing M7 production before any rollout. This prints only aggregate numbers (no customer identities, paths, PDFs or tokens). It is a **structural candidate test, not actual FPDI import**. Do not silently replace an approved PDF, alter its SHA, or claim an unsupported source was signed. If needed, use a reviewed parser license or a new separately approved document after user consultation.

## Single-line, non-destructive Hostinger commands

**1. Live production identity and M7 read-only preflight**

```
ssh -p 65002 u777477188@147.93.42.143 'set -e; cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend; printf "BRANCH: "; git branch --show-current; printf "HEAD: "; git rev-parse HEAD; printf "STATUS: "; git status --porcelain; php scripts/m7_release_preflight.php --require-migrated'
```

**2. Read-only aggregate audit of already-approved private PDFs.** It obtains just the M8 script from GitHub; it does not check out M8 or deploy.

```
ssh -p 65002 u777477188@147.93.42.143 'set -euo pipefail; cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend; git fetch origin feature/m8-digital-signatures-2026-11-04; git merge-base --is-ancestor HEAD FETCH_HEAD; git show FETCH_HEAD:scripts/m8_approved_pdf_audit.php | php'
```

**3. Create a private M8 release backup only.** Requires database credentials already in the server's .env; they are never printed.

```
ssh -p 65002 u777477188@147.93.42.143 'set -euo pipefail; cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend; test -z "$(git status --porcelain)"; git fetch origin feature/m8-digital-signatures-2026-11-04; git merge-base --is-ancestor HEAD FETCH_HEAD; git show FETCH_HEAD:scripts/m8_release_backup.sh | bash -s -- --backup-only'
```

Backup output belongs under private HOME/sign2digital-release-backups with MySQL dump, private PDFs, .env and SHA-256 manifest. A SHA-256 manifest proves transfer integrity, **not actual restorable MySQL data**. Test a restore in an isolated disposable database; never restore into production during acceptance.

**4. After separately approved controlled M8 deployment, run read-only final checks:**

```
php scripts/m8_release_preflight.php --require-migrated
```

Do NOT run destructive production test suites or any migrate:fresh, migrate:refresh, db:wipe, git reset --hard, git clean -fd, removal of signed artifacts, rollback of immutable records, APP_KEY rotation, or public exposure of .env/private PDFs.

## Unmet release gates

1. Independently read live Hostinger branch/HEAD/working tree, production PHP config, MySQL and M7 prior migration.
2. Obtain clean verified backup, private PDFs, .env/APP_KEY, checksum and **separate database restore test**, and confirm rollback code/ref.
3. Audit actual frozen M7 PDFs structurally and run at least one exact approved private file through the FPDI engine without leaking it; leave unsupported old files unchanged.
4. Verify controlled test-recipient SMTP email with real inbox attachment hashes to both parties, never send unsolicited customer mail.
5. Test Android and iOS physical devices on portrait and landscape for draw/type/upload, remote and in-person signatures, and actual downloaded PDF.
6. Only then authorize the exact commit-pinned deploy with additive M8 migration and final readback smoke tests; retain M7 historical records and all signed files even on application-code rollback.

## Legal/identity boundary

Current signing means an evidenced electronic signature with consent and hashes. It is NOT a cryptographic qualified PAdES/eIDAS/ZertES signature nor an independent cryptographic identity check.

**Decision: automatic Stage 2 preparation is complete as far as CI can verify; HOSTINGER DEPLOYMENT HELD pending live and physical acceptance.**
