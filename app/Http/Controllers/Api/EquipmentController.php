<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\EquipmentLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EquipmentController extends Controller
{
    public function __construct(private readonly EquipmentLoanService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }
    private function respond(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'status'=>['nullable',Rule::in(['available','in_use','maintenance'])],
            'per_page'=>['nullable','integer','between:1,100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return $this->respond($this->service->assets($this->company($request)->id,$filters));
    }
    public function show(Request $request,int $equipment): JsonResponse
    {
        $company=$this->company($request);
        return $this->respond(['asset'=>$this->service->assetPayload(
            $this->service->asset($company->id,$equipment)),
            'loans'=>$this->service->loans($company->id,[
                'equipment_asset_id'=>$equipment,'per_page'=>100,
            ])['records']]);
    }
    public function store(Request $request): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->saveAsset(
            $request,$this->company($request))],201);
    }
    public function update(Request $request,int $equipment): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->saveAsset(
            $request,$this->company($request),$equipment)]);
    }
    public function archive(Request $request,int $equipment): JsonResponse
    {
        return $this->respond($this->service->archiveAsset(
            $request,$this->company($request),$equipment));
    }
    public function usage(Request $request,int $equipment): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->usage(
            $request,$this->company($request),$equipment)],201);
    }
    public function maintenance(Request $request,int $equipment): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->maintenance(
            $request,$this->company($request),$equipment)],201);
    }
    public function voice(Request $request,\App\Services\EquipmentLoanVoiceService $voice): JsonResponse
    {
        return $this->respond($voice->prepareAsset($request,$this->company($request)));
    }

    public function photo(Request $request,int $equipment): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->addPhoto(
            $request,$this->company($request),$equipment)],201);
    }
    public function photoBytes(Request $request,int $equipment,int $photo)
    {
        $file=$this->service->photoBytes(
            $this->company($request)->id,$equipment,$photo);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'inline; filename="equipment-photo-'.$photo.'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function removePhoto(Request $request,int $equipment,int $photo): JsonResponse
    {
        return $this->respond(['asset'=>$this->service->removePhoto(
            $request,$this->company($request),$equipment,$photo)]);
    }
}
