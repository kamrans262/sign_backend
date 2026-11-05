<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    private function company(int $company): Company
    {
        return Company::query()->where('status','active')->findOrFail($company);
    }

    private function record(int $invoice): object
    {
        return DB::table('service_invoices')->where('id',$invoice)->firstOrFail();
    }

    private function choices(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->where('category','!=','archived')
                ->orderByDesc('id')->limit(750)->get([
                    'id','company_id','client_id','reference_number','title']),
            'addenda'=>DB::table('addenda')->whereNotNull('signed_at')
                ->whereNotNull('signed_pdf_sha256')
                ->orderByDesc('id')->limit(300)->get([
                    'id','company_id','service_job_id','number','title']),
            'quotes'=>ServiceQuote::query()->whereIn('status',['approved','sent'])
                ->whereNotNull('approved_at')->whereNotNull('service_job_id')
                ->orderByDesc('id')->limit(750)->get([
                    'id','company_id','service_job_id','reference_number']),
        ];
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'type'=>['nullable',Rule::in(['deposit','final'])],
            'status'=>['nullable',Rule::in(['draft','approved','sent','pending',
                'partially_paid','paid','overdue','void'])],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.invoices.index',array_merge($this->choices(),[
            'result'=>$this->invoices->listing(null,$filters),
            'filters'=>$filters,
        ]));
    }

    public function create(): View
    {
        return view('admin.invoices.form',$this->choices());
    }

    public function edit(int $invoice): View
    {
        $row=$this->record($invoice);
        return view('admin.invoices.form',array_merge($this->choices(),[
            'record'=>$this->invoices->detail($row->company_id,$invoice),
        ]));
    }

    private function prepared(Request $request): void
    {
        $items=[];
        foreach($request->input('items',[]) as $item){
            if(trim((string)($item['description']??''))===''&&
                trim((string)($item['unit_price_minor']??''))==='')continue;
            $items[]=[
                'description'=>trim((string)($item['description']??'')),
                'quantity'=>$item['quantity']??1,
                'unit_price_minor'=>$item['unit_price_minor']??null,
            ];
        }
        $request->merge([
            'items'=>$items,
            'allocate_deposits'=>$request->boolean('allocate_deposits'),
        ]);
    }

    public function store(Request $request)
    {
        $data=$request->validate(['company_id'=>['required','integer','exists:companies,id']]);
        $this->prepared($request);
        $saved=$this->invoices->save($request,$this->company((int)$data['company_id']));
        return redirect()->route('admin.invoices.edit',$saved['id'])
            ->with('success','Draft invoice saved. No invoice was sent or paid.');
    }

    public function update(Request $request,int $invoice)
    {
        $row=$this->record($invoice);
        $this->prepared($request);
        $this->invoices->save($request,$this->company($row->company_id),$invoice);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Draft invoice updated.');
    }

    public function approve(Request $request,int $invoice)
    {
        $row=$this->record($invoice);
        $this->invoices->approve($request,$this->company($row->company_id),$invoice);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Invoice approved and locked; delivery is a separate action.');
    }

    public function payment(Request $request,int $invoice)
    {
        $row=$this->record($invoice);
        $this->invoices->payment($request,$this->company($row->company_id),$invoice);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Manual payment recorded. No bank transaction initiated.');
    }

    public function voidPayment(Request $request,int $invoice,int $payment)
    {
        $row=$this->record($invoice);
        $this->invoices->voidPayment($request,$this->company($row->company_id),
            $invoice,$payment);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Payment voided. Original record and audit preserved.');
    }

    public function voidInvoice(Request $request,int $invoice)
    {
        $row=$this->record($invoice);
        $this->invoices->voidInvoice($request,$this->company($row->company_id),$invoice);
        return redirect()->route('admin.invoices.index')
            ->with('success','Invoice voided without removing its history.');
    }

    public function upload(Request $request,int $invoice)
    {
        $row=$this->record($invoice);
        $this->invoices->upload($request,$this->company($row->company_id),$invoice);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Original invoice attachment verified and saved privately.');
    }

    public function removeAttachment(Request $request,int $invoice,int $attachment)
    {
        $row=$this->record($invoice);
        $this->invoices->removeAttachment($request,$this->company($row->company_id),
            $invoice,$attachment);
        return redirect()->route('admin.invoices.edit',$invoice)
            ->with('success','Attachment hidden from active view; audit retained.');
    }

    public function attachment(int $invoice,int $attachment)
    {
        $row=$this->record($invoice);
        $file=$this->invoices->attachmentFile($row->company_id,$invoice,$attachment);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="invoice-attachment-'.$attachment.
                ($file['mime']==='application/pdf'?'.pdf':
                    ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function export(Request $request)
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'type'=>['nullable',Rule::in(['deposit','final'])],
            'status'=>['nullable',Rule::in(['draft','approved','sent','pending',
                'partially_paid','paid','overdue','void'])],
            'q'=>['nullable','string','max:255'],
        ]);
        $safe=static function(mixed $v): string {
            $s=str_replace(["\r","\n"],' ',(string)($v??''));
            return preg_match('/^[\s=+@-]*[=+@-]/u',$s)?"'".$s:$s;
        };
        return response()->streamDownload(function()use($filters,$safe):void{
            $out=fopen('php://output','w');
            fputcsv($out,['ID','Company','Job','Number','Client','Type','Status',
                'Issue','Due','Total CHF','Deposits CHF','Payments CHF','Due CHF']);
            for($page=1;;$page++){
                $filters['page']=$page;$filters['per_page']=100;
                $set=$this->invoices->listing(null,$filters);
                foreach($set['records'] as $r){
                    fputcsv($out,[
                        $r['id'],$r['company_id'],$safe($r['job']),
                        $safe($r['number']),$safe($r['client_name']),
                        $r['type'],$r['status'],$r['invoice_date'],$r['due_date'],
                        number_format($r['total_minor']/100,2,'.',''),
                        number_format($r['deposit_paid_minor']/100,2,'.',''),
                        number_format($r['received_minor']/100,2,'.',''),
                        number_format($r['amount_due_minor']/100,2,'.',''),
                    ]);
                }
                if($page>=$set['last_page'])break;
            }
            fclose($out);
        },'sign2digital-invoices-'.now()->format('Ymd').'.csv',[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
