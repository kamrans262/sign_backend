<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureCompletionService;
use App\Services\SignatureWorkflowService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InPersonSigningController extends Controller
{
    public function __construct(
        private readonly SignatureWorkflowService $workflow,
        private readonly SignatureCompletionService $completion,
    ) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company,403);
        return $company;
    }

    public function start(Request $request, int $quote): JsonResponse
    {
        $company=$this->company($request);
        $item=DB::transaction(function () use ($request,$quote,$company) {
            $source=ServiceQuote::query()->where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($quote);
            $this->workflow->approvedBytes($source);
            abort_if(Signing::query()->where('service_quote_id',$source->id)
                ->where('status','signed')->exists(),409,
                'This quote has already been signed.');
            abort_if(Signing::query()->where('service_quote_id',$source->id)
                ->whereIn('status',['ready','sent','viewed'])
                ->where('expires_at','>',now())->exists(),409,
                'A signing session is already active.');
            $item=Signing::create([
                'company_id'=>$company->id,
                'service_quote_id'=>$source->id,
                'created_by'=>$request->user()->id,
                'business_user_id'=>$request->user()->id,
                'mode'=>'in_person',
                'request_uuid'=>(string) Str::uuid(),
                'document_uuid'=>(string) Str::uuid(),
                'signer_uuid'=>(string) Str::uuid(),
                'business_signer_uuid'=>(string) Str::uuid(),
                // In-person sessions never expose their unused bearer token.
                'token_sha256'=>hash('sha256',$this->workflow::freshToken()),
                'signer_name'=>$source->client_name,
                'signer_email'=>$source->client_email ?? '',
                'business_signer_name'=>$request->user()->name,
                'approved_revision'=>$source->revision,
                'approved_pdf_sha256'=>$source->approved_pdf_sha256,
                'consent_reference'=>hash('sha256',
                    ($source->terms??'')."\n".$source->approved_pdf_sha256),
                'quote_link_version'=>$source->link_version,
                'status'=>'ready',
                'expires_at'=>now()->addHours(4),
            ]);
            $this->workflow->event($item,'signature_requested',$request,$request->user()->id,
                ['mode'=>'in_person']);
            AuditLogger::record('mobile.signature.in_person_started',$request->user(),
                $item,$company,request:$request);
            return $item;
        });
        return response()->json(['data'=>[
            'request_id'=>$item->id,
            'documentId'=>$item->document_uuid,
            'requestId'=>$item->request_uuid,
            'quoteId'=>(string)$item->service_quote_id,
            'customerSignerId'=>$item->signer_uuid,
            'businessSignerId'=>$item->business_signer_uuid,
            'documentVersion'=>$item->approved_revision.':'.$item->approved_pdf_sha256,
            'consentReference'=>$item->consent_reference,
            'expiresAt'=>$item->expires_at->toIso8601String(),
        ]],201,['Cache-Control'=>'private, no-store']);
    }

    private function strokes(array $raw): array
    {
        // Keep data bounded and independent of the device's screen dimensions.
        $total=0;
        foreach ($raw as $stroke) {
            $total+=count($stroke);
            abort_if($total>8000,422,'Too many signature points.');
        }
        abort_if($total < 3,422,'Draw each required signature before confirming.');
        return $raw;
    }

    public function submit(Request $request, int $signature): JsonResponse
    {
        $data=$request->validate([
            'quoteId'=>['required','string','max:32'],
            'documentId'=>['required','uuid'],
            'requestId'=>['required','uuid'],
            'customerSignerId'=>['required','uuid'],
            'businessSignerId'=>['required','uuid','different:customerSignerId'],
            'documentVersion'=>['required','string','max:100'],
            'consentReference'=>['required','string','size:64'],
            'consentAccepted'=>['required','accepted'],
            'customerStrokes'=>['required','array','min:1','max:50'],
            'customerStrokes.*'=>['required','array','min:2','max:160'],
            'customerStrokes.*.*'=>['required','array:x,y'],
            'customerStrokes.*.*.x'=>['required','numeric','between:0,1'],
            'customerStrokes.*.*.y'=>['required','numeric','between:0,1'],
            'businessStrokes'=>['required','array','min:1','max:50'],
            'businessStrokes.*'=>['required','array','min:2','max:160'],
            'businessStrokes.*.*'=>['required','array:x,y'],
            'businessStrokes.*.*.x'=>['required','numeric','between:0,1'],
            'businessStrokes.*.*.y'=>['required','numeric','between:0,1'],
        ]);
        $company=$this->company($request);
        $record=Signing::query()->where('company_id',$company->id)
            ->where('mode','in_person')->findOrFail($signature);
        abort_unless(
            (int)$record->business_user_id === (int)$request->user()->id &&
            (string)$record->service_quote_id === (string)$data['quoteId'] &&
            hash_equals($record->request_uuid,$data['requestId']) &&
            hash_equals($record->document_uuid,$data['documentId']) &&
            hash_equals($record->signer_uuid,$data['customerSignerId']) &&
            hash_equals((string)$record->business_signer_uuid,$data['businessSignerId']) &&
            hash_equals($record->approved_revision.':'.$record->approved_pdf_sha256,
                $data['documentVersion']) &&
            hash_equals($record->consent_reference,$data['consentReference']),
            403,'The signer or approved document does not match.');
        $signers=[
            ['role'=>'Customer','name'=>$record->signer_name,'method'=>'draw',
                'strokes'=>$this->strokes($data['customerStrokes'])],
            ['role'=>'Business','name'=>(string)$record->business_signer_name,'method'=>'draw',
                'strokes'=>$this->strokes($data['businessStrokes'])],
        ];
        $artifact=$this->completion->complete($record->id,'in_person',$signers,
            $request,$request->user()->id);
        $delivered=$this->completion->emailFinalPdf($artifact);
        return response()->json(['data'=>$this->completion->receipt($artifact)+[
            'emailDelivered'=>$delivered,
        ]],201,['Cache-Control'=>'private, no-store']);
    }
}
