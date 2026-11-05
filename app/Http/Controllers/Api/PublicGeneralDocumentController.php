<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeneralDocumentIssueService;
use Illuminate\Http\Request;

final class PublicGeneralDocumentController extends Controller
{
    public function __construct(private readonly GeneralDocumentIssueService $documents) {}

    private static function headers(): array
    {
        return [
            'Cache-Control'=>'private, no-store, max-age=0',
            'Referrer-Policy'=>'no-referrer',
            'X-Robots-Tag'=>'noindex, nofollow',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'self'; style-src 'unsafe-inline'; ".
                "img-src 'self' data:; script-src 'unsafe-inline'; frame-ancestors 'none'",
        ];
    }

    private static function file(string $bytes,string $name,bool $download=false)
    {
        return response($bytes,200,array_merge(self::headers(),[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>($download?'attachment':'inline').
                '; filename="'.$name.'"',
        ]));
    }

    public function show(Request $request,string $token)
    {
        $data=$this->documents->publicDocument($token);
        if($request->expectsJson())return response()->json(['data'=>$data],
            200,self::headers());
        return response()->view('public.general-document',[
            'document'=>$data,'token'=>$token],200,self::headers());
    }
    public function pdf(Request $request,string $token)
    {
        $file=$this->documents->publicPdf($token);
        return self::file($file['bytes'],$file['filename']);
    }
    public function signing(Request $request,string $token)
    {
        $data=$this->documents->signingSummary($token);
        if($request->expectsJson())return response()->json(['data'=>$data],
            200,self::headers());
        return response()->view('public.general-document-sign',[
            'signing'=>$data,'token'=>$token],200,self::headers());
    }
    public function signingPdf(Request $request,string $token)
    {
        $file=$this->documents->signingPdf($token);
        return self::file($file['bytes'],$file['filename']);
    }
    public function submit(Request $request,string $token)
    {
        $result=$this->documents->complete($request,$token);
        if($request->expectsJson())return response()->json(['data'=>$result],
            200,self::headers());
        return redirect()->route('general-document.sign.receipt',['token'=>$token]);
    }
    public function receipt(Request $request,string $token)
    {
        $data=$this->documents->receipt($token);
        if($request->expectsJson())return response()->json(['data'=>$data],
            200,self::headers());
        return response()->view('public.general-document-receipt',[
            'receipt'=>$data,'token'=>$token],200,self::headers());
    }
    public function signedPdf(Request $request,string $token)
    {
        return self::file($this->documents->signedForCustomer($token),
            'Sign2Digital_Signed_General_Document.pdf',true);
    }
}
