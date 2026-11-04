<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SiteReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteReportController extends Controller
{
    public function __construct(private readonly SiteReportService $reports) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function respond(array $value,int $code=200): JsonResponse
    {
        return response()->json(['data'=>$value],$code,['Cache-Control'=>'private, no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'kind'=>['nullable',Rule::in(['site_report','meeting_minutes'])],
            'status'=>['nullable',Rule::in(['draft','approved','sent','archived'])],
            'job_id'=>['nullable','integer','min:1'],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        return $this->respond($this->reports->listing($this->company($request)->id,$filters));
    }

    public function show(Request $request,int $report): JsonResponse
    {
        $companyId=$this->company($request)->id;
        return $this->respond([
            'report'=>$this->reports->payload($this->reports->find($companyId,$report)),
            'events'=>$this->reports->history($companyId,$report),
            'revisions'=>$this->reports->revisions($companyId,$report),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        return $this->respond(['report'=>$this->reports->save(
            $request,$this->company($request))],201);
    }

    public function update(Request $request,int $report): JsonResponse
    {
        return $this->respond(['report'=>$this->reports->save(
            $request,$this->company($request),$report)]);
    }

    public function prepare(Request $request,int $report): JsonResponse
    {
        return $this->respond(['suggestion'=>$this->reports->aiSuggest(
            $request,$this->company($request),$report)]);
    }

    public function approve(Request $request,int $report): JsonResponse
    {
        return $this->respond(['report'=>$this->reports->approve(
            $request,$this->company($request),$report)]);
    }

    public function pdf(Request $request,int $report)
    {
        $company=$this->company($request);
        $record=$this->reports->find($company->id,$report);
        $bytes=$this->reports->verifiedPdf($company->id,$report);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$record->number.'.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function send(Request $request,int $report): JsonResponse
    {
        return $this->respond(['report'=>$this->reports->send(
            $request,$this->company($request),$report)]);
    }

    public function archive(Request $request,int $report): JsonResponse
    {
        return $this->respond(['report'=>$this->reports->archive(
            $request,$this->company($request),$report)]);
    }
}
