<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\M23StripeBillingService;
use App\Services\M23StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class M23StripeBillingController extends Controller
{
    public function __construct(
        private readonly M23StripeBillingService $billing,
        private readonly M23StripeGateway $stripe
    ) {}

    private function company(Request $request): Company
    {
        $c=$request->attributes->get('company');
        abort_unless($c instanceof Company,403,'Company access required.');
        return $c;
    }

    private function json(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data=$request->validate([
            'plan_id'=>['required','integer','min:1'],
            'request_id'=>['required','uuid'],
        ]);
        $company=$this->company($request);
        return $this->json($this->billing->checkout($company,$request->user(),
            (int)$data['plan_id'],(string)$data['request_id']));
    }

    public function checkoutStatus(Request $request,string $requestId): JsonResponse
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}$/i',$requestId),404);
        return $this->json($this->billing->checkoutStatus(
            $this->company($request),$requestId));
    }

    public function portal(Request $request): JsonResponse
    {
        return $this->json($this->billing->portal(
            $this->company($request),$request->user()));
    }

    public function cancel(Request $request): JsonResponse
    {
        $data=$request->validate(['confirm'=>['required','accepted']]);
        return $this->json($this->billing->cancel(
            $this->company($request),$request->user()));
    }

    public function resume(Request $request): JsonResponse
    {
        $request->validate(['confirm'=>['required','accepted']]);
        return $this->json($this->billing->cancel(
            $this->company($request),$request->user(),true));
    }

    public function changePreview(Request $request): JsonResponse
    {
        $data=$request->validate(['plan_id'=>['required','integer','min:1']]);
        return $this->json($this->billing->previewChange(
            $this->company($request),$request->user(),(int)$data['plan_id']));
    }

    public function change(Request $request): JsonResponse
    {
        $data=$request->validate([
            'plan_id'=>['required','integer','min:1'],
            'request_id'=>['required','uuid'],
            'confirm'=>['required','accepted'],
            'proration_behavior'=>['required','in:none'],
        ]);
        return $this->json($this->billing->changePlan(
            $this->company($request),$request->user(),
            (int)$data['plan_id'],(string)$data['request_id']));
    }

    public function webhook(Request $request): JsonResponse
    {
        // Deliberately no session/auth: Stripe signs the exact RAW payload.
        // Invalid signature/mode is rejected before any DB mutation.
        $raw=$request->getContent();
        $event=$this->stripe->verifiedWebhook($raw,$request->header('Stripe-Signature'));
        return $this->json($this->billing->webhook($event));
    }
}
