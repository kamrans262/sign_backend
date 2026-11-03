<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureWorkflowService;
use App\Services\SignatureCompletionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SignatureRequestController extends Controller
{
    public function __construct(
        private readonly SignatureWorkflowService $workflow,
        private readonly SignatureCompletionService $completion,
    ) {}

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
    public function signedPdf(Request $request,int $signature)
    {
        $record=Signing::query()->with('artifact')->findOrFail($signature);
        abort_unless($record->status==='signed' && $record->artifact,404);
        $bytes=$this->completion->bytes($record->artifact);
        AuditLogger::record('admin.signature.signed_pdf_downloaded',
            $request->user(),$record,$record->company,
            metadata:['quote_id'=>$record->service_quote_id],request:$request);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_Quote.pdf"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function resend(Request $request,int $signature): RedirectResponse
    {
        if (in_array((string)config('mail.default'),['log','array','null'],true)) {
            return redirect()->route('admin.signatures.show',$signature)
                ->withErrors(['email'=>'Email transport is not configured.']);
        }
        $token=$this->workflow::freshToken();
        $record=DB::transaction(function () use ($request,$signature,$token) {
            $record=Signing::query()->with('quote')->lockForUpdate()->findOrFail($signature);
            abort_unless($record->mode==='remote',409);
            $this->workflow->validForSigning($record);
            $record->update(['token_sha256'=>hash('sha256',$token)]);
            $this->workflow->event($record,'request_link_rotated',$request,$request->user()->id);
            return $record;
        });
        try {
            $url=$this->workflow->publicUrl($token);
            Mail::raw("Please sign your Sign2Digital quote using this secure link:\n".$url,
                fn($message)=>$message->to($record->signer_email)
                    ->subject('Sign2Digital signature reminder'));
        } catch (\Throwable $error) {
            report($error);
            return redirect()->route('admin.signatures.show',$signature)
                ->withErrors(['email'=>'The signing reminder could not be delivered.']);
        }
        $record->update(['status'=>'sent','sent_at'=>now()]);
        $this->workflow->event($record,'reminder_sent',$request,$request->user()->id,
            ['channel'=>'email']);
        AuditLogger::record('admin.signature.reminder_sent',$request->user(),$record,
            $record->company,request:$request);
        return redirect()->route('admin.signatures.show',$signature)
            ->with('success','Signature reminder sent and previous link revoked.');
    }

    public function resendSigned(Request $request,int $signature): RedirectResponse
    {
        $record=Signing::query()->with('artifact')->findOrFail($signature);
        abort_unless($record->status==='signed' && $record->artifact,404);
        if (!$this->completion->emailFinalPdf($record->artifact)) {
            return redirect()->route('admin.signatures.show',$signature)
                ->withErrors(['email'=>'The signed PDF could not be emailed.']);
        }
        AuditLogger::record('admin.signature.signed_pdf_resent',$request->user(),
            $record,$record->company,request:$request);
        return redirect()->route('admin.signatures.show',$signature)
            ->with('success','Signed PDF emailed to the customer.');
    }

}
