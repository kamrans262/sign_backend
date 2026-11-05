<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GeneralDocumentIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GeneralDocumentIssueController extends Controller
{
    public function __construct(private readonly GeneralDocumentIssueService $documents) {}

    private function company(Request $request): Company
    {
        $company=$request->attributes->get('company');
        abort_unless($company instanceof Company && $company->status==='active',403);
        return $company;
    }
    private function json(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status,[
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
    private function pdf(string $bytes,string $filename,bool $download=false)
    {
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>($download?'attachment':'inline').
                '; filename="'.$filename.'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function issue(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->issue(
            $request,$this->company($request),$document));
    }
    public function show(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->metadata(
            $this->company($request)->id,$document));
    }
    public function pdfView(Request $request,int $document)
    {
        return $this->pdf($this->documents->issuedPdf(
            $this->company($request)->id,$document),
            'Sign2Digital_General_Document_'.$document.'.pdf',
            $request->boolean('download'));
    }
    public function customerLink(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->link(
            $request,$this->company($request),$document));
    }
    public function revokeLink(Request $request,int $document,int $link): JsonResponse
    {
        return $this->json($this->documents->revokeLink(
            $request,$this->company($request),$document,$link));
    }
    public function signature(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->requestSignature(
            $request,$this->company($request),$document),201);
    }
    public function revokeSignature(Request $request,int $document,int $signature): JsonResponse
    {
        return $this->json($this->documents->revokeSignature(
            $request,$this->company($request),$document,$signature));
    }
    public function signedPdf(Request $request,int $document,int $signature)
    {
        return $this->pdf($this->documents->signedForBusiness(
            $this->company($request)->id,$document,$signature),
            'Sign2Digital_Signed_General_Document_'.$document.'.pdf',true);
    }
    public function send(Request $request,int $document): JsonResponse
    {
        return $this->json($this->documents->send(
            $request,$this->company($request),$document));
    }
}
