<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvoiceDocumentService;
use Illuminate\Http\Request;

final class PublicInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceDocumentService $documents) {}

    public function show(Request $request,string $token)
    {
        $details=$this->documents->publicSummary($token);
        return response()->view('public.invoice',['details'=>$details,'token'=>$token],
            200,['Cache-Control'=>'private, no-store, max-age=0',
                'X-Content-Type-Options'=>'nosniff',
                'Referrer-Policy'=>'no-referrer']);
    }

    public function pdf(Request $request,string $token)
    {
        $pdf=$this->documents->customerPdf($token);
        return response($pdf['bytes'],200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="'.$pdf['filename'].'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }
}
