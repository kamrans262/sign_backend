<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SiteReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'photos'=>$this->photoMetadata($companyId,$report),
            'events'=>$this->reports->history($companyId,$report),
            'revisions'=>$this->reports->revisions($companyId,$report),
        ]);
    }

    private function photoMetadata(int $companyId,int $report): array
    {
        $source=$this->reports->find($companyId,$report);
        $ids=json_decode((string)($source->photo_ids??'[]'),true)?:[];
        if(!$ids)return [];
        return DB::table('job_photos')->where('company_id',$companyId)
            ->where('service_job_id',$source->service_job_id)
            ->whereIn('id',$ids)->get(['id','mime_type','sha256'])
            ->map(fn($row)=>[
                'id'=>(int)$row->id,'mime_type'=>$row->mime_type,
                'sha256'=>$row->sha256,
            ])->all();
    }

    public function photo(Request $request,int $report,int $photo)
    {
        $companyId=$this->company($request)->id;
        $source=$this->reports->find($companyId,$report);
        $ids=json_decode((string)($source->photo_ids??'[]'),true)?:[];
        abort_unless(in_array($photo,array_map('intval',$ids),true),404);
        $row=DB::table('job_photos')->where('company_id',$companyId)
            ->where('service_job_id',$source->service_job_id)
            ->where('id',$photo)->firstOrFail();
        abort_unless(in_array($row->mime_type,['image/jpeg','image/png'],true),409);
        $disk=Storage::disk('local');
        abort_unless($disk->exists($row->private_path),404);
        $bytes=$disk->get($row->private_path);
        abort_unless(is_string($bytes) &&
            hash_equals($row->sha256,hash('sha256',$bytes)),409,
            'Referenced report photo failed integrity checks.');
        return response($bytes,200,[
            'Content-Type'=>$row->mime_type,'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
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
        $this->reports->logDownload($request,$company,$report);
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
