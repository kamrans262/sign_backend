<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GeneralDocumentService;
use App\Services\GeneralDocumentIssueService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class GeneralDocumentController extends Controller
{
    public function __construct(private readonly GeneralDocumentService $documents,
        private readonly GeneralDocumentIssueService $issues) {}

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }

    private function record(int $id): object
    {
        return DB::table('general_documents')->where('id',$id)->firstOrFail();
    }

    private function choices(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name']),
            'clients'=>DB::table('clients')->orderBy('id','desc')->limit(1000)
                ->get(['id','company_id','full_name']),
            'jobs'=>DB::table('service_jobs')->where('category','!=','archived')
                ->orderByDesc('id')->limit(1000)
                ->get(['id','company_id','client_id','title']),
        ];
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'q'=>['nullable','string','max:255'],
            'type'=>['nullable',Rule::in(['letter','statement','power_of_attorney','acceptance','custom'])],
            'status'=>['nullable',Rule::in(['draft','reviewed','approved','archived'])],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.general-documents.index',array_merge($this->choices(),[
            'result'=>$this->documents->listing(null,$filters),
            'filters'=>$filters,
        ]));
    }

    public function create(): View
    {
        return view('admin.general-documents.form',$this->choices());
    }

    public function edit(int $document): View
    {
        $row=$this->record($document);
        $issued=DB::table('general_document_issues')
            ->where('company_id',$row->company_id)
            ->where('general_document_id',$document)->exists();
        return view('admin.general-documents.form',array_merge($this->choices(),[
            'record'=>$this->documents->detail($row->company_id,$document),
            'issued'=>$issued?$this->issues->metadata($row->company_id,$document):null,
        ]));
    }

    private function prepared(Request $request): void
    {
        $body=$request->input('document_text');
        if(is_string($body)){
            $parts=preg_split('/\R{2,}/u',trim($body))?:[];
            $blocks=[];
            foreach($parts as $part){
                if(trim($part)==='')continue;
                $blocks[]=['type'=>'paragraph','text'=>trim($part),
                    'bold'=>false,'italic'=>false];
            }
            $request->merge(['blocks'=>$blocks,'source'=>'manual']);
        }
        foreach(['client_id','job_id'] as $key){
            if($request->input($key)==='')$request->merge([$key=>null]);
        }
    }

    public function store(Request $request)
    {
        $data=$request->validate(['company_id'=>['required','integer','exists:companies,id']]);
        $this->prepared($request);
        $saved=$this->documents->save($request,$this->company((int)$data['company_id']));
        return redirect()->route('admin.general-documents.edit',$saved['id'])
            ->with('success','General document draft created; no customer communication sent.');
    }

    public function update(Request $request,int $document)
    {
        $row=$this->record($document);
        $this->prepared($request);
        $this->documents->save($request,$this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','New document revision saved.');
    }

    public function transition(Request $request,int $document,string $action)
    {
        abort_unless(in_array($action,['review','approve','archive'],true),404);
        $row=$this->record($document);
        $this->documents->transition($request,$this->company($row->company_id),
            $document,$action);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','General document '.$action.' completed and audited.');
    }

    public function upload(Request $request,int $document)
    {
        $row=$this->record($document);
        $this->documents->upload($request,$this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Private attachment saved and fingerprint recorded.');
    }

    public function removeAttachment(Request $request,int $document,int $attachment)
    {
        $row=$this->record($document);
        $this->documents->removeAttachment($request,$this->company($row->company_id),
            $document,$attachment);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Attachment removed from active view; history retained.');
    }

    public function attachment(int $document,int $attachment)
    {
        $row=$this->record($document);
        $file=$this->documents->attachmentBytes($row->company_id,$document,$attachment);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="general-document-attachment-'.$attachment.'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function issue(Request $request,int $document)
    {
        $row=$this->record($document);
        $this->issues->issue($request,$this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Official document issued and verified.');
    }

    public function issuedPdf(int $document)
    {
        $row=$this->record($document);
        $bytes=$this->issues->issuedPdf($row->company_id,$document);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_General_Document_'.
                $document.'.pdf"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function customerLink(Request $request,int $document)
    {
        $row=$this->record($document);
        $result=$this->issues->link($request,$this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Private customer link created. Not emailed.')
            ->with('new_general_customer_link',$result['secure_link']);
    }

    public function signature(Request $request,int $document)
    {
        $row=$this->record($document);
        $request->merge(['confirm'=>true]);
        $result=$this->issues->requestSignature($request,
            $this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Signature invitation created. Not emailed.')
            ->with('new_general_signature_link',$result['signature_link']);
    }

    public function revokeLink(Request $request,int $document,int $link)
    {
        $row=$this->record($document);
        $this->issues->revokeLink($request,$this->company($row->company_id),$document,$link);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Private link revoked.');
    }

    public function revokeSignature(Request $request,int $document,int $signature)
    {
        $row=$this->record($document);
        $this->issues->revokeSignature($request,$this->company($row->company_id),
            $document,$signature);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Pending signature request revoked.');
    }

    public function signedPdf(int $document,int $signature)
    {
        $row=$this->record($document);
        $bytes=$this->issues->signedForBusiness($row->company_id,$document,$signature);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_General_Document_'.
                $document.'.pdf"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function send(Request $request,int $document)
    {
        $row=$this->record($document);
        if(!$request->filled('request_key'))$request->merge([
            'request_key'=>'general-mail-'.(string)Str::uuid(),
        ]);
        $this->issues->send($request,$this->company($row->company_id),$document);
        return redirect()->route('admin.general-documents.edit',$document)
            ->with('success','Email submitted to configured transport; inbox delivery not guaranteed.');
    }

}
