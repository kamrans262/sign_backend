<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureWorkflowService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SignatureRequestController extends Controller
{
    public function __construct(private readonly SignatureWorkflowService $workflow) {}

    public function index(Request $request): View
    {
        $filter = $request->validate([
            'q' => ['nullable','string','max:255'],
            'company_id' => ['nullable','integer','exists:companies,id'],
            'status' => ['nullable',Rule::in(['ready','sent','viewed','signed','revoked'])],
        ]);
        $query = Signing::query()->with([
            'company:id,name', 'quote:id,reference_number,custom_quote_number,service_job_id',
        ])->latest('id');
        if (!empty($filter['company_id'])) {
            $query->where('company_id', $filter['company_id']);
        }
        if (!empty($filter['status'])) $query->where('status', $filter['status']);
        if (!empty($filter['q'])) {
            $needle = trim($filter['q']);
            $query->where(function ($q) use ($needle): void {
                $q->where('signer_name', 'like', '%'.$needle.'%')
                  ->orWhere('signer_email', 'like', '%'.$needle.'%')
                  ->orWhereHas('quote', fn ($s) =>
                    $s->where('reference_number','like','%'.$needle.'%')
                      ->orWhere('custom_quote_number','like','%'.$needle.'%'));
            });
        }
        return view('admin.signatures.index', [
            'requests'=>$query->paginate(20)->withQueryString(),
            'companies'=>Company::orderBy('name')->get(['id','name']),
            'search'=>trim((string) ($filter['q'] ?? '')),
            'companyFilter'=>(int) ($filter['company_id'] ?? 0),
            'status'=>$filter['status'] ?? '',
        ]);
    }

    public function show(int $signature): View
    {
        return view('admin.signatures.show', [
            'record'=>Signing::query()->with(['company:id,name',
                'quote:id,reference_number,custom_quote_number,service_job_id,approved_pdf_sha256',
                'events','artifact'])->findOrFail($signature),
        ]);
    }

    public function revoke(Request $request, int $signature): RedirectResponse
    {
        DB::transaction(function () use ($request,$signature): void {
            $item = Signing::query()->lockForUpdate()->findOrFail($signature);
            abort_unless(in_array($item->status, ['ready','sent','viewed'],true),409,
                'Only outstanding requests can be revoked.');
            $item->update(['status'=>'revoked','revoked_at'=>now()]);
            $this->workflow->event($item,'request_revoked',$request,$request->user()->id);
            AuditLogger::record('admin.signature.revoked',$request->user(),$item,
                $item->company,request:$request);
        });
        return redirect()->route('admin.signatures.show',$signature)
            ->with('success','The signing link was revoked.');
    }
}
