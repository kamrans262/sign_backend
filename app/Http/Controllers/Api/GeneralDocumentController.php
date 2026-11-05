<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GeneralDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GeneralDocumentController extends Controller
{
    public function __construct(private readonly GeneralDocumentService $documents) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }

    private function json(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $f=$request->validate([
            'q'=>['nullable','string','max:255'],
            'type'=>['nullable',Rule::in(['letter','statement','power_of_attorney','acceptance','custom'])],
            'status'=>['nullable',Rule::in(['draft','reviewed','approved','archived'])],
            'client_id'=>['nullable','integer','min:1'],
            'job_id'=>['nullable','integer','min:1'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','between:1,100'],
        ]);
        return $this->json($this->documents->listing($this->company($request)->id,$f));
    }

    public function show(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->detail($this->company($request)->id,$document));
    }

    public function revisions(Request $request,int $document): JsonResponse
    {
        return $this->json(['revisions'=>$this->documents->revisions(
            $this->company($request)->id,$document)]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->json(['document'=>$this->documents->save($request,$this->company($request))],201);
    }

    public function update(Request $request,int $document): JsonResponse
    {
        return $this->json(['document'=>$this->documents->save(
            $request,$this->company($request),$document)]);
    }

    public function review(Request $request,int $document): JsonResponse
    {
        return $this->json(['document'=>$this->documents->transition(
            $request,$this->company($request),$document,'review')]);
    }

    public function approve(Request $request,int $document): JsonResponse
    {
        return $this->json(['document'=>$this->documents->transition(
            $request,$this->company($request),$document,'approve')]);
    }

    public function archive(Request $request,int $document): JsonResponse
    {
        return $this->json(['document'=>$this->documents->transition(
            $request,$this->company($request),$document,'archive')]);
    }

    public function ai(Request $request): JsonResponse
    {
        return $this->json(['suggestion'=>$this->documents->prepareAi(
            $request,$this->company($request))]);
    }

    public function transcribe(Request $request): JsonResponse
    {
        return $this->json($this->documents->transcribe(
            $request,$this->company($request)));
    }

    public function attachment(Request $request,int $document,int $attachment)
    {
        $file=$this->documents->attachmentBytes(
            $this->company($request)->id,$document,$attachment);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="general-attachment-'.$attachment.'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function upload(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->upload(
            $request,$this->company($request),$document),201);
    }

    public function removeAttachment(Request $request,int $document,int $attachment): JsonResponse
    {
        return $this->json($this->documents->removeAttachment(
            $request,$this->company($request),$document,$attachment));
    }
}
