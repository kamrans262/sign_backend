# Sign2Digital M5 — Job Management, Stage 1

Source: original bilingual scope section 4 / mobile flow screens 15–17 and the existing Flutter Jobs, Create Job, Job Details, Client Details, and downstream navigation. Do not simplify any existing screen.

## Persistence and security

- Jobs are stored in `service_jobs`, **not** Laravel's existing `jobs` queue table.
- Each job is owned by one `company_id` and one `client_id`. The client must be a non-archived record belonging to that company.
- Actual active company is derived from `X-Company-Id` after Sanctum authentication and `company.access`; client-provided `company_id` is ignored on mobile.
- Read operations require `jobs.read` and write operations `jobs.write`, with current active membership. Unknown/foreign record IDs return 404.
- Team assignments require active users with active memberships in the job company. No dummy team members may be uploaded.
- Immutable company assignment, numbered job references and audit logging for create/update/archive/restore.
- Dates must be ordered, budget nonnegative, stage/progress explicit. Overdue is derived from the actual due date (except completed jobs).
- Jobs can be active, completed or archived; archiving preserves history and is reversible. Editing archived jobs requires restoring first.
- Original UI uses a description but no title input; backend derives title from the first description line if not provided.
- Company/client related lists and previous job history may only return real saved jobs. The client API reports job history only when the signed-in member also has `jobs.read`.

## API contracts (all `/api/v1`)

| Method | Path | Permission | Function |
|---|---|---|---|
| GET | /jobs?category=active\|completed\|archived&q=&page= | jobs.read | Search and paginate company jobs |
| POST | /jobs | jobs.write | Create job |
| GET | /jobs/{job} | jobs.read | Fetch job and current downstream reference slots |
| PATCH | /jobs/{job} | jobs.write | Modify job/stage/team |
| DELETE | /jobs/{job} | jobs.write | Archive job |
| POST | /jobs/{job}/restore | jobs.write | Restore archived job |

Create expects `client_id`, `description`, `start_date`, `due_date`; optional `title`, `address`, `budget_chf`, `team_member_ids`. Uses ISO dates `YYYY-MM-DD`. Server response is `data.job`; list response is `data.jobs` and `data.pagination`.

## Flutter integration

- `RemoteJobRepository` maps authenticated API data to existing JobModel and verifies company boundaries after every request.
- Signed-in dashboard Jobs and Clients screens no longer show sample jobs; standalone UI test/demo mode retains the original mock records.
- Existing quote, addenda, photo, notes and document routes are unchanged. Financial and downstream sections not yet persisted are displayed as **Not available / Not connected yet**, not fictional sample amounts or invented records.
- Company switches clear previously visible tenant jobs before reloading; load errors are shown with a retry action rather than falling back to fake entries.
- Team picker uses real company member API in authenticated mode.
- Existing portrait designs and input fields are preserved; centered max widths are lifted in landscape for Jobs and Create Job.

## Stage 2 verification before deployment

- Scoped Laravel SQLite/MySQL tests and Flutter analyzer/widget tests.
- Actual iOS/Android job creation, team selection, company switching, landscape, file/route smoke testing.
- Cross-company ID enumeration, role revocation, archive/restore and client history permissions.
- Obtain explicit release approval, take real MySQL + private storage + web-entry backups, verify server runtime/migrations, and only then deploy. No production migration is part of Stage 1.
- The OpenAI API key is not needed for core M5 CRUD. Existing AI voice functionality needs separate secure Laravel proxy integration before production; no client-side API secrets.
