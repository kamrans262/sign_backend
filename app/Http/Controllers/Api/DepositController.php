<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DepositController extends Controller
{
    public function __construct(private readonly DepositService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function reply(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'quote_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['pending','received','refunded'])],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        return $this->reply($this->service->listing($this->company($request)->id,$filters));
    }

    public function show(Request $request,int $deposit): JsonResponse
    {
        $company=$this->company($request);
        return $this->reply([
            'deposit'=>$this->service->payload($this->service->find($company->id,$deposit)),
            'receipts'=>$this->service->receipts($company->id,$deposit),
            'history'=>$this->service->history($company->id,$deposit),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->reply(['deposit'=>$this->service->save(
            $request,$this->company($request))],201);
    }

    public function fromQuote(Request $request,int $quote): JsonResponse
    {
        return $this->reply(['deposit'=>$this->service->fromQuote(
            $request,$this->company($request),$quote)],201);
    }

    public function update(Request $request,int $deposit): JsonResponse
    {
        return $this->reply(['deposit'=>$this->service->save(
            $request,$this->company($request),$deposit)]);
    }

    public function receive(Request $request,int $deposit): JsonResponse
    {
        return $this->reply(['deposit'=>$this->service->change(
            $request,$this->company($request),$deposit,'receive')]);
    }

    public function refund(Request $request,int $deposit): JsonResponse
    {
        return $this->reply(['deposit'=>$this->service->change(
            $request,$this->company($request),$deposit,'refund')]);
    }

    public function destroy(Request $request,int $deposit): JsonResponse
    {
        $request->merge(['confirm'=>true,
            'reason'=>'Pending deposit voided from the Sign2Digital mobile app.']);
        return $this->reply(['deposit'=>$this->service->change(
            $request,$this->company($request),$deposit,'void')]);
    }

    public function summary(Request $request,int $job): JsonResponse
    {
        $data=$request->validate(['quote_id'=>['nullable','integer','min:1']]);
        return $this->reply($this->service->summary(
            $this->company($request)->id,$job,
            isset($data['quote_id'])?(int)$data['quote_id']:null));
    }

    public function upload(Request $request,int $deposit): JsonResponse
    {
        return $this->reply($this->service->upload(
            $request,$this->company($request),$deposit),201);
    }

    public function removeReceipt(Request $request,int $deposit,int $receipt): JsonResponse
    {
        return $this->reply($this->service->removeReceipt(
            $request,$this->company($request),$deposit,$receipt));
    }

    public function receipt(Request $request,int $deposit,int $receipt)
    {
        $file=$this->service->receiptFile($this->company($request)->id,$deposit,$receipt);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="deposit-receipt-'.$receipt.
                ($file['mime']==='application/pdf'?'.pdf':
                    ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
