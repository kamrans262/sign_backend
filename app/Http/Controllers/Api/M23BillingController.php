<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\M23BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class M23BillingController extends Controller
{
    public function __construct(private readonly M23BillingService $billing) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company,403,'Company access is required.');
        return $company;
    }

    private function respond(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        return $this->respond($this->billing->overview($this->company($request),(int)$request->user()->id));
    }

    public function plans(Request $request): JsonResponse
    {
        $company=$this->company($request);
        return $this->respond([
            'company_id'=>(int)$company->id,
            'plans'=>$this->billing->plans(),
            'checkout_available'=>app(\App\Services\M23StripeGateway::class)->enabled(),
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->respond($this->billing->usage($this->company($request),(int)$request->user()->id));
    }

    public function eligibility(Request $request): JsonResponse
    {
        return $this->respond($this->billing->eligibility($this->company($request),(int)$request->user()->id));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->respond($this->billing->history($this->company($request)));
    }

    public function unavailable(Request $request): JsonResponse
    {
        $company=$this->company($request);
        // Mutating billing actions will be wired only after verified Stripe
        // customer/price identity, webhook signing and explicit authorization.
        abort_unless((int)$company->owner_user_id===(int)$request->user()->id,
            403,'Only the company owner can manage billing.');
        return $this->respond([
            'available'=>false,
            'message'=>'Stripe is not configured. No payment or cancellation was made.',
        ],503);
    }
}
