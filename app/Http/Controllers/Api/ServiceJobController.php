<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Services\DocumentCatalogService;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceJobController extends Controller
{
    private function canReadQuotes(Request $request): bool
    {
        $company = $this->company($request);
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) return false;
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return true;
        $perms = json_decode((string) $member->pivot->permissions, true);
        return is_array($perms) && in_array('quotes.read', $perms, true);
    }

    private function canReadReports(Request $request): bool
    {
        $company = $this->company($request);
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) return false;
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return true;
        $perms = json_decode((string) $member->pivot->permissions, true);
        return is_array($perms) && in_array('documents.read', $perms, true);
    }

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('company');
        abort_unless($company instanceof Company, 403);
        return $company;
    }

    private function scoped(Request $request, int $id): ServiceJob
    {
        // Identical 404 for unknown and cross-company job identifiers.
        return ServiceJob::query()
            ->where('company_id', $this->company($request)->id)
            ->with(['client', 'assignees:id,name'])
            ->findOrFail($id);
    }

    private function payload(ServiceJob $job): array
    {
        return [
            'id' => $job->id,
            'company_id' => $job->company_id,
            'client_id' => $job->client_id,
            'client_name' => $job->client?->company_name ?: $job->client?->full_name,
            'reference_number' => $job->reference_number,
            'title' => $job->title,
            'description' => $job->description,
            'address' => $job->address ?? '',
            'start_date' => $job->start_date?->format('Y-m-d'),
            'due_date' => $job->due_date?->format('Y-m-d'),
            'budget_chf' => $job->budget_chf,
            'progress_percentage' => (float) $job->progress_percentage,
            'stage' => $job->effectiveStage(),
            'category' => $job->category,
            'team_member_ids' => $job->assignees->pluck('id')->values(),
            'team_members' => $job->assignees->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'archived_at' => $job->archived_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    private function fields(Request $request, ?ServiceJob $existing = null): array
    {
        $creating = $existing === null;
        $data = $request->validate([
            'client_id' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:15000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'start_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'due_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'budget_chf' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'progress_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'stage' => ['sometimes', Rule::in(['planning', 'in_progress', 'completed'])],
            'team_member_ids' => ['sometimes', 'array', 'max:100'],
            'team_member_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        // Server-owned company, reference and accounting fields are never mass-assigned.
        $company = $this->company($request);
        $clientId = (int) ($data['client_id'] ?? $existing?->client_id ?? 0);
        $client = Client::query()->where('company_id', $company->id)
            ->whereKey($clientId)->first();
        if (!$client) {
            throw ValidationException::withMessages(['client_id' => 'Choose a client from the active company.']);
        }
        $start = (string) ($data['start_date'] ?? $existing?->start_date?->format('Y-m-d') ?? '');
        $due = (string) ($data['due_date'] ?? $existing?->due_date?->format('Y-m-d') ?? '');
        if ($start === '' || $due === '' || $due < $start) {
            throw ValidationException::withMessages(['due_date' => 'End date must not precede start date.']);
        }
        $description = trim((string) ($data['description'] ?? $existing?->description ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'A job description is required.']);
        }
        if (array_key_exists('description', $data)) $data['description'] = $description;
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
            // Existing titles are not nullable in the database. Fail with
            // a validation error rather than an unhandled SQL exception.
            if (!$creating && $data['title'] === '') {
                throw ValidationException::withMessages(['title' => 'Job title is required.']);
            }
        }
        if ($creating && empty($data['title'])) {
            $data['title'] = mb_substr(strtok($description, "\n") ?: $description, 0, 255);
        }
        if (isset($data['team_member_ids'])) {
            $ids = array_map('intval', $data['team_member_ids']);
            $count = $company->users()->wherePivot('status', 'active')
                ->where('users.is_active', true)->whereIn('users.id', $ids)->count();
            if ($count !== count($ids)) {
                throw ValidationException::withMessages([
                    'team_member_ids' => 'Every selected team member must be active in this company.',
                ]);
            }
        }
        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(['active', 'completed', 'archived'])],
            'stage' => ['nullable', Rule::in(['planning', 'in_progress', 'completed', 'overdue'])],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ServiceJob::query()->where('company_id', $this->company($request)->id)
            ->with(['client', 'assignees:id,name']);
        $query->where('category', $filters['category'] ?? 'active');
        if (isset($filters['client_id'])) $query->where('client_id', $filters['client_id']);
        if (isset($filters['stage'])) {
            if ($filters['stage'] === 'overdue') {
                $query->whereNot('stage', 'completed')->whereDate('due_date', '<', today());
            } else {
                $query->where('stage', $filters['stage']);
            }
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $needle = trim($filters['q']);
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$needle.'%')
                ->orWhere('description', 'like', '%'.$needle.'%')
                ->orWhere('reference_number', 'like', '%'.$needle.'%')
                ->orWhereHas('client', fn ($client) =>
                    $client->where('full_name', 'like', '%'.$needle.'%')
                        ->orWhere('company_name', 'like', '%'.$needle.'%')));
        }
        $page = $query->latest('id')->paginate((int) ($filters['per_page'] ?? 50));
        return response()->json(['data' => [
            'jobs' => $page->getCollection()->map(fn ($job) => $this->payload($job))->values(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]]);
    }

    public function show(Request $request, int $job): JsonResponse
    {
        $target = $this->scoped($request, $job);
        $canSeeQuotes = $this->canReadQuotes($request);
        $quotes = $canSeeQuotes
            ? $target->serviceQuotes()->whereNot('status', 'archived')->orderByDesc('id')
                ->get(['id', 'reference_number', 'status', 'total_minor', 'currency'])
                ->map(fn ($quote) => [
                    'id' => $quote->id,
                    'reference_number' => $quote->reference_number,
                    'status' => $quote->status,
                    'total_minor' => $quote->total_minor,
                    'currency' => $quote->currency,
                ])->values()
            : [];
        // M11: verified media counts/identifiers, never raw private note bodies
        // or storage paths. Detail content stays in permission-checked endpoints.
        $photos = DB::table('job_photos')->where('company_id',$target->company_id)
            ->where('service_job_id',$target->id)->whereNull('archived_at')
            ->orderByDesc('id')->limit(100)->get(['id','category']);
        $notes = DB::table('job_notes')->where('company_id',$target->company_id)
            ->where('service_job_id',$target->id)->whereNull('archived_at')
            ->orderByDesc('id')->limit(100)->get(['id','note_type']);
        $reports = $this->canReadReports($request)
            ? DB::table('site_reports')->where('company_id',$target->company_id)
                ->where('service_job_id',$target->id)
                ->orderByDesc('id')->limit(100)
                ->get(['id','kind','number','title','status'])
            : collect();
        $acceptances = $this->canReadReports($request)
            ? DB::table('work_acceptances')->where('company_id',$target->company_id)
                ->where('service_job_id',$target->id)->orderByDesc('id')->limit(100)
                ->get(['id','number','status','revision'])
            : collect();
        $canSeeDocuments = $this->canReadReports($request);
        $companyId = (int) $target->company_id;
        $jobId = (int) $target->id;
        // Only server-verified IDs and metadata are returned. Private files
        // and note bodies remain behind their own permission-checked routes.
        $signedQuotes = $canSeeQuotes
            ? DB::table('signed_document_artifacts as a')
                ->join('service_quotes as q', function ($join): void {
                    $join->on('q.id','=','a.service_quote_id')
                        ->on('q.company_id','=','a.company_id');
                })
                ->where('a.company_id',$companyId)
                ->where('q.service_job_id',$jobId)
                ->orderByDesc('a.id')->limit(100)
                ->get(['a.id','a.service_quote_id','a.signed_pdf_sha256','a.created_at'])
            : collect();
        $addenda = $canSeeDocuments
            ? DB::table('addenda')->where('company_id',$companyId)
                ->where('service_job_id',$jobId)->where('status','!=','archived')
                ->orderByDesc('id')->limit(100)
                ->get(['id','number','title','status','total_minor'])
            : collect();
        $documents = $canSeeDocuments
            ? collect(app(DocumentCatalogService::class)->listing($companyId,[
                'job_id'=>$jobId,'per_page'=>100,
              ])['documents'])->filter(fn(array $d)=>$canSeeQuotes ||
                  !in_array($d['kind'],['quote','signed','addendum'],true))->values()
            : collect();
        $purchases = $canSeeDocuments
            ? DB::table('job_expenses')->where('company_id',$companyId)
                ->where('service_job_id',$jobId)->orderByDesc('id')->limit(100)
                ->get(['id','category','status','expense_date','amount_minor'])
            : collect();
        $time = DB::table('job_labour_entries')->where('company_id',$companyId)
            ->where('service_job_id',$jobId)->whereNull('voided_at')
            ->orderByDesc('id')->limit(100)
            ->get(['id','worker_name_snapshot','task','duration_minutes',
                'cost_minor','status']);
        $deposits = $canSeeDocuments
            ? DB::table('job_deposits')->where('company_id',$companyId)
                ->where('service_job_id',$jobId)->where('status','!=','void')
                ->orderByDesc('id')->limit(100)
                ->get(['id','reference_number','amount_minor','status'])
            : collect();
        $invoices = $canSeeDocuments
            ? DB::table('service_invoices')->where('company_id',$companyId)
                ->where('service_job_id',$jobId)->where('status','!=','void')
                ->orderByDesc('id')->limit(100)
                ->get(['id','invoice_number','status','total_minor'])
            : collect();
        $snapshot = app(\App\Services\JobDetailsSnapshot::class)->forJob(
            $target, $canSeeQuotes, $canSeeDocuments);
        return response()->json(['data' => array_merge([
            'job' => $this->payload($target),
            'sections' => [
                'quotes' => $quotes, 'signed_quotes' => $signedQuotes,
                'addenda' => $addenda, 'documents' => $documents,
                'photos' => $photos, 'notes' => $notes,
                'purchases' => $purchases, 'time' => $time,
                'deposits' => $deposits, 'site_reports' => $reports,
                'acceptance' => $acceptances, 'invoices' => $invoices,
            ],
        ], $snapshot)]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $this->fields($request);
        $validatedKey = $request->validate([
            'request_key' => ['sometimes', 'string', 'min:16', 'max:100',
                'regex:/^[A-Za-z0-9_.:-]+$/D'],
        ]);
        $key = $validatedKey['request_key'] ?? null;
        $fingerprintData = $data;
        ksort($fingerprintData);
        $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
        $team = $data['team_member_ids'] ?? [];
        unset($data['team_member_ids']);
        $replayed = false;
        $job = DB::transaction(function () use ($request, $company, $data,
            $team, $key, $fingerprint, &$replayed): ServiceJob {
            // Serialize every job creation for a company, not just retries.
            // This lock protects both the counter and request-key deduplication.
            $lockedCompany = Company::query()->whereKey($company->id)
                ->lockForUpdate()->firstOrFail();
            if ($key !== null) {
                $prior = ServiceJob::query()->where('company_id', $company->id)
                    ->where('request_key', $key)->first();
                if ($prior !== null) {
                    abort_unless($prior->category !== 'archived' &&
                        hash_equals((string) $prior->request_fingerprint, $fingerprint),
                        409, 'Job creation request key was already used.');
                    $replayed = true;
                    return $prior;
                }
            }
            $nextNumber = max((int) $lockedCompany->job_sequence,
                (int) ServiceJob::query()->where('company_id', $company->id)->count()) + 1;
            $letters = preg_replace('/[^A-Za-z]/', '',
                \Illuminate\Support\Str::ascii((string) $lockedCompany->name));
            $initial = strtoupper(substr($letters ?: 'X', 0, 1));
            $reference = sprintf('JOB-%s-%03d', $initial, $nextNumber);
            $job = ServiceJob::create(array_merge($data, [
                'company_id' => $company->id,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'reference_number' => $reference,
                'category' => 'active',
                'stage' => 'planning',
                'progress_percentage' => 0,
                'request_key' => $key,
                'request_fingerprint' => $key === null ? null : $fingerprint,
            ]));
            $lockedCompany->update(['job_sequence' => $nextNumber]);
            $job->assignees()->sync($team);
            AuditLogger::record('mobile.job.created', $request->user(), $job, $company,
                metadata: ['client_id' => $job->client_id], request: $request);
            return $job;
        }, 3);
        return response()->json(['data' => ['job' => $this->payload(
            $job->load(['client', 'assignees:id,name'])
        )]], $replayed ? 200 : 201);
    }

    public function update(Request $request, int $job): JsonResponse
    {
        $target = $this->scoped($request, $job);
        abort_if($target->category === 'archived', 409, 'Restore an archived job before editing.');
        $data = $this->fields($request, $target);
        if ($data === []) {
            throw ValidationException::withMessages(['job' => 'No job changes supplied.']);
        }
        $team = $data['team_member_ids'] ?? null;
        unset($data['team_member_ids']);
        // Completed jobs can be reopened explicitly; no fabricated payment/quote side effects.
        DB::transaction(function () use ($request, $target, $data, $team): void {
            if (($data['stage'] ?? null) === 'completed') {
                $data['category'] = 'completed';
                $data['progress_percentage'] = 100;
                $data['completed_at'] = $target->completed_at ?? now();
            } elseif (isset($data['stage']) && $target->category === 'completed') {
                $data['category'] = 'active';
                $data['completed_at'] = null;
            }
            $target->update(array_merge($data, ['updated_by' => $request->user()->id]));
            if ($team !== null) $target->assignees()->sync($team);
            AuditLogger::record('mobile.job.updated', $request->user(), $target,
                $this->company($request), metadata: ['fields' => array_keys($data)], request: $request);
        });
        return response()->json(['data' => ['job' => $this->payload(
            $target->fresh()->load(['client', 'assignees:id,name'])
        )]]);
    }

    public function destroy(Request $request, int $job): JsonResponse
    {
        $target = $this->scoped($request, $job);
        abort_if($target->category === 'archived', 409);
        $target->update(['category' => 'archived', 'archived_at' => now(), 'updated_by' => $request->user()->id]);
        AuditLogger::record('mobile.job.archived', $request->user(), $target,
            $this->company($request), request: $request);
        return response()->json(['message' => 'Job archived.']);
    }

    public function restore(Request $request, int $job): JsonResponse
    {
        $target = $this->scoped($request, $job);
        abort_unless($target->category === 'archived', 409);
        $target->update(['category' => $target->stage === 'completed' ? 'completed' : 'active',
            'archived_at' => null, 'updated_by' => $request->user()->id]);
        AuditLogger::record('mobile.job.restored', $request->user(), $target,
            $this->company($request), request: $request);
        return response()->json(['data' => ['job' => $this->payload(
            $target->fresh()->load(['client', 'assignees:id,name'])
        )]]);
    }
}
