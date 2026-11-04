<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\WorkAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class WorkAcceptanceController extends Controller
{
    public function __construct(private readonly WorkAcceptanceService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function response(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in([
                'draft','approved','sent','viewed','accepted',
                'accepted_with_reservations',
            ])],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        return $this->response($this->service->list($this->company($request)->id,$filters));
    }

    public function show(Request $request,int $acceptance): JsonResponse
    {
        $companyId=$this->company($request)->id;
        $row=$this->service->find($companyId,$acceptance);
        return $this->response([
            'acceptance'=>$this->service->payload($row),
            'events'=>$this->service->events($companyId,$acceptance),
            'revisions'=>$this->service->revisions($companyId,$acceptance),
            'requests'=>$this->service->requests($companyId,$acceptance),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->response(['acceptance'=>$this->service->save(
            $request,$this->company($request))],201);
    }

    public function update(Request $request,int $acceptance): JsonResponse
    {
        return $this->response(['acceptance'=>$this->service->save(
            $request,$this->company($request),$acceptance)]);
    }

    public function approve(Request $request,int $acceptance): JsonResponse
    {
        return $this->response(['acceptance'=>$this->service->approve(
            $request,$this->company($request),$acceptance)]);
    }

    public function send(Request $request,int $acceptance): JsonResponse
    {
        return $this->response($this->service->send(
            $request,$this->company($request),$acceptance));
    }

    public function requests(Request $request,int $acceptance): JsonResponse
    {
        return $this->response(['requests'=>$this->service->requests(
            $this->company($request)->id,$acceptance)]);
    }

    public function revoke(Request $request,int $acceptance,int $signature): JsonResponse
    {
        return $this->response(['acceptance'=>$this->service->revoke(
            $request,$this->company($request),$acceptance,$signature)]);
    }

    public function pdf(Request $request,int $acceptance,string $kind)
    {
        abort_unless(in_array($kind,['approved','signed'],true),404);
        $company=$this->company($request);
        $row=$this->service->find($company->id,$acceptance);
        $bytes=$kind==='signed'?$this->service->signedBytes($row):
            $this->service->approvedBytes($row);
        $this->service->event($row,'downloaded',$request->user()?->id,null,
            ['kind'=>$kind]);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.
                $row->number.'-'.$kind.'.pdf"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function customerPhotos(Request $request,int $acceptance): JsonResponse
    {
        return $this->response(['photos'=>$this->service->customerPhotos(
            $this->company($request)->id,$acceptance)]);
    }

    public function customerPhoto(Request $request,int $acceptance,int $photo)
    {
        $company=$this->company($request);
        $data=$this->service->customerPhoto($company->id,$acceptance,$photo);
        return response($data['bytes'],200,[
            'Content-Type'=>$data['mime_type'],
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Disposition'=>'inline; filename="customer-evidence-'.$photo.
                ($data['mime_type']==='image/png'?'.png':'.jpg').'"',
        ]);
    }

    public function signature(Request $request,int $acceptance)
    {
        $company=$this->company($request);
        $row=$this->service->find($company->id,$acceptance);
        $artifact=DB::table('work_acceptance_artifacts')
            ->where('company_id',$company->id)
            ->where('work_acceptance_id',$row->id)->firstOrFail();
        $disk=Storage::disk('local');
        abort_unless($disk->exists($artifact->signature_path),404);
        $bytes=$disk->get($artifact->signature_path);
        abort_unless(is_string($bytes) &&
            hash_equals($artifact->signature_sha256,hash('sha256',$bytes)),409,
            'Signature evidence failed integrity checks.');
        return response($bytes,200,[
            'Content-Type'=>'image/png','Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
