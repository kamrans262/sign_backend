<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\SignatureRequest as Signing;
use App\Services\SignatureWorkflowService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    public function store(Request $request, int $quote): JsonResponse
    {
        $data = $request->validate([
            'signer_name' => ['required','string','max:255'],
            'signer_email' => ['required','email','max:255'],
            'expires_in_days' => ['nullable','integer','between:1,7'],
            'delivery' => ['required', Rule::in(['link','email'])],
        ]);
        if ($data['delivery'] === 'email' &&
            in_array((string) config('mail.default'), ['log','array','null'], true)) {
            return response()->json(['message'=>'Email delivery is not configured.'], 503);
        }
        $company = $this->company($request);
        $token = $this->workflow::freshToken();
        $item = DB::transaction(function () use ($company,$quote,$request,$data,$token) {
            $source = ServiceQuote::query()->where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($quote);
            $this->workflow->approvedBytes($source);
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
                'request_uuid' => (string) Str::uuid(),
                'document_uuid' => (string) Str::uuid(),
                'signer_uuid' => (string) Str::uuid(),
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
            $this->workflow->event($item, 'signature_requested', $request, $request->user()->id);
            AuditLogger::record('mobile.signature.requested', $request->user(),
                $item, $company, metadata: [
                    'quote_id' => $source->id,
                    'approved_revision' => $source->revision,
                ], request: $request);
            return $item;
        });

        $link = $this->workflow->publicUrl($token);
        if ($data['delivery'] === 'email') {
            try {
                Mail::raw("Please review and sign your approved Sign2Digital quote.\n\n".
                    "Secure link (expires {$item->expires_at->toIso8601String()}):\n".$link,
                    fn ($mail) => $mail->to($item->signer_email)
                        ->subject('Sign2Digital — Signature requested'));
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
