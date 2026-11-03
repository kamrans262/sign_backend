<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
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

    private function profile(Client $client): array
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
            // Job/quote/document relationships are introduced by their own
            // modules. Never fabricate historical records for live clients.
            'job_count' => 0,
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
        return response()->json(['data' => [
            'clients' => $page->getCollection()->map(fn (Client $client) => $this->profile($client))->values(),
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
        return response()->json(['data' => [
            'client' => $this->profile($target),
            'jobs' => [], 'quotes' => [], 'documents' => [],
            'invoices' => [], 'notes_history' => [],
        ]]);
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
