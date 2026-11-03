<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignedDocumentArtifact;
use App\Services\SignatureCompletionService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SignedDocumentController extends Controller
{
    public function __construct(private readonly SignatureCompletionService $completion) {}

    private function companyId(Request $request): int
    {
        $company=$request->attributes->get('company');
        abort_unless($company !== null && $company->status === 'active',403);
        return (int)$company->id;
    }

    private function scoped(Request $request, int $id): SignedDocumentArtifact
    {
        return SignedDocumentArtifact::query()
            ->where('company_id',$this->companyId($request))
            ->with('request.quote')->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $data=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'quote_id'=>['nullable','integer','min:1'],
        ]);
        $query=SignedDocumentArtifact::query()
            ->where('company_id',$this->companyId($request))
            ->with('request.quote:id,company_id,service_job_id,custom_quote_number,reference_number')
            ->orderByDesc('id');
        if (!empty($data['quote_id'])) $query->where('service_quote_id',$data['quote_id']);
        if (!empty($data['job_id'])) {
            $query->whereHas('request.quote',fn($q)=>
                $q->where('service_job_id',$data['job_id']));
        }
        $records=$query->paginate(30);
        return response()->json(['data'=>[
            'documents'=>$records->getCollection()->map(fn($item)=>$this->item($item))->all(),
            'total'=>$records->total(),
        ]]);
    }

    private function item(SignedDocumentArtifact $artifact): array
    {
        $artifact->loadMissing('request.quote');
        $request=$artifact->request;
        return [
            'id'=>$artifact->id,
            'request_id'=>$request->request_uuid,
            'document_id'=>$request->document_uuid,
            'quote_id'=>$artifact->service_quote_id,
            'job_id'=>$request->quote?->service_job_id,
            'quote_number'=>$request->quote?->custom_quote_number ?: $request->quote?->reference_number,
            'signer_name'=>$request->signer_name,
            'status'=>'signed',
            'source_revision'=>$artifact->source_revision,
            'source_sha256'=>$artifact->source_pdf_sha256,
            'signed_pdf_sha256'=>$artifact->signed_pdf_sha256,
            'signed_at'=>$request->signed_at?->toIso8601String(),
        ];
    }

    public function show(Request $request,int $document): JsonResponse
    {
        return response()->json(['data'=>[
            'document'=>$this->item($this->scoped($request,$document)),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function download(Request $request,int $document)
    {
        $artifact=$this->scoped($request,$document);
        $bytes=$this->completion->bytes($artifact);
        AuditLogger::record('mobile.signature.signed_pdf_downloaded',
            $request->user(),$artifact,$request->attributes->get('company'),
            metadata:['quote_id'=>$artifact->service_quote_id],
            request:$request);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_Quote.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function resend(Request $request,int $document): JsonResponse
    {
        $artifact=$this->scoped($request,$document);
        $delivered=$this->completion->emailFinalPdf($artifact);
        return response()->json(['data'=>[
            'delivered'=>$delivered,'document'=>$this->item($artifact),
        ]],$delivered ? 200 : 503,['Cache-Control'=>'private, no-store']);
    }
}
