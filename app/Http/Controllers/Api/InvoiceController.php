<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\InvoiceService;
use App\Services\InvoiceDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices,
        private readonly InvoiceDocumentService $documents) {}

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
        $filters=$request->validate([
            'job_id'=>['nullable','integer','min:1'],
            'type'=>['nullable',Rule::in(['deposit','final'])],
            'status'=>['nullable',Rule::in(['draft','approved','sent','pending',
                'partially_paid','paid','overdue','void'])],
            'q'=>['nullable','string','max:255'],
            'per_page'=>['nullable','integer','between:1,100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return $this->json($this->invoices->listing($this->company($request)->id,$filters));
    }

    public function sources(Request $request,int $job): JsonResponse
    {
        return $this->json($this->invoices->sources(
            $this->company($request)->id,$job));
    }

    public function availableDeposits(Request $request,int $job): JsonResponse
    {
        return $this->json($this->invoices->availableDeposits(
            $this->company($request)->id,$job));
    }

    public function show(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->invoices->detail(
            $this->company($request)->id,$invoice));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->json(['invoice'=>$this->invoices->save(
            $request,$this->company($request))],201);
    }

    public function update(Request $request,int $invoice): JsonResponse
    {
        return $this->json(['invoice'=>$this->invoices->save(
            $request,$this->company($request),$invoice)]);
    }

    public function approve(Request $request,int $invoice): JsonResponse
    {
        return $this->json(['invoice'=>$this->invoices->approve(
            $request,$this->company($request),$invoice)]);
    }

    public function payment(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->invoices->payment(
            $request,$this->company($request),$invoice),201);
    }

    public function voidPayment(Request $request,int $invoice,int $payment): JsonResponse
    {
        return $this->json(['invoice'=>$this->invoices->voidPayment(
            $request,$this->company($request),$invoice,$payment)]);
    }

    public function voidInvoice(Request $request,int $invoice): JsonResponse
    {
        return $this->json(['invoice'=>$this->invoices->voidInvoice(
            $request,$this->company($request),$invoice)]);
    }

    public function upload(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->invoices->upload(
            $request,$this->company($request),$invoice),201);
    }

    public function removeAttachment(Request $request,int $invoice,int $attachment): JsonResponse
    {
        return $this->json($this->invoices->removeAttachment(
            $request,$this->company($request),$invoice,$attachment));
    }

    public function attachment(Request $request,int $invoice,int $attachment)
    {
        $file=$this->invoices->attachmentFile(
            $this->company($request)->id,$invoice,$attachment);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="invoice-attachment-'.$attachment.
                ($file['mime']==='application/pdf'?'.pdf':
                    ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
    // M17 Stage 2: only the server's immutable, hash-verified PDF is official.
    public function issue(Request $request,int $invoice): JsonResponse
    {
        return $this->json(['document'=>$this->documents->issue(
            $request,$this->company($request),$invoice)]);
    }

    public function document(Request $request,int $invoice): JsonResponse
    {
        return $this->json(['document'=>$this->documents->document(
            $this->company($request)->id,$invoice)]);
    }

    public function pdf(Request $request,int $invoice)
    {
        return $this->officialPdf($request,$invoice,false);
    }

    public function download(Request $request,int $invoice)
    {
        return $this->officialPdf($request,$invoice,true);
    }

    private function officialPdf(Request $request,int $invoice,bool $attachment)
    {
        $company=$this->company($request);
        $meta=$this->documents->document($company->id,$invoice);
        $bytes=$this->documents->documentBytes($company->id,$invoice);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>($attachment?'attachment':'inline').
                '; filename="'.$meta['filename'].'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function customerLink(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->documents->createLink(
            $request,$this->company($request),$invoice));
    }

    public function send(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->documents->send(
            $request,$this->company($request),$invoice));
    }

    public function reconciliation(Request $request,int $invoice): JsonResponse
    {
        return $this->json($this->documents->reconciliation(
            $this->company($request)->id,$invoice));
    }

}
