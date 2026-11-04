<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\LabourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LabourController extends Controller
{
    public function __construct(private readonly LabourService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function response(array $payload,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$payload],$status,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'worker_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['pending','approved','void'])],
            'kind'=>['nullable',Rule::in(['labour','time_sheet'])],
            'q'=>['nullable','string','max:255'],
            'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        return $this->response($this->service->listing($this->company($request)->id,$filters));
    }

    public function show(Request $request,int $entry): JsonResponse
    {
        $company=$this->company($request);
        return $this->response([
            'entry'=>$this->service->payload($this->service->find($company->id,$entry)),
            'history'=>$this->service->history($company->id,$entry),
            'evidence'=>$this->service->evidence($company->id,$entry),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->response(['entry'=>$this->service->save(
            $request,$this->company($request))],201);
    }

    public function update(Request $request,int $entry): JsonResponse
    {
        return $this->response(['entry'=>$this->service->save(
            $request,$this->company($request),$entry)]);
    }

    public function approve(Request $request,int $entry): JsonResponse
    {
        $request->validate(['confirm'=>['required','accepted']]);
        return $this->response(['entry'=>$this->service->transition(
            $request,$this->company($request),$entry,'approve')]);
    }

    public function void(Request $request,int $entry): JsonResponse
    {
        return $this->response(['entry'=>$this->service->transition(
            $request,$this->company($request),$entry,'void')]);
    }

    public function workforce(Request $request,int $job): JsonResponse
    {
        return $this->response([
            'workers'=>$this->service->workforce($this->company($request),$job),
        ]);
    }

    public function summary(Request $request,int $job): JsonResponse
    {
        return $this->response($this->service->jobTotals($this->company($request)->id,$job));
    }

    public function upload(Request $request,int $entry): JsonResponse
    {
        return $this->response($this->service->upload(
            $request,$this->company($request),$entry),201);
    }

    public function removeEvidence(Request $request,int $entry,int $evidence): JsonResponse
    {
        return $this->response($this->service->removeEvidence(
            $request,$this->company($request),$entry,$evidence));
    }

    public function evidenceFile(Request $request,int $entry,int $evidence)
    {
        $file=$this->service->evidenceFile(
            $this->company($request)->id,$entry,$evidence);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="labour-proof-'.$evidence.
                ($file['mime']==='application/pdf'?'.pdf':
                ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
