<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\DocumentCatalogService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentCatalogService $documents) {}

    private function companyIdFor(string $document): int
    {
        abort_unless(preg_match('/^(quote|signed|managed|addendum)-([1-9][0-9]*)$/D',$document,$match),404);
        $table=match($match[1]) {
            'quote'=>'service_quotes', 'signed'=>'signed_document_artifacts',
            'addendum'=>'addenda',
            'managed'=>'managed_documents',
        };
        $id=DB::table($table)->where('id',(int)$match[2])->value('company_id');
        abort_unless($id,404);
        return (int)$id;
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'kind'=>['nullable',Rule::in(['quote','signed','managed','addendum'])],
            'category'=>['nullable',Rule::in(['quotes','contacts','reports','addends','receipts','invoices','other'])],
            'status'=>['nullable','string','max:24'],
            'page'=>['nullable','integer','min:1'],
        ]);
        $result=$this->documents->listing(isset($filters['company_id'])
            ? (int)$filters['company_id'] : null,$filters);
        return view('admin.documents.index',[
            'result'=>$result,'companies'=>Company::query()->orderBy('name')->get(['id','name']),
            'filters'=>$filters,'companyNames'=>Company::query()->pluck('name','id')->all(),
        ]);
    }

    public function show(string $document): View
    {
        $companyId=$this->companyIdFor($document);
        return view('admin.documents.show',[
            'document'=>$this->documents->resolve($companyId,$document),
            'company'=>Company::findOrFail($companyId),
            'versions'=>$this->documents->versions($companyId,$document),
            'attachments'=>$this->documents->attachments($companyId,$document),
            'history'=>$this->documents->history($companyId,$document),
        ]);
    }

    public function download(Request $request, string $document)
    {
        $companyId=$this->companyIdFor($document);
        $bytes=$this->documents->bytes($companyId,$document);
        $this->documents->recordEvent($companyId,$document,'downloaded',$request->user()->id);
        AuditLogger::record('admin.document.downloaded',$request->user(),null,
            Company::findOrFail($companyId),metadata:['document_id'=>$document],request:$request);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Document.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function downloadAttachment(Request $request, string $document, int $attachment)
    {
        $companyId=$this->companyIdFor($document);
        [$bytes,$mime]=$this->documents->attachmentBytes($companyId,$document,$attachment);
        $this->documents->recordEvent($companyId,$document,'downloaded',$request->user()->id);
        AuditLogger::record('admin.document.attachment_downloaded',$request->user(),null,
            Company::findOrFail($companyId),
            metadata:['document_id'=>$document,'attachment_id'=>$attachment],request:$request);
        return response($bytes,200,[
            'Content-Type'=>$mime,
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Attachment_'.$attachment.'.'.
                ($mime==='application/pdf'?'pdf':($mime==='image/png'?'png':'jpg')).'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate(['company_id'=>['required','integer','exists:companies,id']]);
        $company=Company::query()->where('status','active')->findOrFail($data['company_id']);
        $item=$this->documents->saveFile($company->id,$request);
        AuditLogger::record('admin.document.created',$request->user(),null,$company,
            metadata:['document_id'=>$item['id']],request:$request);
        return redirect()->route('admin.documents.show',$item['id'])
            ->with('success','Document uploaded securely.');
    }

    public function addVersion(Request $request,string $document): RedirectResponse
    {
        $companyId=$this->companyIdFor($document);
        $item=$this->documents->saveFile($companyId,$request,$document);
        AuditLogger::record('admin.document.version_added',$request->user(),null,
            Company::findOrFail($companyId),
            metadata:['document_id'=>$document,'version'=>$item['version']],request:$request);
        return redirect()->route('admin.documents.show',$document)
            ->with('success','New document version recorded.');
    }

    public function addAttachment(Request $request,string $document): RedirectResponse
    {
        $companyId=$this->companyIdFor($document);
        $attachment=$this->documents->saveAttachment($companyId,$request,$document);
        AuditLogger::record('admin.document.attachment_added',$request->user(),null,
            Company::findOrFail($companyId),
            metadata:['document_id'=>$document,'attachment_id'=>$attachment['id']],request:$request);
        return redirect()->route('admin.documents.show',$document)
            ->with('success','Attachment added. Original documents remain unchanged.');
    }
}
