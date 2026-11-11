<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function json(array $data,int $code=200): JsonResponse
    {
        return response()->json(['data'=>$data],$code,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['to_review','approved','reimbursed'])],
            'q'=>['nullable','string','max:255'],
            'per_page'=>['nullable','integer','between:1,100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return $this->json($this->service->listing($this->company($request)->id,$filters));
    }

    public function show(Request $request,int $expense): JsonResponse
    {
        $company=$this->company($request);
        return $this->json([
            'expense'=>$this->service->payload($this->service->find($company->id,$expense)),
            'receipts'=>$this->service->receipts($company->id,$expense),
            'history'=>$this->service->history($company->id,$expense),
            'can_approve'=>$this->service->canApprove($company, $request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->json(['expense'=>$this->service->save(
            $request,$this->company($request))],201);
    }

    public function update(Request $request,int $expense): JsonResponse
    {
        return $this->json(['expense'=>$this->service->save(
            $request,$this->company($request),$expense)]);
    }

    public function upload(Request $request,int $expense): JsonResponse
    {
        return $this->json($this->service->upload(
            $request,$this->company($request),$expense),201);
    }

    public function removeReceipt(Request $request,int $expense,int $receipt): JsonResponse
    {
        return $this->json($this->service->removeReceipt($request,
            $this->company($request),$expense,$receipt));
    }

    public function receipt(Request $request,int $expense,int $receipt)
    {
        $file=$this->service->receiptFile($this->company($request)->id,$expense,$receipt);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="receipt-'.$receipt.
                ($file['mime']==='application/pdf'?'.pdf':
                ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function approve(Request $request,int $expense): JsonResponse
    {
        $request->validate(['confirm'=>['required','accepted']]);
        return $this->json(['expense'=>$this->service->changeStatus(
            $request,$this->company($request),$expense,'approve')]);
    }

    public function requestReimbursement(Request $request,int $expense): JsonResponse
    {
        $request->validate(['confirm'=>['required','accepted']]);
        return $this->json(['expense'=>$this->service->changeStatus(
            $request,$this->company($request),$expense,'request-reimbursement')]);
    }

    public function totals(Request $request,int $job): JsonResponse
    {
        return $this->json($this->service->totals($this->company($request)->id,$job));
    }
}
