<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentCatalogService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentCatalogService $documents) {}

    private function company(Request $request)
    {
        $company=$request->attributes->get('company');
        abort_unless($company && $company->status==='active',403);
        return $company;
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'category'=>['nullable',Rule::in(['quotes','contacts','reports','addends','receipts','invoices','other'])],
            'kind'=>['nullable',Rule::in(['quote','signed','managed','addendum'])],
            'status'=>['nullable','string','max:24'],
            'client_id'=>['nullable','integer','min:1'],
            'job_id'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return response()->json(['data'=>$this->documents->listing($this->company($request)->id,$filters)],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $companyId=$this->company($request)->id;
        return response()->json(['data'=>[
            'document'=>$this->documents->resolve($companyId,$document),
            'versions'=>$this->documents->versions($companyId,$document),
            'attachments'=>$this->documents->attachments($companyId,$document),
            'history'=>$this->documents->history($companyId,$document),
            'related'=>$this->documents->related($companyId,$document),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function history(Request $request, string $document): JsonResponse
    {
        return response()->json(['data'=>['events'=>
            $this->documents->history($this->company($request)->id,$document)]],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function versions(Request $request, string $document): JsonResponse
    {
        return response()->json(['data'=>['versions'=>
            $this->documents->versions($this->company($request)->id,$document)]],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function attachments(Request $request, string $document): JsonResponse
    {
        return response()->json(['data'=>['attachments'=>
            $this->documents->attachments($this->company($request)->id,$document)]],
            200,['Cache-Control'=>'private, no-store']);
    }

    public function download(Request $request, string $document)
    {
        $company=$this->company($request);
        $item=$this->documents->resolve($company->id,$document);
        $bytes=$this->documents->bytes($company->id,$document);
        $this->documents->recordEvent($company->id,$document,'downloaded',$request->user()->id);
        AuditLogger::record('mobile.document.downloaded',$request->user(),null,$company,
            metadata:['document_id'=>$document],request:$request);
        $filename=preg_replace('/[^a-zA-Z0-9_-]/','_', (string)$item['number']);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_'.$filename.'.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function downloadAttachment(Request $request, string $document, int $attachment)
    {
        $company=$this->company($request);
        [$bytes,$mime]=$this->documents->attachmentBytes($company->id,$document,$attachment);
        $this->documents->recordEvent($company->id,$document,'downloaded',$request->user()->id);
        AuditLogger::record('mobile.document.attachment_downloaded',$request->user(),null,$company,
            metadata:['document_id'=>$document,'attachment_id'=>$attachment],request:$request);
        return response($bytes,200,[
            'Content-Type'=>$mime,
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Attachment_'.$attachment.'.'.
                ($mime==='application/pdf'?'pdf':($mime==='image/png'?'png':'jpg')).'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $item=$this->documents->saveFile($company->id,$request);
        AuditLogger::record('mobile.document.created',$request->user(),null,$company,
            metadata:['document_id'=>$item['id']],request:$request);
        return response()->json(['data'=>['document'=>$item]],201,['Cache-Control'=>'private, no-store']);
    }

    public function addVersion(Request $request, string $document): JsonResponse
    {
        $company=$this->company($request);
        $item=$this->documents->saveFile($company->id,$request,$document);
        AuditLogger::record('mobile.document.version_added',$request->user(),null,$company,
            metadata:['document_id'=>$document,'version'=>$item['version']],request:$request);
        return response()->json(['data'=>['document'=>$item]],200,['Cache-Control'=>'private, no-store']);
    }

    public function addAttachment(Request $request, string $document): JsonResponse
    {
        $company=$this->company($request);
        $item=$this->documents->saveAttachment($company->id,$request,$document);
        AuditLogger::record('mobile.document.attachment_added',$request->user(),null,$company,
            metadata:['document_id'=>$document,'attachment_id'=>$item['id']],request:$request);
        return response()->json(['data'=>['attachment'=>$item]],201,
            ['Cache-Control'=>'private, no-store']);
    }
}
