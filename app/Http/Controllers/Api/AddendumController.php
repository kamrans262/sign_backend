<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Addendum;
use App\Models\AddendumSigningRequest;
use App\Services\AddendumService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AddendumController extends Controller
{
    public function __construct(private readonly AddendumService $service) {}

    private function company(Request $request): \App\Models\Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof \App\Models\Company &&
            $company->status==='active',403);
        return $company;
    }

    private static function inputs(Request $request, bool $create = true): array
    {
        return $request->validate([
            'source_signed_artifact_id'=>$create ? ['required','integer','min:1'] : ['prohibited'],
            'number'=>['required','string','max:80'],
            'title'=>['required','string','max:255'],
            'change_description'=>['required','string','max:10000'],
            'effective_date'=>['required','date_format:Y-m-d'],
            'additional_work'=>['nullable','string','max:10000'],
            'quantity'=>['nullable','integer','between:1,100000'],
            'unit_price_minor'=>['nullable','integer','between:1,100000000000'],
            'vat_basis_points'=>['required','integer','between:0,10000'],
            'source_document_id'=>['prohibited'],
            'expected_revision'=>$create ? ['prohibited'] : ['required','integer','min:1'],
        ]);
    }

    private static function editable(array $data): array
    {
        $price=AddendumService::amounts($data);
        return array_merge($price,[
            'number'=>trim($data['number']),
            'title'=>trim($data['title']),
            'change_description'=>trim($data['change_description']),
            'effective_date'=>$data['effective_date'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filter=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'client_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['draft','approved','sent','signed'])],
            'per_page'=>['nullable','integer','between:1,100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        $query=Addendum::query()->where('company_id',$this->company($request)->id);
        if (!empty($filter['job_id'])) $query->where('service_job_id',$filter['job_id']);
        if (!empty($filter['client_id'])) $query->where('client_id',$filter['client_id']);
        if (!empty($filter['status'])) $query->where('status',$filter['status']);
        $records=$query->latest('id')->paginate($filter['per_page']??20);
        return response()->json(['data'=>[
            'addenda'=>$records->getCollection()->map(fn (Addendum $item)=>
                $this->service->payload($item))->values()->all(),
            'page'=>$records->currentPage(),'last_page'=>$records->lastPage(),
            'total'=>$records->total(),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function show(Request $request,int $addendum): JsonResponse
    {
        $item=$this->service->scoped($this->company($request)->id,$addendum);
        return response()->json(['data'=>[
            'addendum'=>$this->service->payload($item),
            'history'=>$this->service->history($item),
            'requests'=>$item->signingRequests->map(fn (AddendumSigningRequest $s)=>
                $this->requestPayload($s))->all(),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function history(Request $request,int $addendum): JsonResponse
    {
        $item=$this->service->scoped($this->company($request)->id,$addendum);
        $revisions=DB::table('addendum_revisions')->where('company_id',$item->company_id)
            ->where('addendum_id',$item->id)
            ->orderByDesc('revision')->get(['revision','created_at']);
        return response()->json(['data'=>[
            'events'=>$this->service->history($item),'revisions'=>$revisions,
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $fields=self::inputs($request);
        $values=self::editable($fields);
        $company=$this->company($request);
        abort_if($values['number']==='' || $values['title']==='' ||
            $values['change_description']==='',422,'Complete the addendum fields.');
        $item=DB::transaction(function () use ($company,$fields,$values,$request) {
            $source=$this->service->source($company->id,(int)$fields['source_signed_artifact_id']);
            $quote=$source->request->quote;
            abort_if(Addendum::query()->where('company_id',$company->id)
                ->where('number',$values['number'])->exists(),409,
                'This addendum number is already used within this company.');
            $item=Addendum::create(array_merge($values,[
                'company_id'=>$company->id,
                'source_signed_artifact_id'=>$source->id,
                'service_quote_id'=>$quote->id,
                'service_job_id'=>$quote->service_job_id,
                'client_id'=>$quote->client_id,
                'source_pdf_sha256'=>$source->signed_pdf_sha256,
                'source_revision'=>$source->source_revision,
                'currency'=>$quote->currency,
                'revision'=>1,'status'=>'draft',
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]));
            app(\App\Services\M23BillingService::class)->recordCreate(
                (int)$company->id,'addendum',(int)$item->id,$request->user()->id);
            $this->service->revision($item,$request->user()->id);
            $this->service->event($item,'created',$request->user()->id);
            AuditLogger::record('mobile.addendum.created',$request->user(),$item,
                $company,request:$request);
            return $item;
        });
        return response()->json(['data'=>['addendum'=>$this->service->payload($item)]],
            201,['Cache-Control'=>'private, no-store']);
    }

    public function update(Request $request,int $addendum): JsonResponse
    {
        $fields=self::inputs($request,false);
        $values=self::editable($fields);
        $company=$this->company($request);
        abort_if($values['number']==='' || $values['title']==='' ||
            $values['change_description']==='',422);
        $item=DB::transaction(function () use ($company,$addendum,$request,$fields,$values) {
            $item=Addendum::query()->where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($addendum);
            abort_unless($item->status==='draft',409,
                'Only an unsigned draft can be edited.');
            abort_unless((int)$item->revision===(int)$fields['expected_revision'],409,
                'Addendum changed elsewhere. Reload first.');
            abort_if(Addendum::query()->where('company_id',$company->id)
                ->where('number',$values['number'])->where('id','!=',$item->id)->exists(),
                409,'This addendum number is already used.');
            $item->update(array_merge($values,[
                'revision'=>$item->revision+1,'updated_by'=>$request->user()->id,
            ]));
            $this->service->revision($item,$request->user()->id);
            $this->service->event($item,'version_changed',$request->user()->id);
            AuditLogger::record('mobile.addendum.updated',$request->user(),$item,
                $company,request:$request);
            return $item;
        });
        return response()->json(['data'=>['addendum'=>$this->service->payload($item)]],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function approve(Request $request,int $addendum): JsonResponse
    {
        $data=$request->validate([
            'confirmed'=>['required','accepted'],
            'expected_revision'=>['required','integer','min:1'],
        ]);
        $company=$this->company($request);
        $item=DB::transaction(function () use ($company,$request,$addendum,$data) {
            $item=Addendum::query()->where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($addendum);
            abort_unless($item->status==='draft' &&
                (int)$item->revision===(int)$data['expected_revision'],409,
                'Only the currently reviewed draft can be approved.');
            app(\App\Services\M23BillingService::class)->recordIssue(
                (int)$company->id,'addendum',(int)$item->id,$request->user()?->id);
            $bytes=$this->service->createPdf($item);
            $sha=hash('sha256',$bytes);
            $path='addenda/approved/'.$company->id.'/'.$item->id.'/'.
                $item->revision.'/'.$sha.'.pdf';
            abort_unless(Storage::disk('local')->put($path,$bytes),503,
                'Unable to freeze approved addendum PDF.');
            $item->update([
                'status'=>'approved','approved_at'=>now(),
                'approved_pdf_sha256'=>$sha,'approved_pdf_path'=>$path,
                'approved_revision'=>$item->revision,
            ]);
            $this->service->event($item,'approved',$request->user()->id,
                ['sha256'=>$sha,'revision'=>$item->revision]);
            AuditLogger::record('mobile.addendum.approved',$request->user(),$item,
                $company,metadata:['revision'=>$item->revision],request:$request);
            return $item;
        });
        return response()->json(['data'=>['addendum'=>$this->service->payload($item)]],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function pdf(Request $request,int $addendum)
    {
        $company=$this->company($request);
        $item=$this->service->scoped($company->id,$addendum);
        $signed=$item->status==='signed';
        $bytes=$signed?$this->service->signedBytes($item):
            $this->service->approvedBytes($item);
        $this->service->event($item,'downloaded',$request->user()->id,
            ['signed'=>$signed]);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="Sign2Digital_Addendum.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function requestPayload(AddendumSigningRequest $item): array
    {
        return [
            'id'=>$item->id,'request_id'=>$item->request_uuid,
            'document_id'=>$item->document_uuid,
            'addendum_id'=>$item->addendum_id,
            'signer_name'=>$item->signer_name,'signer_email'=>$item->signer_email,
            'status'=>$item->expires_at->isPast() &&
                in_array($item->status,['ready','sent','viewed'],true)
                ? 'expired' : $item->status,
            'expires_at'=>$item->expires_at->toIso8601String(),
            'sent_at'=>$item->sent_at?->toIso8601String(),
            'viewed_at'=>$item->viewed_at?->toIso8601String(),
            'signed_at'=>$item->signed_at?->toIso8601String(),
            'revoked_at'=>$item->revoked_at?->toIso8601String(),
        ];
    }

    public function requests(Request $request,int $addendum): JsonResponse
    {
        $item=$this->service->scoped($this->company($request)->id,$addendum);
        return response()->json(['data'=>[
            'requests'=>$item->signingRequests->map(fn(AddendumSigningRequest $s)=>
                $this->requestPayload($s))->all(),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function requestSignature(Request $request,int $addendum): JsonResponse
    {
        $data=$request->validate([
            'signer_name'=>['required','string','max:255'],
            'signer_email'=>['required','email','max:255'],
            'expires_in_days'=>['nullable','integer','between:1,7'],
            'delivery'=>['required',Rule::in(['link','email'])],
            // Explicitly approved reissue: invalidate an unsent ready link before emailing.
            'replace_ready_link'=>['sometimes','boolean'],
        ]);
        if ($data['delivery']==='email' &&
            in_array((string)config('mail.default'),['log','array','null'],true)) {
            return response()->json(['message'=>'Email delivery is not configured.'],503);
        }
        $company=$this->company($request);
        $token=AddendumService::freshToken();
        $item=DB::transaction(function () use ($company,$request,$addendum,$data,$token) {
            $source=Addendum::query()->where('company_id',$company->id)
                ->lockForUpdate()->with('quote')->findOrFail($addendum);
            abort_unless(in_array($source->status,['approved','sent'],true),409,
                'Approve this separate addendum before asking for a signature.');
            $this->service->approvedBytes($source);
            abort_unless(trim((string)$source->quote->client_email)!=='' &&
                mb_strtolower(trim((string)$source->quote->client_email)) ===
                mb_strtolower(trim($data['signer_email'])),422,
                'Signer email must match the original verified customer.');
            $active=AddendumSigningRequest::query()->where('addendum_id',$source->id)
                ->whereIn('status',['ready','sent','viewed'])
                ->where('expires_at','>',now())->lockForUpdate()->first();
            if ($active !== null) {
                $replace=$data['delivery']==='email' &&
                    ($data['replace_ready_link']??false) === true &&
                    $active->status==='ready' && $active->sent_at===null &&
                    $active->viewed_at===null && $active->revoked_at===null &&
                    (int)$active->approved_revision===(int)$source->revision &&
                    hash_equals((string)$source->approved_pdf_sha256,
                        (string)$active->approved_pdf_sha256) &&
                    mb_strtolower((string)$active->signer_email) ===
                        mb_strtolower(trim($data['signer_email']));
                abort_unless($replace,409,
                    'An active signing request already exists. Use the existing receipt or explicitly replace an unsent link.');
                // The original raw link token is never stored. Reissuing an
                // unsent ready link rotates it and invalidates the old token.
                // Sent/viewed links are NEVER silently replaced or resent.
                $active->update(['status'=>'revoked','revoked_at'=>now()]);
                $this->service->event($source,'signature_link_replaced',
                    $request->user()->id,['signing_request_id'=>$active->id]);
            }
            $signer=AddendumSigningRequest::create([
                'addendum_id'=>$source->id,'company_id'=>$company->id,
                'created_by'=>$request->user()->id,
                'request_uuid'=>(string)Str::uuid(),
                'document_uuid'=>(string)Str::uuid(),
                'signer_uuid'=>(string)Str::uuid(),
                'token_sha256'=>hash('sha256',$token),
                'signer_name'=>trim($data['signer_name']),
                'signer_email'=>trim($data['signer_email']),
                'approved_revision'=>$source->revision,
                'approved_pdf_sha256'=>$source->approved_pdf_sha256,
                'consent_reference'=>hash('sha256',
                    $source->source_pdf_sha256."\n".$source->approved_pdf_sha256),
                'status'=>'ready',
                'expires_at'=>now()->addDays($data['expires_in_days']??7),
            ]);
            $this->service->event($source,'signature_requested',$request->user()->id,
                ['signing_request_id'=>$signer->id]);
            AuditLogger::record('mobile.addendum.signature_requested',$request->user(),
                $source,$company,request:$request);
            return $signer;
        });
        $link=URL::route('addenda.public.browser',['token'=>$token]);
        if ($data['delivery']==='email') {
            try {
                Mail::raw("Please review and sign your separate Sign2Digital addendum.\n\n".
                    "Secure link (expires {$item->expires_at->toIso8601String()}):\n".$link,
                    fn($message)=>$message->to($item->signer_email)
                        ->subject('Sign2Digital - Addendum signature requested'));
            } catch (\Throwable $error) {
                report($error);
                // A failed SMTP submission must not keep a live, undisclosed
                // capability that blocks retry. Keep the failure in audit.
                DB::transaction(function () use ($item,$company,$request,$addendum): void {
                    $locked=AddendumSigningRequest::query()
                        ->where('company_id',$company->id)->lockForUpdate()
                        ->findOrFail($item->id);
                    if (in_array($locked->status,['ready','sent','viewed'],true)) {
                        $locked->update(['status'=>'revoked','revoked_at'=>now()]);
                        $source=$this->service->scoped($company->id,$addendum);
                        $this->service->event($source,'signature_delivery_failed',
                            $request->user()->id,['signing_request_id'=>$locked->id]);
                    }
                });
                return response()->json(['message'=>'Email transport rejected the request; the link was revoked. You may retry.'],502);
            }
            $item->update(['status'=>'sent','sent_at'=>now()]);
            $source=$this->service->scoped($company->id,$addendum);
            $source->update(['status'=>'sent']);
            $this->service->event($source,'document_sent',$request->user()->id,
                ['channel'=>'email','signing_request_id'=>$item->id]);
            $link=null;
        }
        return response()->json(['data'=>[
            'request'=>$this->requestPayload($item),
            'secure_link'=>$link,
            'transport_accepted'=>$data['delivery']==='email',
            'delivered'=>false, // SMTP acceptance is not proof of mailbox delivery.
        ]],201,['Cache-Control'=>'private, no-store']);
    }

    public function revoke(Request $request,int $addendum,int $signature): JsonResponse
    {
        $company=$this->company($request);
        $item=DB::transaction(function () use ($company,$request,$addendum,$signature) {
            $source=$this->service->scoped($company->id,$addendum);
            $item=AddendumSigningRequest::query()->where('company_id',$company->id)
                ->where('addendum_id',$source->id)->lockForUpdate()
                ->findOrFail($signature);
            abort_unless(in_array($item->status,['ready','sent','viewed'],true),409);
            $item->update(['status'=>'revoked','revoked_at'=>now()]);
            $this->service->event($source,'request_revoked',$request->user()->id,
                ['signing_request_id'=>$item->id]);
            AuditLogger::record('mobile.addendum.signature_revoked',$request->user(),
                $source,$company,request:$request);
            return $item;
        });
        return response()->json(['data'=>['request'=>$this->requestPayload($item)]],
            200,['Cache-Control'=>'private, no-store']);
    }
}
