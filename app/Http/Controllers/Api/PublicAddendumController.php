<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Addendum;
use App\Models\AddendumSigningRequest;
use App\Services\AddendumService;
use App\Services\SignedPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One-use customer capability exclusively for a frozen addendum, not a quote. */
final class PublicAddendumController extends Controller
{
    public function __construct(
        private readonly AddendumService $service,
        private readonly SignedPdfService $pdf,
    ) {}

    private function findToken(string $token): AddendumSigningRequest
    {
        abort_unless((bool)preg_match('/^[A-Za-z0-9_-]{43}$/D',$token),404);
        return AddendumSigningRequest::query()->with('addendum.quote.company')
            ->where('token_sha256',hash('sha256',$token))->firstOrFail();
    }

    private function active(AddendumSigningRequest $signing): Addendum
    {
        abort_unless(in_array($signing->status,['ready','sent','viewed'],true) &&
            $signing->revoked_at === null && $signing->expires_at->isFuture(),410,
            'The addendum signing link expired or was revoked.');
        $item=$signing->addendum;
        abort_unless($item && $item->quote && $item->quote->company
            && $item->quote->company->status === 'active'
            && (int)$item->company_id === (int)$signing->company_id
            && in_array($item->status,['approved','sent'],true)
            && (int)$item->revision === (int)$signing->approved_revision
            && is_string($item->approved_pdf_sha256)
            && hash_equals($item->approved_pdf_sha256,$signing->approved_pdf_sha256)
            && hash_equals(hash('sha256',
                $item->source_pdf_sha256."\n".$item->approved_pdf_sha256),
                $signing->consent_reference),410,
            'The approved addendum no longer matches this signing request.');
        $this->service->approvedBytes($item);
        return $item;
    }

    private function viewEvent(AddendumSigningRequest $signing,Request $request): void
    {
        DB::transaction(function () use ($signing,$request): void {
            $locked=AddendumSigningRequest::query()->lockForUpdate()->findOrFail($signing->id);
            $item=$this->active($locked->load('addendum.quote.company'));
            if ($locked->viewed_at === null) {
                $locked->update(['viewed_at'=>now(),'status'=>'viewed']);
                $this->service->event($item,'document_viewed',null,
                    ['signing_request_id'=>$locked->id]);
            }
        });
    }

    private static function headers(): array
    {
        return [
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'X-Robots-Tag'=>'noindex, nofollow',
            'Referrer-Policy'=>'no-referrer',
        ];
    }

    public function open(Request $request,string $token)
    {
        $signing=$this->findToken($token);
        $addendum=$this->active($signing);
        $this->viewEvent($signing,$request);
        $signing->refresh();
        if ($request->expectsJson()) {
            return response()->json([
                'data'=>[
                    'addendum'=>$this->service->payload($addendum),
                    'signer'=>[
                        'requestId'=>$signing->request_uuid,
                        'documentId'=>$signing->document_uuid,
                        'signerId'=>$signing->signer_uuid,
                        'documentVersion'=>$signing->approved_revision.':'.$signing->approved_pdf_sha256,
                        'consentReference'=>$signing->consent_reference,
                    ],
                    'expires_at'=>$signing->expires_at->toIso8601String(),
                    'approved_pdf_url'=>route('addenda.public.pdf',['token'=>$token]),
                ],
            ],200,self::headers());
        }
        return response()->view('public.addendum',[
            'addendum'=>$addendum,'signing'=>$signing,'signingToken'=>$token,
        ],200,self::headers());
    }

    public function approvedPdf(Request $request,string $token)
    {
        $signing=$this->findToken($token);
        $item=$this->active($signing);
        return response($this->service->approvedBytes($item),200,array_merge(
            self::headers(),[
                'Content-Type'=>'application/pdf',
                'Content-Disposition'=>'inline; filename="Sign2Digital_Approved_Addendum.pdf"',
            ]));
    }

