<?php

namespace App\Services;

use App\Models\SignatureRequest as Signing;
use App\Models\SignedDocumentArtifact;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SignatureCompletionService
{
    public function __construct(
        private readonly SignatureWorkflowService $workflow,
        private readonly SignedPdfService $pdf,
    ) {}

    /**
     * Caller has checked access, authenticated the correct signing capability,
     * and validated consent. Still re-check everything while holding row lock.
     *
     * @param array<int,array{role:string,name:string,method:string,png?:string,strokes?:array}> $signers
     */
    public function complete(int $requestId, string $mode, array $signers,
        Request $request, ?int $businessUserId = null): SignedDocumentArtifact
    {
        return DB::transaction(function () use ($requestId,$mode,$signers,$request,$businessUserId) {
            $signing=Signing::query()->with('quote.company')
                ->lockForUpdate()->findOrFail($requestId);
            if ($signing->mode !== $mode || $signing->artifact()->exists()) {
                throw new HttpException(409,'This request is not active or has already been signed.');
            }
            $quote=$this->workflow->validForSigning($signing);
            if ($quote->company?->status !== 'active') {
                throw new HttpException(403,'Company is not active.');
            }
            if ($mode === 'in_person' && (
                $businessUserId === null ||
                (int)$signing->business_user_id !== $businessUserId ||
                count($signers) !== 2
            )) {
                throw new HttpException(403,'Business signer authorization failed.');
            }
            if ($mode === 'remote' && count($signers) !== 1) {
                throw new HttpException(422,'Exactly one authorized customer signature is required.');
            }
            // Remote two-party signing uses a verified issuer signature captured
            // before delivery. Customer completion supplies the second signature.
            if ($mode === 'remote' && $signing->business_signature_path !== null) {
                $path = $signing->business_signature_path;
                $digest = (string) $signing->business_signature_sha256;
                $bytes = Storage::disk('local')->get($path);
                if (!is_string($bytes) || !preg_match('/^[a-f0-9]{64}$/D', $digest) ||
                    !hash_equals($digest, hash('sha256', $bytes)) ||
                    $signing->business_user_id === null || !$signing->business_signed_at) {
                    throw new HttpException(503, 'Verified issuer signature is unavailable.');
                }
                try {
                    SignedPdfService::validatePng($bytes);
                } catch (\\InvalidArgumentException $error) {
                    throw new HttpException(503, 'Verified issuer signature is invalid.');
                }
                array_unshift($signers, [
                    'role' => 'Business', 'name' => $signing->business_signer_name,
                    'method' => $signing->business_signature_method, 'png' => $bytes,
                ]);
            }

            $source=$this->workflow->approvedBytes($quote);
            $signedAt=now()->utc();
            $quoteNumber=$quote->custom_quote_number ?: $quote->reference_number;
            try {
                $bytes=$this->pdf->build(
                    $source,
                    $quoteNumber,
                    $signing->approved_pdf_sha256,
                    $signing->document_uuid,
                    $signing->request_uuid,
                    $signedAt,
                    $signers,
                    $signing->consent_reference,
                );
            } catch (\InvalidArgumentException $error) {
                throw new HttpException(422,'The supplied signature cannot be embedded.');
            } catch (\Throwable $error) {
                report($error);
                throw new HttpException(503,'The signed PDF could not be generated or verified.');
            }
            $sha=hash('sha256',$bytes);
            $path='signatures/signed/'.$signing->company_id.'/'.$signing->service_quote_id.
                '/'.$signing->id.'/'.$sha.'.pdf';
            $disk=Storage::disk('local');
            if ($disk->exists($path) || !$disk->put($path,$bytes)) {
                throw new HttpException(503,'Unable to store the signed document privately.');
            }
            $readBack=$disk->get($path);
            if (!is_string($readBack) || !hash_equals($sha,hash('sha256',$readBack))) {
                $disk->delete($path);
                throw new HttpException(503,'Signed PDF storage integrity failed.');
            }

            $evidence=[
                'request_id'=>$signing->request_uuid,
                'document_id'=>$signing->document_uuid,
                'company_id'=>$signing->company_id,
                'quote_id'=>$signing->service_quote_id,
                'source_revision'=>$signing->approved_revision,
                'source_sha256'=>$signing->approved_pdf_sha256,
                'signed_at_utc'=>$signedAt->toIso8601String(),
                'consent_reference'=>$signing->consent_reference,
                'consent_accepted'=>true,
                'mode'=>$mode,
                'ip_address'=>$request->ip(),
                'user_agent'=>mb_substr((string)$request->userAgent(),0,500),
                'signers'=>array_map(static fn(array $signer): array => [
                    'role'=>$signer['role'],
                    'name'=>$signer['name'],
                    'method'=>$signer['method'],
                    'evidence_sha256'=>isset($signer['png'])
                        ? hash('sha256',$signer['png'])
                        : hash('sha256',json_encode($signer['strokes'],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ],$signers),
                'business_user_id'=>$businessUserId ?? $signing->business_user_id,
                'business_signed_at_utc'=>$signing->business_signed_at?->toIso8601String(),
            ];
            $artifact=SignedDocumentArtifact::create([
                'signature_request_id'=>$signing->id,
                'company_id'=>$signing->company_id,
                'service_quote_id'=>$quote->id,
                'source_pdf_sha256'=>$signing->approved_pdf_sha256,
                'source_revision'=>$signing->approved_revision,
                'signed_pdf_path'=>$path,
                'signed_pdf_sha256'=>$sha,
                'evidence'=>$evidence,
                'created_at'=>$signedAt,
            ]);
            $signing->update(['status'=>'signed','signed_at'=>$signedAt]);
            $this->workflow->event($signing,'document_signed',$request,
                $mode === 'in_person' ? $businessUserId : null,
                ['signed_pdf_sha256'=>$sha,'mode'=>$mode]);
            $this->workflow->event($signing,'document_completed',$request,$businessUserId);
            AuditLogger::record('signature.document.completed',$request->user(),
                $signing,$quote->company,metadata:[
                    'quote_id'=>$quote->id,'mode'=>$mode,
                    'approved_sha256'=>$signing->approved_pdf_sha256,
                    'signed_sha256'=>$sha,
                ],request:$request);
            return $artifact;
        }, 3);
    }

    public function bytes(SignedDocumentArtifact $artifact): string
    {
        $disk=Storage::disk('local');
        if (!$disk->exists($artifact->signed_pdf_path)) {
            throw new HttpException(503,'The signed document is unavailable.');
        }
        $bytes=$disk->get($artifact->signed_pdf_path);
        if (!is_string($bytes) ||
            !str_starts_with($bytes,'%PDF-') ||
            !str_contains(substr($bytes,-4096),'%%EOF') ||
            !hash_equals($artifact->signed_pdf_sha256,hash('sha256',$bytes))) {
            throw new HttpException(503,'Signed PDF integrity validation failed.');
        }
        return $bytes;
    }

    public function receipt(SignedDocumentArtifact $artifact, bool $includePdf = true): array
    {
        $artifact->loadMissing('request.quote.job');
        $request=$artifact->request;
        $quote=$request->quote;
        $number=$quote->custom_quote_number ?: $quote->reference_number;
        $evidence=$artifact->evidence ?? [];
        $signedTime=$request->signed_at ?? $artifact->created_at;
        $result=[
            'documentId'=>$request->document_uuid,
            'requestId'=>$request->request_uuid,
            'quoteId'=>(string)$quote->id,
            'jobId'=>$quote->service_job_id === null ? null : (string)$quote->service_job_id,
            'quoteNumber'=>$number,
            'projectName'=>$quote->job?->title ?: 'Quote '.$number,
            'signerName'=>$request->signer_name,
            'signedAt'=>$signedTime->toIso8601String(),
            'ipAddress'=>$evidence['ip_address'] ?? null,
            'signedPdfSha256'=>$artifact->signed_pdf_sha256,
            'sourcePdfSha256'=>$artifact->source_pdf_sha256,
            'mode'=>$evidence['mode'] ?? $request->mode,
        ];
        if ($includePdf) $result['signedPdfBase64']=base64_encode($this->bytes($artifact));
        return $result;
    }

    /**
     * Completion must not be rolled back when SMTP is unavailable. Delivery
     * succeeds only after the real mail transport accepted the email.
     */
    public function emailFinalPdf(SignedDocumentArtifact $artifact): bool
    {
        $artifact->loadMissing('request.quote.company.owner');
        $signing=$artifact->request;
        $quote=$signing->quote;
        if (in_array((string) config('mail.default'), ['log','array','null',''], true)) {
            return false;
        }
        $recipients=[
            'customer'=>trim((string)$signing->signer_email),
            'business_owner'=>trim((string)($quote->company?->owner?->email??'')),
        ];
        foreach($recipients as $address){
            if(!filter_var($address,FILTER_VALIDATE_EMAIL)){
                $this->workflow->event($signing,'signed_pdf_delivery_failed',null,null,
                    ['reason'=>'recipient_not_configured']);
                return false;
            }
        }
        $bytes=$this->bytes($artifact);
        $number=$quote->custom_quote_number ?: $quote->reference_number;
        $sent=[];
        $complete=true;
        foreach($recipients as $role=>$address){
            $normalized=mb_strtolower($address);
            if(isset($sent[$normalized]))continue;
            try {
                Mail::raw(
                    "Your signed Sign2Digital quote ".$number.
                    " is attached. Keep this immutable PDF for your records.",
                    fn($message)=>$message->to($address)
                        ->subject('Sign2Digital — Signed quote '.$number)
                        ->attachData($bytes,'Sign2Digital_Signed_Quote.pdf',
                            ['mime'=>'application/pdf'])
                );
                $sent[$normalized]=true;
                $this->workflow->event($signing,'signed_pdf_emailed',null,null,
                    ['recipient_role'=>$role,'channel'=>'email']);
            } catch(\Throwable $error){
                report($error);
                $complete=false;
                $this->workflow->event($signing,'signed_pdf_delivery_failed',null,null,
                    ['recipient_role'=>$role]);
            }
        }
        return $complete;
    }
}
