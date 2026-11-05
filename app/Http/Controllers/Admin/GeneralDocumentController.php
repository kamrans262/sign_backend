<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GeneralDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class GeneralDocumentController extends Controller
{
    public function __construct(private readonly GeneralDocumentService $documents) {}

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
        return view('admin.general-documents.form',array_merge($this->choices(),[
            'record'=>$this->documents->detail($row->company_id,$document),
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
}
