<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceJobController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'category' => ['nullable', Rule::in(['active', 'completed', 'archived'])],
        ]);
        $query = ServiceJob::query()->with(['company:id,name', 'client:id,full_name,company_name'])->latest('id');
        if (!empty($filters['company_id'])) $query->where('company_id', $filters['company_id']);
        if (!empty($filters['category'])) $query->where('category', $filters['category']);
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$needle.'%')
                ->orWhere('reference_number', 'like', '%'.$needle.'%')
                ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', '%'.$needle.'%')
                    ->orWhere('company_name', 'like', '%'.$needle.'%')));
        }
        return view('admin.jobs.index', [
            'jobs' => $query->paginate(20)->withQueryString(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'search' => $needle,
            'companyFilter' => (int) ($filters['company_id'] ?? 0),
            'category' => $filters['category'] ?? '',
        ]);
    }

    private function options(): array
    {
        return [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'clients' => Client::query()->with('company:id,name')
                ->orderBy('full_name')->get(['id','company_id','full_name','company_name']),
            'members' => User::query()->where('is_active', true)
                ->whereHas('companies', fn ($company) => $company->where('company_user.status', 'active'))
                ->orderBy('name')->get(['id','name','email']),
        ];
    }

    public function create(): View
    {
        return view('admin.jobs.form', $this->options());
    }

    public function edit(int $job): View
    {
        return view('admin.jobs.form', array_merge($this->options(), [
            'job' => ServiceJob::query()->with('assignees')->findOrFail($job),
        ]));
    }

    private function fields(Request $request, ?ServiceJob $existing = null): array
    {
        $creating = $existing === null;
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('status', 'active')],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:15000'],
            'address' => ['nullable', 'string', 'max:4000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'budget_chf' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'stage' => ['required', Rule::in(['planning','in_progress','completed'])],
            'progress_percentage' => ['required', 'numeric', 'between:0,100'],
            'team_member_ids' => ['sometimes', 'array', 'max:100'],
            'team_member_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        if ($existing && $existing->company_id !== (int) $data['company_id']) {
            throw ValidationException::withMessages(['company_id' => 'Job company cannot change after creation.']);
        }
        if (!Client::query()->whereKey($data['client_id'])
            ->where('company_id', $data['company_id'])->exists()) {
            throw ValidationException::withMessages(['client_id' => 'Client must belong to the selected company.']);
        }
        $ids = array_map('intval', $data['team_member_ids'] ?? []);
        $count = Company::findOrFail($data['company_id'])->users()->wherePivot('status', 'active')
            ->where('users.is_active', true)->whereIn('users.id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['team_member_ids' => 'Members must belong to this company.']);
        }
        $data['title'] = trim($data['title']);
        $data['description'] = trim($data['description']);
        if ($data['title'] === '' || $data['description'] === '') {
            throw ValidationException::withMessages(['description' => 'Job title and description are required.']);
        }
        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->fields($request);
        $ids = $data['team_member_ids'] ?? [];
        unset($data['team_member_ids']);
        $job = DB::transaction(function () use ($data, $ids, $request): ServiceJob {
            // Serialize admin and mobile numbering on the same company row.
            $company = Company::query()->whereKey($data['company_id'])
                ->lockForUpdate()->firstOrFail();
            $nextNumber = max((int) $company->job_sequence,
                (int) ServiceJob::query()->where('company_id', $company->id)->count()) + 1;
            $letters = preg_replace('/[^A-Za-z]/', '',
                \Illuminate\Support\Str::ascii((string) $company->name));
            $initial = strtoupper(substr($letters ?: 'X', 0, 1));
            $completed = $data['stage'] === 'completed';
            $job = ServiceJob::create(array_merge($data, [
                'reference_number' => sprintf('JOB-%s-%03d', $initial, $nextNumber),
                'category' => $completed ? 'completed' : 'active',
                'completed_at' => $completed ? now() : null,
                'progress_percentage' => $completed ? 100 : $data['progress_percentage'],
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
            $company->forceFill(['job_sequence' => $nextNumber])->save();
            $job->assignees()->sync($ids);
            AuditLogger::record('admin.job.created', $request->user(), $job,
                Company::findOrFail($job->company_id), request: $request);
            return $job;
        });
        return redirect()->route('admin.jobs.edit', $job)->with('success', 'Job created.');
    }

    public function update(Request $request, int $job): RedirectResponse
    {
        $target = ServiceJob::findOrFail($job);
        abort_if($target->category === 'archived', 409, 'Restore archived job before editing.');
        $data = $this->fields($request, $target);
        $ids = $data['team_member_ids'] ?? [];
        unset($data['team_member_ids']);
        DB::transaction(function () use ($request, $target, $data, $ids): void {
            $completed = $data['stage'] === 'completed';
            $data['category'] = $completed ? 'completed' : 'active';
            $data['completed_at'] = $completed ? ($target->completed_at ?? now()) : null;
            if ($completed) $data['progress_percentage'] = 100;
            $data['updated_by'] = $request->user()->id;
            $target->update($data);
            $target->assignees()->sync($ids);
            AuditLogger::record('admin.job.updated', $request->user(), $target,
                Company::findOrFail($target->company_id), request: $request);
        });
        return redirect()->route('admin.jobs.edit', $target)->with('success', 'Job updated.');
    }

    public function destroy(Request $request, int $job): RedirectResponse
    {
        $target = ServiceJob::findOrFail($job);
        $target->update(['category' => 'archived', 'archived_at' => now(),
            'updated_by' => $request->user()->id]);
        AuditLogger::record('admin.job.archived', $request->user(), $target,
            Company::findOrFail($target->company_id), request: $request);
        return redirect()->route('admin.jobs.index')->with('success', 'Job archived.');
    }

    public function restore(Request $request, int $job): RedirectResponse
    {
        $target = ServiceJob::findOrFail($job);
        abort_unless($target->category === 'archived', 409);
        $target->update(['category' => $target->stage === 'completed' ? 'completed' : 'active',
            'archived_at' => null, 'updated_by' => $request->user()->id]);
        AuditLogger::record('admin.job.restored', $request->user(), $target,
            Company::findOrFail($target->company_id), request: $request);
        return redirect()->route('admin.jobs.index', ['category' => 'archived'])->with('success', 'Job restored.');
    }
}