    public function submit(Request $request,string $token)
    {
        $signing=$this->findToken($token);
        $this->active($signing);
        $validator=Validator::make($request->all(),[
            'addendumId'=>['required','integer','min:1'],
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
        abort_if($validator->fails(),422,'Invalid or incomplete addendum signature.');
        $fields=$validator->validated();
        abort_unless((int)$fields['addendumId']===(int)$signing->addendum_id &&
            hash_equals($signing->request_uuid,(string)$fields['requestId']) &&
            hash_equals($signing->document_uuid,(string)$fields['documentId']) &&
            hash_equals($signing->signer_uuid,(string)$fields['signerId']) &&
            hash_equals($signing->approved_revision.':'.$signing->approved_pdf_sha256,
                (string)$fields['documentVersion']) &&
            hash_equals($signing->consent_reference,(string)$fields['consentReference']),403,
            'Signature identifiers do not match the approved addendum.');
        if ($fields['method']==='type' && mb_strtolower(trim((string)($fields['typedName']??''))) !==
            mb_strtolower(trim($signing->signer_name))) {
            abort(422,'Typed signature name must match the authorized signer.');
        }
        $png=base64_decode($fields['signaturePngBase64'],true);
        abort_unless(is_string($png),422,'Invalid signature image.');
        try {
            SignedPdfService::validatePng($png);
        } catch (\InvalidArgumentException $error) {
            abort(422,$error->getMessage());
        }

        $item=DB::transaction(function () use ($signing,$request,$fields,$png) {
            $locked=AddendumSigningRequest::query()->lockForUpdate()
                ->with('addendum.quote.company')->findOrFail($signing->id);
            $item=$this->active($locked);
            $original=$this->service->approvedBytes($item);
            $signedAt=now()->utc();
            $bytes=$this->pdf->build(
                $original,$item->number,$item->approved_pdf_sha256,
                $locked->document_uuid,$locked->request_uuid,$signedAt,
                [[
                    'role'=>'Customer',
                    'name'=>$locked->signer_name,
                    'method'=>$fields['method'],
                    'png'=>$png,
                ]],$locked->consent_reference,'Addendum');
            $sha=hash('sha256',$bytes);
            $path='addenda/signed/'.$item->company_id.'/'.$item->id.'/'.
                $locked->id.'/'.$sha.'.pdf';
            abort_unless(Storage::disk('local')->put($path,$bytes),503,
                'Could not safely store signed addendum.');
            // Validate immediately before persisting the completion.
            abort_unless(hash_equals($sha,hash('sha256',Storage::disk('local')->get($path))),
                503,'Signed addendum storage integrity failed.');
            $item->update([
                'status'=>'signed','signed_pdf_path'=>$path,
                'signed_pdf_sha256'=>$sha,'signed_at'=>$signedAt,
            ]);
            $locked->update(['status'=>'signed','signed_at'=>$signedAt]);
            // Retain concise consent evidence like M8; never store the
            // signature PNG, raw signing token or provider credentials.
            $this->service->event($item,'signed',null,[
                'request_id'=>$locked->id,
                'request_uuid'=>$locked->request_uuid,
                'mode'=>'remote','method'=>$fields['method'],
                'signer_name'=>$locked->signer_name,
                'consent_accepted'=>true,
                'consent_reference'=>$locked->consent_reference,
                'signature_evidence_sha256'=>hash('sha256',$png),
                'ip_address'=>$request->ip(),
                'user_agent'=>mb_substr((string)$request->userAgent(),0,500),
                'signed_at_utc'=>$signedAt->toIso8601String(),
                'approved_pdf_sha256'=>$locked->approved_pdf_sha256,
                'signed_pdf_sha256'=>$sha,
            ]);
            return $item;
        });
        $delivered=false;
        if (!in_array((string)config('mail.default'),['log','array','null'],true)) {
            try {
                $bytes=$this->service->signedBytes($item);
                Mail::raw('Attached is your signed Sign2Digital addendum.',
                    fn($mail)=>$mail->to($signing->signer_email)
                        ->subject('Sign2Digital - Signed addendum '.$item->number)
                        ->attachData($bytes,'Signed_Addendum.pdf',
                            ['mime'=>'application/pdf']));
                $delivered=true;
                $this->service->event($item,'signed_pdf_emailed');
            } catch (\Throwable $error) {
                report($error);
                $this->service->event($item,'signed_pdf_delivery_failed');
            }
        }
        if (!$request->expectsJson()) {
            return Redirect::route('addenda.public.receipt',['token'=>$token],303);
        }
        return response()->json(['data'=>[
            'addendum_id'=>$item->id,'status'=>'signed',
            'signed_pdf_sha256'=>$item->signed_pdf_sha256,
            'signed_at'=>$item->signed_at?->toIso8601String(),
            'email_delivered'=>$delivered,
        ]],201,self::headers());
    }

    private function signed(string $token): Addendum
    {
        $signing=$this->findToken($token);
        abort_unless($signing->status==='signed' &&
            $signing->revoked_at === null && $signing->expires_at->isFuture(),410);
        $item=$signing->addendum;
        abort_unless($item && $item->status==='signed' &&
            (int)$item->company_id === (int)$signing->company_id,410);
        $this->service->signedBytes($item);
        return $item;
    }

    public function receipt(Request $request,string $token)
    {
        $item=$this->signed($token);
        if ($request->expectsJson()) {
            return response()->json(['data'=>[
                'addendum_id'=>$item->id,'status'=>'signed',
                'reference'=>$item->number,
                'signed_at'=>$item->signed_at?->toIso8601String(),
                'signed_pdf_sha256'=>$item->signed_pdf_sha256,
            ]],200,self::headers());
        }
        return response()->view('public.addendum-receipt',['addendum'=>$item,
            'token'=>$token],200,self::headers());
    }

    public function signedPdf(Request $request,string $token)
    {
        $item=$this->signed($token);
        return response($this->service->signedBytes($item),200,array_merge(
            self::headers(),[
                'Content-Type'=>'application/pdf',
                'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_Addendum.pdf"',
            ]));
    }
}
