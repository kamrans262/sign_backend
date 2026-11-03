<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ]);
        $query = Client::query()->with('company:id,name')->latest('id');
        if (($filters['status'] ?? '') === 'archived') $query->onlyTrashed();
        $companyId = (int) ($filters['company_id'] ?? 0);
        if ($companyId > 0) $query->where('company_id', $companyId);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('full_name', 'like', '%'.$search.'%')
                ->orWhere('company_name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('phone_number', 'like', '%'.$search.'%'));
        }
        return view('admin.clients.index', [
            'clients' => $query->paginate(20)->withQueryString(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'companyFilter' => $companyId,
            'status' => $filters['status'] ?? 'active',
        ]);
    }

    public function create(): View
    {
        return view('admin.clients.form', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', [
            'client' => $client,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function fields(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:4000'],
            'country_iso_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'dialing_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:15000'],
        ]);
        $data['full_name'] = trim($data['full_name']);
        if ($data['full_name'] === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'full_name' => 'Full name cannot be empty.',
            ]);
        }
        if (!empty($data['email'])) $data['email'] = strtolower(trim($data['email']));
        if (!empty($data['country_iso_code'])) {
            $data['country_iso_code'] = strtoupper($data['country_iso_code']);
        }
        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->fields($request);
        $client = Client::create(array_merge($data, ['source' => 'admin']));
        AuditLogger::record('admin.client.created', $request->user(), $client,
            Company::findOrFail($client->company_id), request: $request);
        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Client created.');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $this->fields($request);
        // Moving an existing client between tenant companies could expose
        // future linked jobs/documents. Keep company assignment immutable.
        if ((int) $data['company_id'] !== (int) $client->company_id) {
            return back()->withErrors([
                'company_id' => 'Client company cannot be changed after creation.',
            ]);
        }
        $client->update($data);
        AuditLogger::record('admin.client.updated', $request->user(), $client,
            Company::findOrFail($client->company_id), metadata: [
                'fields' => array_keys($data),
            ], request: $request);
        return back()->with('success', 'Client updated.');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $client->delete();
        AuditLogger::record('admin.client.archived', $request->user(), $client,
            Company::findOrFail($client->company_id), request: $request);
        return redirect()->route('admin.clients.index')
            ->with('success', 'Client archived. Records are retained.');
    }

    public function restore(Request $request, int $client): RedirectResponse
    {
        $target = Client::withTrashed()->findOrFail($client);
        abort_unless($target->trashed(), 404);
        $target->restore();
        AuditLogger::record('admin.client.restored', $request->user(), $target,
            Company::findOrFail($target->company_id), request: $request);
        return redirect()->route('admin.clients.edit', $target)
            ->with('success', 'Client restored.');
    }
}
