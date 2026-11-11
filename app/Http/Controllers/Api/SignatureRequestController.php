<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureWorkflowService;
use App\Services\SignedPdfService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SignatureRequestController extends Controller
{
    public function __construct(private readonly SignatureWorkflowService $workflow) {}

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('company');
        abort_unless($company instanceof Company, 403);
        return $company;
    }

    private function scoped(Request $request, int $id): Signing
    {
        return Signing::query()->where('company_id', $this->company($request)->id)
            ->with('quote:id,company_id,custom_quote_number,reference_number,service_job_id')
            ->findOrFail($id);
    }

    private function result(Signing $item): array
    {
        return [
            'id' => $item->id,
            'request_id' => $item->request_uuid,
            'document_id' => $item->document_uuid,
            'quote_id' => $item->service_quote_id,
            'job_id' => $item->quote?->service_job_id,
            'signer_name' => $item->signer_name,
            'mode' => $item->mode,
            'business_signer_name' => $item->business_signer_name,
            'business_signed_at' => $item->business_signed_at?->toIso8601String(),
            'signer_email' => $item->signer_email,
            'status' => $item->expires_at->isPast() && in_array($item->status, ['ready','sent','viewed'], true)
                ? 'expired' : $item->status,
            'approved_revision' => $item->approved_revision,
            'approved_pdf_sha256' => $item->approved_pdf_sha256,
            'expires_at' => $item->expires_at->toIso8601String(),
            'sent_at' => $item->sent_at?->toIso8601String(),
            'viewed_at' => $item->viewed_at?->toIso8601String(),
            'signed_at' => $item->signed_at?->toIso8601String(),
            'revoked_at' => $item->revoked_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request, int $quote): JsonResponse
    {
        ServiceQuote::query()->where('company_id', $this->company($request)->id)
            ->findOrFail($quote);
        $records = Signing::query()->where('company_id', $this->company($request)->id)
            ->where('service_quote_id', $quote)->with('quote:id,service_job_id')
            ->latest('id')->paginate(20);
        return response()->json(['data' => [
            'requests' => $records->getCollection()->map(fn (Signing $item) => $this->result($item)),
            'total' => $records->total(),
        ]]);
    }

    public function show(Request $request, int $signature): JsonResponse
    {
        $item = $this->scoped($request, $signature);
        return response()->json(['data' => [
            'request' => $this->result($item),
            'events' => $this->workflow->eventsForMobile($item),
        ]]);
    }

    /** Server-issued, short-lived issuer authorization bound to an immutable approved PDF. */
    public function businessContext(Request $request, int $quote): JsonResponse
    {
        $company = $this->company($request);
        $source = ServiceQuote::query()->where('company_id', $company->id)->findOrFail($quote);
        $this->workflow->approvedBytes($source);
        $claims = [
            'company_id' => $company->id, 'quote_id' => $source->id,
            'user_id' => $request->user()->id, 'revision' => $source->revision,
            'pdf_sha256' => $source->approved_pdf_sha256,
            'issued_at' => now()->timestamp,
            'document_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'signer_id' => (string) Str::uuid(),
        ];
        $authorization = Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR));
        return response()->json(['data' => ['context' => [
            'quote_id' => (string) $source->id,
            'document_id' => $claims['document_id'],
            'request_id' => $claims['request_id'],
            'signer_id' => $claims['signer_id'],
            'document_version' => $source->revision.':'.$source->approved_pdf_sha256,
            'consent_reference' => hash('sha256',
                ($source->terms ?? '')."\n".$source->approved_pdf_sha256),
            'authorization' => $authorization,
        ]]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, int $quote): JsonResponse
    {
        $data = $request->validate([
            'signer_name' => ['required','string','max:255'],
            'signer_email' => ['required','email','max:255'],
            'expires_in_days' => ['nullable','integer','between:1,7'],
            'delivery' => ['required', Rule::in(['link','email'])],
            'message' => ['nullable','string','max:2000'],
            'attach_pdf' => ['nullable','boolean'],
            'business_authorization' => ['nullable','string','max:4096'],
            'business_signature_png_base64' => ['required_with:business_authorization','string','max:410000'],
            'business_signature_method' => ['required_with:business_authorization', Rule::in(['draw','type','upload'])],
            'business_signature_typed_name' => ['nullable','string','max:255'],
            'business_consent_accepted' => ['required_with:business_authorization','accepted'],
        ]);
        if ($data['delivery'] === 'email' &&
            in_array((string) config('mail.default'), ['log','array','null'], true)) {
            return response()->json(['message'=>'Email delivery is not configured.'], 503);
        }
        $company = $this->company($request);
        $token = $this->workflow::freshToken();
        $business = null;
        if (!empty($data['business_authorization'])) {
            try {
                $claims = json_decode(Crypt::decryptString($data['business_authorization']), true, 512, JSON_THROW_ON_ERROR);
                $png = base64_decode((string) $data['business_signature_png_base64'], true);
                if (!is_array($claims) || !is_string($png)) {
                    abort(422, 'Issuer authorization or signature is invalid.');
                }
                SignedPdfService::validatePng($png);
            } catch (\Throwable $error) {
                abort(422, 'Issuer authorization or signature is invalid.');
            }
            if ($data['business_signature_method'] === 'type' &&
                mb_strtolower(trim((string) ($data['business_signature_typed_name'] ?? ''))) !==
                mb_strtolower(trim((string) $request->user()->name))) {
                abort(422, 'Typed issuer signature must match the authorized user.');
            }
            $business = ['claims' => $claims, 'png' => $png,
                'method' => $data['business_signature_method']];
        }
        $businessPath = null;
        try {
            $item = DB::transaction(function () use ($company,$quote,$request,$data,$token,$business,&$businessPath) {
            $source = ServiceQuote::query()->where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($quote);
            $this->workflow->approvedBytes($source);
            if ($business !== null) {
                $claims = $business['claims'];
                abort_unless(
                    (int) ($claims['company_id'] ?? 0) === (int) $company->id &&
                    (int) ($claims['quote_id'] ?? 0) === (int) $source->id &&
                    (int) ($claims['user_id'] ?? 0) === (int) $request->user()->id &&
                    (int) ($claims['revision'] ?? 0) === (int) $source->revision &&
                    hash_equals((string) $source->approved_pdf_sha256, (string) ($claims['pdf_sha256'] ?? '')) &&
                    (int) ($claims['issued_at'] ?? 0) <= now()->timestamp &&
                    (int) ($claims['issued_at'] ?? 0) >= now()->subMinutes(10)->timestamp &&
                    (bool) preg_match('/^[0-9a-f-]{36}$/D', (string) ($claims['document_id'] ?? '')) &&
                    (bool) preg_match('/^[0-9a-f-]{36}$/D', (string) ($claims['request_id'] ?? '')) &&
                    (bool) preg_match('/^[0-9a-f-]{36}$/D', (string) ($claims['signer_id'] ?? '')),
                    422, 'Issuer signing context expired or does not match the approved quotation.'
                );
            }
            if (trim((string) $source->client_email) === '' ||
                mb_strtolower(trim((string) $source->client_email)) !==
                    mb_strtolower(trim($data['signer_email']))) {
                abort(422, 'Signer email must match the approved customer record.');
            }
            abort_if(Signing::query()->where('service_quote_id',$source->id)
                ->where('status','signed')->exists(),409,
                'This approved quote has already been signed.');
            $active = Signing::query()->where('service_quote_id', $source->id)
                ->whereIn('status', ['ready','sent','viewed'])
                ->where('expires_at','>',now())->exists();
            abort_if($active, 409, 'An active signing request already exists for this quote.');
            $item = Signing::create([
                'company_id' => $company->id,
                'service_quote_id' => $source->id,
                'created_by' => $request->user()->id,
                'mode' => 'remote',
                'request_uuid' => $business === null ? (string) Str::uuid() : $business['claims']['request_id'],
                'document_uuid' => $business === null ? (string) Str::uuid() : $business['claims']['document_id'],
                'signer_uuid' => (string) Str::uuid(),
                'business_signer_uuid' => $business === null ? null : $business['claims']['signer_id'],
                'business_user_id' => $business === null ? null : $request->user()->id,
                'business_signer_name' => $business === null ? null : $request->user()->name,
                'business_signature_method' => $business['method'] ?? null,
                'business_signed_at' => $business === null ? null : now(),
                'token_sha256' => hash('sha256', $token),
                'signer_name' => trim($data['signer_name']),
                'signer_email' => trim($data['signer_email']),
                'approved_revision' => $source->revision,
                'approved_pdf_sha256' => $source->approved_pdf_sha256,
                'consent_reference' => hash('sha256',
                    ($source->terms ?? '')."\n".$source->approved_pdf_sha256),
                'quote_link_version' => $source->link_version,
                'status' => 'ready',
                'expires_at' => now()->addDays($data['expires_in_days'] ?? 7),
            ]);
            if ($business !== null) {
                $digest = hash('sha256', $business['png']);
                $businessPath = 'signatures/business/'.$company->id.'/'.$source->id.'/'.$item->id.'/'.$digest.'.png';
                if (!Storage::disk('local')->put($businessPath, $business['png'])) {
                    abort(503, 'Unable to securely store the issuer signature.');
                }
                $item->update([
                    'business_signature_path' => $businessPath,
                    'business_signature_sha256' => $digest,
                ]);
                $this->workflow->event($item, 'business_signed', $request, $request->user()->id,
                    ['evidence_sha256' => $digest]);
            }
            $this->workflow->event($item, 'signature_requested', $request, $request->user()->id);
            AuditLogger::record('mobile.signature.requested', $request->user(),
                $item, $company, metadata: [
                    'quote_id' => $source->id,
                    'approved_revision' => $source->revision,
                ], request: $request);
            return $item;
            });
        } catch (\Throwable $error) {
            if ($businessPath !== null) Storage::disk('local')->delete($businessPath);
            throw $error;
        }

        $link = $this->workflow->publicUrl($token);
        if ($data['delivery'] === 'email') {
            try {
                $message = trim((string) ($data['message'] ?? ''));
                if ($message === '') $message = 'Please review and sign your approved Sign2Digital quote.';
                $attach = $data['attach_pdf'] ?? false;
                $pdf = $attach ? $this->workflow->approvedBytes($item->quote) : null;
                Mail::raw($message."\n\n".
                    "Secure signing link (expires {$item->expires_at->toIso8601String()}):\n".$link,
                    function ($mail) use ($item, $pdf): void {
                        $mail->to($item->signer_email)->subject('Sign2Digital — Signature requested');
                        if ($pdf !== null) $mail->attachData($pdf, 'Sign2Digital_Approved_Quote.pdf',
                            ['mime'=>'application/pdf']);
                    });
            } catch (\Throwable $error) {
                report($error);
                // Remains 'ready': never claim email delivery or expose the token.
                return response()->json(['message' =>
                    'Request created, but email was not delivered. Use resend from the company account.',
                    'data' => ['request' => $this->result($item)]], 502);
            }
            $item->update(['status'=>'sent','sent_at'=>now()]);
            $this->workflow->event($item,'document_sent',$request,$request->user()->id,
                ['channel'=>'email']);
            $link = null; // Email is sent; do not echo a bearer link needlessly.
        }
        $item->load('quote:id,service_job_id');
        return response()->json(['data' => [
            'request' => $this->result($item),
            'secure_link' => $link,
            'delivered' => $data['delivery'] === 'email',
        ]], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function revoke(Request $request, int $signature): JsonResponse
    {
        $company = $this->company($request);
        $item = DB::transaction(function () use ($request,$signature,$company) {
            $item = Signing::query()->where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($signature);
            abort_unless(in_array($item->status,['ready','sent','viewed'],true),409);
            $item->update(['status'=>'revoked','revoked_at'=>now()]);
            $this->workflow->event($item,'request_revoked',$request,$request->user()->id);
            AuditLogger::record('mobile.signature.revoked',$request->user(),$item,$company,request:$request);
            return $item;
        });
        return response()->json(['data'=>['request'=>$this->result($item)]]);
    }

    public function rotateLink(Request $request, int $signature): JsonResponse
    {
        $company=$this->company($request);
        $token=$this->workflow::freshToken();
        $item=DB::transaction(function () use ($request,$signature,$company,$token) {
            $item=Signing::query()->where('company_id',$company->id)
                ->lockForUpdate()->with('quote')->findOrFail($signature);
            abort_unless($item->mode==='remote',409);
            $this->workflow->validForSigning($item);
            $item->update(['token_sha256'=>hash('sha256',$token)]);
            $this->workflow->event($item,'request_link_rotated',$request,
                $request->user()->id,['channel'=>'copy_link']);
            AuditLogger::record('mobile.signature.link_rotated',$request->user(),
                $item,$company,request:$request);
            return $item;
        });
        return response()->json(['data'=>[
            'request'=>$this->result($item),
            'secure_link'=>$this->workflow->publicUrl($token),
            'delivered'=>false,
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function resend(Request $request, int $signature): JsonResponse
    {
        if (in_array((string) config('mail.default'), ['log','array','null'], true)) {
            return response()->json(['message'=>'Email delivery is not configured.'],503);
        }
        $company = $this->company($request);
        $token = $this->workflow::freshToken();
        $item = DB::transaction(function () use ($company,$signature,$request,$token) {
            $item = Signing::query()->where('company_id',$company->id)
                ->lockForUpdate()->with('quote')->findOrFail($signature);
            $this->workflow->validForSigning($item);
            abort_unless($item->mode === 'remote',409);
            $item->update(['token_sha256'=>hash('sha256',$token)]);
            $this->workflow->event($item,'request_link_rotated',$request,$request->user()->id);
            return $item;
        });
        try {
            $link = $this->workflow->publicUrl($token);
            Mail::raw("Please review and sign your Sign2Digital quote.\n\n".
                "Updated secure link (expires {$item->expires_at->toIso8601String()}):\n".$link,
                fn ($mail) => $mail->to($item->signer_email)
                    ->subject('Sign2Digital — Signature reminder'));
        } catch (\Throwable $error) {
            report($error);
            return response()->json(['message'=>'The reminder could not be sent.'],502);
        }
        $item->update(['status'=>'sent','sent_at'=>now()]);
        $this->workflow->event($item,'reminder_sent',$request,$request->user()->id,
            ['channel'=>'email']);
        return response()->json(['data'=>['request'=>$this->result($item),'delivered'=>true]]);
    }
}
