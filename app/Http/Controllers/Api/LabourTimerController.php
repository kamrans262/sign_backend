<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\LabourTimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LabourTimerController extends Controller
{
    public function __construct(private readonly LabourTimerService $service) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function reply(array $data): JsonResponse
    {
        return response()->json(['data'=>$data],200,[
            'Cache-Control'=>'private, no-store',
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $company=$this->company($request);
        return $this->reply(['timer'=>$this->service->current(
            $company,(int)$request->user()->id)]);
    }

    public function action(Request $request,string $action): JsonResponse
    {
        return $this->reply($this->service->perform($request,
            $this->company($request),$action));
    }
}
