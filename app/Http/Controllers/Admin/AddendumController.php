<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addendum;
use App\Models\AddendumSigningRequest;
use App\Models\Company;
use App\Models\SignedDocumentArtifact;
use App\Services\AddendumService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AddendumController extends Controller
{
    public function __construct(private readonly AddendumService $service) {}

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'status'=>['nullable',Rule::in(['draft','approved','sent','signed'])],
        ]);
        $query=Addendum::query()->with(['company:id,name','quote:id,reference_number,custom_quote_number'])
            ->latest('id');
        if (!empty($filters['company_id'])) $query->where('company_id',$filters['company_id']);
        if (!empty($filters['status'])) $query->where('status',$filters['status']);
        if (!empty($filters['q'])) {
            $needle=trim($filters['q']);
            $query->where(fn ($q)=>$q->where('number','like','%'.$needle.'%')
                ->orWhere('title','like','%'.$needle.'%'));
        }
        return view('admin.addenda.index',[
            'addenda'=>$query->paginate(20)->withQueryString(),
            'companies'=>Company::orderBy('name')->get(['id','name']),
            'filters'=>$filters,
        ]);
    }

    private function sources(): \Illuminate\Database\Eloquent\Collection
    {
        return SignedDocumentArtifact::query()->with(['request.quote.company'])
            ->whereHas('request', fn($q)=>$q->where('status','signed'))
            ->latest('id')->get();
    }

    public function create(): View
    {
        return view('admin.addenda.form',[
            'addendum'=>null,'companies'=>Company::where('status','active')
                ->orderBy('name')->get(['id','name']),
            'sources'=>$this->sources(),
        ]);
    }

    public function edit(int $addendum): View
    {
        $item=Addendum::findOrFail($addendum);
        abort_unless($item->status==='draft',409,
            'Approved or signed addenda cannot be edited.');
        return view('admin.addenda.form',[
            'addendum'=>$item,'companies'=>Company::where('status','active')
                ->orderBy('name')->get(['id','name']),
            'sources'=>$this->sources(),
        ]);
    }

    private static function form(Request $request,bool $create): array
    {
        $input=$request->validate([
            'company_id'=>$create?['required','integer','exists:companies,id']:['prohibited'],
            'source_signed_artifact_id'=>$create?['required','integer','min:1']:['prohibited'],
            'number'=>['required','string','max:80'],
            'title'=>['required','string','max:255'],
            'change_description'=>['required','string','max:10000'],
            'effective_date'=>['required','date_format:Y-m-d'],
            'additional_work'=>['nullable','string','max:10000'],
            'quantity'=>['nullable','integer','between:1,100000'],
            'unit_price'=>['nullable','string','regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'vat_percent'=>['required','string','regex:/^\d{1,3}(?:\.\d{1,2})?$/'],
            'expected_revision'=>$create?['prohibited']:['required','integer','min:1'],
        ]);
        $price=$input['unit_price']??null;
        $minor=null;
        if ($price!==null && $price!=='') {
            $parts=explode('.',$price);
            $minor=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
        }
        $vatParts=explode('.',$input['vat_percent']);
        $bps=(int)$vatParts[0]*100+(int)str_pad($vatParts[1]??'',2,'0');
        $amounts=AddendumService::amounts([
            'additional_work'=>$input['additional_work']??'',
            'quantity'=>$input['quantity']??null,
            'unit_price_minor'=>$minor,
            'vat_basis_points'=>$bps,
        ]);
        $clean=array_merge($amounts,[
            'number'=>trim($input['number']),
            'title'=>trim($input['title']),
            'change_description'=>trim($input['change_description']),
            'effective_date'=>$input['effective_date'],
        ]);
        abort_if($clean['number']===''||$clean['title']===''||
            $clean['change_description']==='',422,'Complete the addendum fields.');
        return [$input,$clean];
    }

    public function store(Request $request): RedirectResponse
    {
        [$input,$values]=self::form($request,true);
        $company=Company::query()->where('status','active')->findOrFail($input['company_id']);
        $item=DB::transaction(function () use ($request,$input,$values,$company) {
            $source=$this->service->source($company->id,(int)$input['source_signed_artifact_id']);
            $quote=$source->request->quote;
            abort_if(Addendum::where('company_id',$company->id)
                ->where('number',$values['number'])->exists(),409,
                'Addendum number is already used.');
            $item=Addendum::create(array_merge($values,[
                'company_id'=>$company->id,'source_signed_artifact_id'=>$source->id,
                'service_quote_id'=>$quote->id,
                'service_job_id'=>$quote->service_job_id,
                'client_id'=>$quote->client_id,
                'currency'=>$quote->currency,
                'source_pdf_sha256'=>$source->signed_pdf_sha256,
                'source_revision'=>$source->source_revision,
                'status'=>'draft','revision'=>1,
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]));
            $this->service->revision($item,$request->user()->id);
            $this->service->event($item,'created',$request->user()->id);
            AuditLogger::record('admin.addendum.created',$request->user(),$item,
                $company,request:$request);
            return $item;
        });
        return redirect()->route('admin.addenda.show',$item->id)
            ->with('success','Addendum draft created.');
    }

    public function update(Request $request,int $addendum): RedirectResponse
    {
        [$input,$values]=self::form($request,false);
        DB::transaction(function () use ($request,$addendum,$input,$values) {
            $item=Addendum::lockForUpdate()->findOrFail($addendum);
            abort_unless($item->status==='draft' &&
                (int)$input['expected_revision']===(int)$item->revision,409,
                'Addendum has changed. Reload before editing.');
            abort_if(Addendum::where('company_id',$item->company_id)
                ->where('number',$values['number'])->where('id','!=',$item->id)
                ->exists(),409,'This addendum number is in use.');
            $item->update(array_merge($values,[
                'revision'=>$item->revision+1,'updated_by'=>$request->user()->id,
            ]));
            $this->service->revision($item,$request->user()->id);
            $this->service->event($item,'version_changed',$request->user()->id);
            AuditLogger::record('admin.addendum.updated',$request->user(),$item,
                $item->company,request:$request);
        });
        return redirect()->route('admin.addenda.show',$addendum)
            ->with('success','Draft updated.');
    }

    public function show(int $addendum): View
    {
        $item=Addendum::with(['company','quote','source','signingRequests'])
            ->findOrFail($addendum);
        $revisions=DB::table('addendum_revisions')
            ->where('addendum_id',$item->id)->orderByDesc('revision')->get(['revision','created_at']);
        return view('admin.addenda.show',[
            'addendum'=>$item,'history'=>$this->service->history($item),
            'revisions'=>$revisions,
        ]);
    }

    public function approve(Request $request,int $addendum): RedirectResponse
    {
        $fields=$request->validate([
            'confirmed'=>['required','accepted'],
            'expected_revision'=>['required','integer','min:1'],
        ]);
        DB::transaction(function () use ($request,$addendum,$fields) {
            $item=Addendum::lockForUpdate()->findOrFail($addendum);
            abort_unless($item->status==='draft' &&
                (int)$item->revision===(int)$fields['expected_revision'],409,
                'Only the current reviewed draft can be approved.');
            $bytes=$this->service->createPdf($item);
            $hash=hash('sha256',$bytes);
            $path='addenda/approved/'.$item->company_id.'/'.$item->id.'/'.
                $item->revision.'/'.$hash.'.pdf';
            abort_unless(Storage::disk('local')->put($path,$bytes),503);
            $item->update([
                'status'=>'approved','approved_at'=>now(),
                'approved_pdf_sha256'=>$hash,'approved_pdf_path'=>$path,
                'approved_revision'=>$item->revision,
            ]);
            $this->service->event($item,'approved',$request->user()->id,
                ['sha256'=>$hash,'revision'=>$item->revision]);
            AuditLogger::record('admin.addendum.approved',$request->user(),$item,
                $item->company,request:$request);
        });
        return redirect()->route('admin.addenda.show',$addendum)
            ->with('success','Approved addendum PDF frozen. Original signed quote unchanged.');
    }

    public function pdf(Request $request,int $addendum)
    {
        $item=Addendum::findOrFail($addendum);
        $bytes=$item->status==='signed'?$this->service->signedBytes($item):
            $this->service->approvedBytes($item);
        $this->service->event($item,'downloaded',$request->user()->id);
        AuditLogger::record('admin.addendum.downloaded',$request->user(),$item,
            $item->company,request:$request);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Addendum.pdf"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function requestSignature(Request $request,int $addendum): RedirectResponse
    {
        $data=$request->validate([
            'signer_name'=>['required','string','max:255'],
            'signer_email'=>['required','email','max:255'],
            'expires_in_days'=>['required','integer','between:1,7'],
            'delivery'=>['required',Rule::in(['link','email'])],
        ]);
        if ($data['delivery']==='email' &&
            in_array((string)config('mail.default'),['log','array','null'],true)) {
            return back()->withErrors(['email'=>'Email transport is not configured.']);
        }
        $token=AddendumService::freshToken();
        $item=DB::transaction(function () use ($request,$addendum,$data,$token) {
            $source=Addendum::with('quote')->lockForUpdate()->findOrFail($addendum);
            abort_unless(in_array($source->status,['approved','sent'],true),409);
            $this->service->approvedBytes($source);
            abort_unless(mb_strtolower(trim($data['signer_email'])) ===
                mb_strtolower(trim((string)$source->quote->client_email)) &&
                trim((string)$source->quote->client_email)!=='',422,
                'The signer must be the original approved customer.');
            abort_if(AddendumSigningRequest::where('addendum_id',$source->id)
                ->whereIn('status',['ready','sent','viewed'])
                ->where('expires_at','>',now())->exists(),409);
            $item=AddendumSigningRequest::create([
                'addendum_id'=>$source->id,'company_id'=>$source->company_id,
                'created_by'=>$request->user()->id,'request_uuid'=>(string)Str::uuid(),
                'document_uuid'=>(string)Str::uuid(),'signer_uuid'=>(string)Str::uuid(),
                'token_sha256'=>hash('sha256',$token),
                'signer_name'=>trim($data['signer_name']),
                'signer_email'=>trim($data['signer_email']),
                'approved_revision'=>$source->revision,
                'approved_pdf_sha256'=>$source->approved_pdf_sha256,
                'consent_reference'=>hash('sha256',
                    $source->source_pdf_sha256."\n".$source->approved_pdf_sha256),
                'status'=>'ready','expires_at'=>now()->addDays($data['expires_in_days']),
            ]);
            $this->service->event($source,'signature_requested',$request->user()->id,
                ['signing_request_id'=>$item->id]);
            AuditLogger::record('admin.addendum.signature_requested',$request->user(),
                $source,$source->company,request:$request);
            return $item;
        });
        $url=URL::route('addenda.public.browser',['token'=>$token]);
        if ($data['delivery']==='email') {
            try {
                Mail::raw("Review and sign this separate Sign2Digital addendum:\n".$url,
                    fn($mail)=>$mail->to($item->signer_email)
                        ->subject('Sign2Digital - Addendum signature'));
            } catch (\Throwable $error) {
                report($error);
                return redirect()->route('admin.addenda.show',$addendum)
                    ->withErrors(['email'=>'Request created, email not delivered.']);
            }
            $item->update(['status'=>'sent','sent_at'=>now()]);
            $source=Addendum::findOrFail($addendum);
            $source->update(['status'=>'sent']);
            $this->service->event($source,'document_sent',$request->user()->id,
                ['channel'=>'email','signing_request_id'=>$item->id]);
            return redirect()->route('admin.addenda.show',$addendum)
                ->with('success','Addendum signature email sent.');
        }
        // One-time session flash. Bearer token is NEVER written to database or logs.
        return redirect()->route('admin.addenda.show',$addendum)
            ->with('secure_addendum_link',$url)
            ->with('success','Signing link created. Copying it does not confirm delivery.');
    }

    public function revoke(Request $request,int $addendum,int $signature): RedirectResponse
    {
        DB::transaction(function () use ($request,$addendum,$signature) {
            $source=Addendum::findOrFail($addendum);
            $item=AddendumSigningRequest::where('company_id',$source->company_id)
                ->where('addendum_id',$source->id)->lockForUpdate()->findOrFail($signature);
            abort_unless(in_array($item->status,['ready','sent','viewed'],true),409);
            $item->update(['status'=>'revoked','revoked_at'=>now()]);
            $this->service->event($source,'request_revoked',$request->user()->id,
                ['signing_request_id'=>$item->id]);
            AuditLogger::record('admin.addendum.signature_revoked',$request->user(),
                $source,$source->company,request:$request);
        });
        return redirect()->route('admin.addenda.show',$addendum)
            ->with('success','Addendum signing link revoked.');
    }
}
