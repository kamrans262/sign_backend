<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WorkAcceptanceCustomerService;
use App\Services\WorkAcceptanceService;
use Illuminate\Http\Request;

final class PublicWorkAcceptanceController extends Controller
{
    public function __construct(
        private readonly WorkAcceptanceCustomerService $customers,
        private readonly WorkAcceptanceService $reports,
    ) {}

    private static function headers(): array
    {
        return [
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Robots-Tag'=>'noindex, nofollow',
            'Referrer-Policy'=>'no-referrer',
            'X-Content-Type-Options'=>'nosniff',
        ];
    }

    public function open(Request $request,string $token)
    {
        $cap=$this->customers->byToken($token);
        if($cap->status==='signed') {
            return redirect()->route('acceptance.public.receipt',['token'=>$token]);
        }
        $details=$this->customers->open($request,$token);
        $row=$details['report'];
        if($request->expectsJson()) {
            return response()->json(['data'=>[
                'report'=>[
                    'number'=>$row->number,
                    'completed_work'=>$row->completed_work,
                    'completion_date'=>$row->completion_date,
                    'cleaning_status'=>$row->cleaning_status,
                    'outstanding_issues'=>$row->outstanding_issues,
                    'remedial_work'=>$row->remedial_work,
                ],
                'signer'=>[
                    'name'=>$cap->signer_name,'request_uuid'=>$cap->request_uuid,
                    'document_uuid'=>$cap->document_uuid,
                    'revision'=>(int)$cap->approved_revision,
                    'consent_reference'=>$cap->consent_reference,
                ],
            ]],200,self::headers());
        }
        return response()->view('public.work-acceptance',[
            'item'=>$row,'signing'=>$cap,'token'=>$token,
        ],200,self::headers());
    }

    public function approvedPdf(Request $request,string $token)
    {
        $cap=$this->customers->byToken($token);
        $row=$this->customers->active($cap);
        return response($this->reports->approvedBytes($row),200,array_merge(
            self::headers(),[
                'Content-Type'=>'application/pdf',
                'Content-Disposition'=>'inline; filename="Work_Acceptance_Review.pdf"',
            ]));
    }

    public function submit(Request $request,string $token)
    {
        $result=$this->customers->submit($request,$token);
        if($request->expectsJson()) {
            return response()->json(['data'=>$result],200,self::headers());
        }
        return redirect()->route('acceptance.public.receipt',['token'=>$token]);
    }

    public function receipt(Request $request,string $token)
    {
        $record=$this->customers->receipt($token);
        if($request->expectsJson()) {
            return response()->json(['data'=>$record],200,self::headers());
        }
        return response()->view('public.work-acceptance-confirmation',[
            'record'=>$record,'token'=>$token,
        ],200,self::headers());
    }

    public function signedPdf(Request $request,string $token)
    {
        $bytes=$this->customers->signedBytes($token);
        return response($bytes,200,array_merge(self::headers(),[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="Sign2Digital_Signed_Work_Acceptance.pdf"',
        ]));
    }
}
