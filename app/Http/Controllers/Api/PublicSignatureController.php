<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureWorkflowService;
use App\Services\SignatureCompletionService;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PublicSignatureController extends Controller
{
    public function __construct(
        private readonly SignatureWorkflowService $workflow,
        private readonly SignatureCompletionService $completion,
    ) {}

    private function requestForToken(string $token): Signing
    {
        // 256-bit base64url capability; never accept sequential IDs as public access.
        abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 404);
        $item = Signing::query()->with('quote.items')
            ->where('token_sha256', hash('sha256', $token))->firstOrFail();
        abort_unless($item->mode === 'remote',404);
        $this->workflow->validForSigning($item);
        return $item;
    }

    private function registerView(Signing $item, Request $request): void
    {
        DB::transaction(function () use ($item, $request): void {
            $locked = Signing::query()->lockForUpdate()->findOrFail($item->id);
            $this->workflow->validForSigning($locked);
            if ($locked->viewed_at === null) {
                $locked->update(['viewed_at' => now(), 'status' => 'viewed']);
                $this->workflow->event($locked, 'document_viewed', $request);
            }
        });
    }

    private static function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public function open(Request $request, string $token): JsonResponse
    {
        $item = $this->requestForToken($token);
        $this->registerView($item, $request);
        $item->refresh();
        $quote = $item->quote;
        return response()->json([
            'quote' => [
                'id' => (string) $quote->id,
                'clientId' => $quote->client_id === null ? null : (string) $quote->client_id,
                'jobId' => $quote->service_job_id === null ? null : (string) $quote->service_job_id,
                'companyId' => (string) $quote->company_id,
                'clientName' => $quote->client_name,
                'clientEmail' => $quote->client_email ?? '',
                'clientPhone' => $quote->client_phone ?? '',
                'billingAddress' => $quote->billing_address ?? '',
                'quoteNumber' => $quote->custom_quote_number ?: $quote->reference_number,
                'date' => $quote->quote_date?->toIso8601String() ?? $quote->created_at->toIso8601String(),
                'terms' => $quote->terms ?? '',
                'currency' => $quote->currency,
                'vatBasisPoints' => $quote->vat_basis_points,
                'depositBasisPoints' => $quote->deposit_basis_points,
                'items' => $quote->items->map(fn ($line) => [
                    'id' => (string) $line->id,
                    'title' => $line->title,
                    'description' => $line->description ?? '',
                    'quantity' => (string) $line->quantity,
                    'unitPrice' => self::money((int) $line->unit_price_minor),
                    'dimensions' => $line->dimensions,
                ])->values()->all(),
            ],
            'request' => [
                'requestId' => $item->request_uuid,
                'secureUrl' => $this->workflow->publicUrl($token),
                'expiresAt' => $item->expires_at->toIso8601String(),
                'events' => $this->workflow->eventsForMobile($item),
            ],
            'signer' => [
                'documentId' => $item->document_uuid,
                'signerId' => $item->signer_uuid,
                'documentVersion' => $item->approved_revision.':'.$item->approved_pdf_sha256,
                'consentReference' => $item->consent_reference,
            ],
        ],200,[
            'Cache-Control'=>'private, no-store, max-age=0',
            'Pragma'=>'no-cache','X-Robots-Tag'=>'noindex, nofollow',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }

    public function browser(Request $request, string $token)
    {
        $item = $this->requestForToken($token);
        $this->registerView($item, $request);
        // Reuse the exact M6/M7 mobile-like approved customer quote styling.
        // Until the trusted signed-PDF compositor is integrated, never present
        // this read-only view as a completed electronic-signing workflow.
        return response()->view('public.quote', [
            'quote' => $item->quote->loadMissing('company'),
            'signing' => $item,
            'signingToken' => $token,
        ], 200, [
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'Referrer-Policy'=>'no-referrer',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function approvedPdf(Request $request, string $token)
    {
        $item = $this->requestForToken($token);
        $bytes = $this->workflow->approvedBytes($item->quote);
        return response($bytes, 200, [
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="Sign2Digital_Approved_Quote.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }
    private function signedRequest(string $token): Signing
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 404);
        $record=Signing::query()->with('quote.company')
            ->where('token_sha256', hash('sha256',$token))->firstOrFail();
        abort_unless($record->mode === 'remote' &&
            $record->status === 'signed' && $record->revoked_at === null &&
            $record->expires_at->isFuture() &&
            $record->quote?->company?->status === 'active' &&
            $record->quote->status !== 'archived' &&
            (int)$record->quote->link_version === (int)$record->quote_link_version, 410);
        abort_unless($record->artifact()->exists(), 503);
        return $record;
    }

    public function submit(Request $request, string $token)
    {
        $record=$this->requestForToken($token);
        abort_unless($record->mode === 'remote', 404);
        // Avoid flashing sensitive signature image data into web sessions.
        $validator=Validator::make($request->all(),[
            'quoteId'=>['required','string','max:32'],
            'documentId'=>['required','uuid'],
            'requestId'=>['required','uuid'],
            'signerId'=>['required','uuid'],
            'documentVersion'=>['required','string','max:100'],
            'consentReference'=>['required','string','size:64'],
            'method'=>['required','in:draw,type,upload'],
            'typedName'=>['nullable','string','max:255'],
            'signaturePngBase64'=>['required','string','max:410000'],
            'consentAccepted'=>['required','accepted'],
            'capturedAt'=>['nullable','date'],
        ]);
        abort_if($validator->fails(),422,'Invalid or incomplete signature submission.');
        $fields=$validator->validated();
        abort_unless(
            hash_equals((string)$record->request_uuid,(string)$fields['requestId']) &&
            hash_equals((string)$record->document_uuid,(string)$fields['documentId']) &&
            hash_equals((string)$record->signer_uuid,(string)$fields['signerId']) &&
            hash_equals((string)$record->approved_revision.':'.$record->approved_pdf_sha256,
                (string)$fields['documentVersion']) &&
            hash_equals((string)$record->consent_reference,(string)$fields['consentReference']) &&
            (string)$record->service_quote_id === (string)$fields['quoteId'],
            403,'The signature identifiers do not match the approved document.');
        if ($fields['method'] === 'type' &&
            mb_strtolower(trim((string)($fields['typedName']??''))) !==
            mb_strtolower(trim((string)$record->signer_name))) {
            return response()->json(['message'=>'Typed signature must match the approved signer name.'],422);
        }
        $signature=base64_decode($fields['signaturePngBase64'],true);
        abort_unless(is_string($signature),422,'Invalid signature image encoding.');
        try {
            \App\Services\SignedPdfService::validatePng($signature);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['message'=>$error->getMessage()],422);
        }
        $artifact=$this->completion->complete($record->id,'remote',[
            [
                'role'=>'Customer',
                'name'=>$record->signer_name,
                'method'=>$fields['method'],
                'png'=>$signature,
            ],
        ],$request);
        $delivered=$this->completion->emailFinalPdf($artifact);
        if (!$request->expectsJson()) {
            return Redirect::route('signing.public.receipt.browser',['token'=>$token],303);
        }
        return response()->json($this->completion->receipt($artifact)+[
            'emailDelivered'=>$delivered,
        ],201,[
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }

    public function receipt(Request $request, string $token)
    {
        $record=$this->signedRequest($token);
        $receipt=$this->completion->receipt($record->artifact,false);
        $headers=[
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'Referrer-Policy'=>'no-referrer',
            'X-Content-Type-Options'=>'nosniff',
        ];
        if ($request->expectsJson()) {
            return response()->json(['data'=>$receipt],200,$headers);
        }
        return response()->view('public.signing-complete',[
            'receipt'=>$receipt,
            'token'=>$token,
        ],200,$headers);
    }

    public function signedPdf(Request $request, string $token)
    {
        $record=$this->signedRequest($token);
        $bytes=$this->completion->bytes($record->artifact);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_Quote.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }

}
