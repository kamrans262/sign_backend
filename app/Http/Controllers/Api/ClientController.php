<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Services\DocumentCatalogService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Authentication, active membership and company permissions are verified
     * by route middleware before any method executes. Never accept company_id
     * from a mobile request body.
     */
    private function company(Request $request): Company
    {
        $company = $request->attributes->get('company');
        abort_unless($company instanceof Company, 403);
        return $company;
    }

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

    private function canReadJobs(Request $request): bool
    {
        $company = $this->company($request);
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) return false;
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return true;
        $permissions = json_decode((string) $member->pivot->permissions, true);
        return is_array($permissions) && in_array('jobs.read', $permissions, true);
    }

    private function canReadDocuments(Request $request): bool
    {
        $company = $this->company($request);
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) return false;
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return true;
        $permissions = json_decode((string) $member->pivot->permissions, true);
        return is_array($permissions) && in_array('documents.read', $permissions, true);
    }

    private function profile(Client $client, bool $canReadJobs = false): array
    {
        return [
            'id' => $client->id,
            'company_id' => $client->company_id,
            'full_name' => $client->full_name,
            'company_name' => $client->company_name ?? '',
            'address' => $client->address ?? '',
            'country_iso_code' => $client->country_iso_code ?? '',
            'dialing_code' => $client->dialing_code ?? '',
            'phone_number' => $client->phone_number ?? '',
            'email' => $client->email ?? '',
            'notes' => $client->notes ?? '',
            'source' => $client->source,
            'job_count' => $canReadJobs ? $client->serviceJobs()->count() : 0,
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }

    private function fields(Request $request, bool $required = true): array
    {
        $data = $request->validate([
            'full_name' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:4000'],
            'country_iso_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'dialing_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:15000'],
        ]);
        foreach (['full_name', 'company_name', 'address', 'phone_number', 'email',
            'notes', 'dialing_code', 'country_iso_code'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (!empty($data['country_iso_code'])) {
            $data['country_iso_code'] = strtoupper($data['country_iso_code']);
        }
        if (array_key_exists('full_name', $data) && $data['full_name'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Client full name is required.']);
        }
        if (!empty($data['email'])) $data['email'] = strtolower($data['email']);
        return $data;
    }

    private function scoped(Request $request, int $id): Client
    {
        // A missing client and a foreign-tenant ID are indistinguishable.
        return Client::query()->where('company_id', $this->company($request)->id)
            ->whereKey($id)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Client::query()->where('company_id', $this->company($request)->id);
        $needle = trim((string) ($data['q'] ?? ''));
        if ($needle !== '') {
            $query->where(function ($q) use ($needle): void {
                $q->where('full_name', 'like', '%'.$needle.'%')
                    ->orWhere('company_name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%')
                    ->orWhere('phone_number', 'like', '%'.$needle.'%');
            });
        }
        $page = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50));
        $canReadJobs = $this->canReadJobs($request);
        return response()->json(['data' => [
            'clients' => $page->getCollection()->map(fn (Client $client) => $this->profile($client, $canReadJobs))->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]]);
    }

    public function show(Request $request, int $client): JsonResponse
    {
        $target = $this->scoped($request, $client);
        $canReadJobs = $this->canReadJobs($request);
        $canReadQuotes = $this->canReadQuotes($request);
        $quotes = $canReadQuotes
            ? $target->serviceQuotes()->whereNot('status', 'archived')->latest('id')
                ->get(['id', 'reference_number', 'status', 'total_minor', 'currency'])
                ->map(fn ($quote) => [
                    'id' => $quote->id,
                    'reference_number' => $quote->reference_number,
                    'status' => $quote->status,
                    'total_minor' => $quote->total_minor,
                    'currency' => $quote->currency,
                ])->values()
            : [];
        $jobs = $canReadJobs
            ? $target->serviceJobs()->latest('id')->get()->map(fn ($job) => [
                'id' => $job->id,
                'reference_number' => $job->reference_number,
                'title' => $job->title,
                'status' => $job->category,
                'due_date' => $job->due_date?->format('Y-m-d'),
            ])->values()
            : [];
        $companyId = (int) $target->company_id;
        $canReadDocuments = $this->canReadDocuments($request);
        $documents = $canReadDocuments
            ? collect(app(DocumentCatalogService::class)->listing($companyId, [
                'client_id' => (int) $target->id, 'per_page' => 100,
            ])['documents'])->filter(fn (array $document) => $canReadQuotes ||
                !in_array($document['kind'], ['quote', 'signed', 'addendum'], true))->values()
            : collect();
        $invoices = $canReadDocuments && $canReadJobs
            ? DB::table('service_invoices')->where('company_id', $companyId)
                ->where('client_id', $target->id)->where('status', '!=', 'void')
                ->latest('id')->limit(100)
                ->get(['id', 'invoice_number', 'status', 'total_minor',
                    'currency', 'invoice_date', 'service_job_id'])
                ->map(fn ($invoice) => [
                    'id' => (int) $invoice->id,
                    'number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'total_minor' => (int) $invoice->total_minor,
                    'currency' => $invoice->currency,
                    'invoice_date' => $invoice->invoice_date,
                    'job_id' => (int) $invoice->service_job_id,
                ])->values()
            : collect();
        // Private job notes are available only to users authorized to read jobs.
        // Never include note bodies in customer-facing public document links.
        $notes = $canReadJobs
            ? DB::table('job_notes as n')
                ->join('service_jobs as j', function ($join): void {
                    $join->on('j.id', '=', 'n.service_job_id')
                        ->on('j.company_id', '=', 'n.company_id');
                })
                ->where('n.company_id', $companyId)
                ->where('j.client_id', $target->id)
                ->whereNull('n.archived_at')->latest('n.id')->limit(100)
                ->get(['n.id', 'n.title', 'n.body', 'n.created_at',
                    'n.service_job_id'])
                ->map(fn ($note) => [
                    'id' => 'note-'.$note->id,
                    'title' => $note->title, 'description' => $note->body,
                    'created_at' => $note->created_at,
                    'job_id' => (int) $note->service_job_id,
                ])->values()
            : collect();
        if (trim((string) $target->notes) !== '') {
            $notes->prepend([
                'id' => 'client-note-'.$target->id, 'title' => 'Client note',
                'description' => $target->notes,
                'created_at' => $target->updated_at?->toIso8601String(),
                'job_id' => null,
            ]);
        }
        return response()->json(['data' => [
            'client' => $this->profile($target, $canReadJobs),
            'jobs' => $jobs, 'quotes' => $quotes, 'documents' => $documents,
            'invoices' => $invoices, 'notes_history' => $notes,
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $fields = $this->fields($request);
        $client = DB::transaction(function () use ($request, $company, $fields): Client {
            $client = Client::create(array_merge($fields, [
                'company_id' => $company->id, 'source' => 'manual',
            ]));
            AuditLogger::record('mobile.client.created', $request->user(), $client, $company,
                request: $request);
            return $client;
        });
        return response()->json(['data' => ['client' => $this->profile($client)]], 201);
    }

    public function update(Request $request, int $client): JsonResponse
    {
        $target = $this->scoped($request, $client);
        $fields = $this->fields($request, false);
        if (!$fields) {
            throw ValidationException::withMessages(['client' => 'No client changes supplied.']);
        }
        $target->update($fields);
        AuditLogger::record('mobile.client.updated', $request->user(), $target,
            $this->company($request), metadata: ['fields' => array_keys($fields)], request: $request);
        return response()->json(['data' => ['client' => $this->profile($target->fresh())]]);
    }

    public function destroy(Request $request, int $client): JsonResponse
    {
        $target = $this->scoped($request, $client);
        $target->delete(); // soft-delete to preserve future linked job history
        AuditLogger::record('mobile.client.archived', $request->user(), $target,
            $this->company($request), request: $request);
        return response()->json(['message' => 'Client archived.']);
    }

    public function import(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $payload = $request->validate([
            'clients' => ['required', 'array', 'min:1', 'max:100'],
            'clients.*.full_name' => ['required', 'string', 'max:255'],
            'clients.*.company_name' => ['nullable', 'string', 'max:255'],
            'clients.*.address' => ['nullable', 'string', 'max:4000'],
            'clients.*.country_iso_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'clients.*.dialing_code' => ['nullable', 'string', 'max:8'],
            'clients.*.phone_number' => ['nullable', 'string', 'max:50'],
            'clients.*.email' => ['nullable', 'email', 'max:255'],
            'clients.*.notes' => ['nullable', 'string', 'max:15000'],
        ]);
        $created = DB::transaction(function () use ($request, $company, $payload) {
            $items = collect();
            foreach ($payload['clients'] as $item) {
                $item['full_name'] = trim($item['full_name']);
                if ($item['full_name'] === '') {
                    throw ValidationException::withMessages([
                        'clients' => 'Every imported contact needs a name.',
                    ]);
                }
                if (!empty($item['email'])) $item['email'] = strtolower(trim($item['email']));
                if (!empty($item['country_iso_code'])) {
                    $item['country_iso_code'] = strtoupper($item['country_iso_code']);
                }
                // Only explicitly reviewed and submitted entries are saved.
                // Do not merge phone contacts automatically into existing records.
                $client = Client::create(array_merge($item, [
                    'company_id' => $company->id,
                    'source' => 'phone_contact',
                ]));
                $items->push($this->profile($client));
            }
            AuditLogger::record('mobile.client.imported', $request->user(), null, $company,
                metadata: ['count' => $items->count()], request: $request);
            return $items;
        });
        return response()->json(['data' => [
            'clients' => $created, 'imported_count' => $created->count(),
        ]], 201);
    }
}
