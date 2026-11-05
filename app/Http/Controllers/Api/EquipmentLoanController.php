<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\EquipmentLoanService;
use App\Services\EquipmentLoanVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EquipmentLoanController extends Controller
{
    public function __construct(private readonly EquipmentLoanService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }
    private function respond(array $data,int $code=200): JsonResponse
    {
        return response()->json(['data'=>$data],$code,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'status'=>['nullable',Rule::in(['active','returned','overdue'])],
            'equipment_asset_id'=>['nullable','integer','min:1'],
            'client_id'=>['nullable','integer','min:1'],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        $company=$this->company($request);
        $filters['today']=$this->service->companyDate($company);
        return $this->respond($this->service->loans($company->id,$filters));
    }
    public function show(Request $request,int $loan): JsonResponse
    {
        $company=$this->company($request);
        return $this->respond(['loan'=>$this->service->loanPayload(
            $this->service->loan($company->id,$loan),
            $this->service->companyDate($company)),
            'history'=>$this->service->loanHistory($company->id,$loan)]);
    }
    public function store(Request $request): JsonResponse
    {
        return $this->respond(['loan'=>$this->service->createLoan(
            $request,$this->company($request))],201);
    }
    public function update(Request $request,int $loan): JsonResponse
    {
        return $this->respond(['loan'=>$this->service->updateLoan(
            $request,$this->company($request),$loan)]);
    }
    public function extend(Request $request,int $loan): JsonResponse
    {
        return $this->respond(['loan'=>$this->service->transitionLoan(
            $request,$this->company($request),$loan,'extend')]);
    }
    public function returnLoan(Request $request,int $loan): JsonResponse
    {
        return $this->respond(['loan'=>$this->service->transitionLoan(
            $request,$this->company($request),$loan,'return')]);
    }
    public function voice(Request $request,EquipmentLoanVoiceService $voice): JsonResponse
    {
        return $this->respond($voice->prepare($request,$this->company($request)));
    }
    public function history(Request $request,int $loan): JsonResponse
    {
        return $this->respond(['events'=>$this->service->loanHistory(
            $this->company($request)->id,$loan)]);
    }
}
