<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DepositController extends Controller
{
    public function __construct(private readonly DepositService $service) {}

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }

    private function record(int $id): object
    {
        return DB::table('job_deposits')->where('id',$id)->firstOrFail();
    }

    private function choices(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->where('category','!=','archived')
                ->orderByDesc('id')->limit(500)->get([
                    'id','company_id','reference_number','title']),
            'quotes'=>ServiceQuote::query()->whereIn('status',['approved','sent'])
                ->whereNotNull('service_job_id')->whereNotNull('deposit_minor')
                ->where('deposit_minor','>',0)->orderByDesc('id')->limit(500)
                ->get(['id','company_id','service_job_id','reference_number','deposit_minor']),
        ];
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'quote_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['pending','received','refunded','void'])],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.deposits.index',array_merge($this->choices(),[
            'result'=>$this->service->listing(null,$filters),
            'filters'=>$filters,
        ]));
    }

    public function create(): View
    {
        return view('admin.deposits.form',$this->choices());
    }

    public function edit(int $deposit): View
    {
        $row=$this->record($deposit);
        return view('admin.deposits.form',array_merge($this->choices(),[
            'record'=>$this->service->payload($this->service->find($row->company_id,$deposit)),
            'receipts'=>$this->service->receipts($row->company_id,$deposit),
            'history'=>$this->service->history($row->company_id,$deposit),
            'summary'=>$this->service->summary($row->company_id,$row->service_job_id,
                $row->service_quote_id),
        ]));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
        ]);
        $saved=$this->service->save($request,$this->company((int)$data['company_id']));
        return redirect()->route('admin.deposits.edit',$saved['id'])
            ->with('success','Pending manual deposit created.');
    }

    public function fromQuote(Request $request,int $quote)
    {
        $row=ServiceQuote::query()->findOrFail($quote);
        $saved=$this->service->fromQuote($request,$this->company($row->company_id),$quote);
        return redirect()->route('admin.deposits.edit',$saved['id'])
            ->with('success','Pending deposit created from approved quote.');
    }

    public function update(Request $request,int $deposit)
    {
        $row=$this->record($deposit);
        $this->service->save($request,$this->company($row->company_id),$deposit);
        return redirect()->route('admin.deposits.edit',$deposit)
            ->with('success','Pending deposit updated.');
    }

    public function receive(Request $request,int $deposit)
    {
        $row=$this->record($deposit);
        $this->service->change($request,$this->company($row->company_id),
            $deposit,'receive');
        return redirect()->route('admin.deposits.edit',$deposit)
            ->with('success','Manual payment confirmed as received.');
    }

    public function refund(Request $request,int $deposit)
    {
        $row=$this->record($deposit);
        $this->service->change($request,$this->company($row->company_id),
            $deposit,'refund');
        return redirect()->route('admin.deposits.edit',$deposit)
            ->with('success','Manual refund recorded; no bank transfer initiated.');
    }

    public function destroy(Request $request,int $deposit)
    {
        $row=$this->record($deposit);
        $this->service->change($request,$this->company($row->company_id),
            $deposit,'void');
        return redirect()->route('admin.deposits.index')
            ->with('success','Pending deposit voided; audit retained.');
    }

    public function upload(Request $request,int $deposit)
    {
        $row=$this->record($deposit);
        $this->service->upload($request,$this->company($row->company_id),$deposit);
        return redirect()->route('admin.deposits.edit',$deposit)
            ->with('success','Receipt stored privately with SHA-256 fingerprint.');
    }

    public function removeReceipt(Request $request,int $deposit,int $receipt)
    {
        $row=$this->record($deposit);
        $this->service->removeReceipt($request,$this->company($row->company_id),
            $deposit,$receipt);
        return redirect()->route('admin.deposits.edit',$deposit)
            ->with('success','Receipt removed from active view; audit retained.');
    }

    public function receipt(int $deposit,int $receipt)
    {
        $row=$this->record($deposit);
        $file=$this->service->receiptFile($row->company_id,$deposit,$receipt);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="deposit-receipt-'.$receipt.
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
            'status'=>['nullable',Rule::in(['pending','received','refunded','void'])],
            'q'=>['nullable','string','max:255'],
        ]);
        $safe=static function(mixed $raw): string {
            $s=str_replace(["\r","\n"],' ',(string)($raw??''));
            return preg_match('/^[=+@-]/',$s)?"'".$s:$s;
        };
        return response()->streamDownload(function()use($filters,$safe):void {
            $out=fopen('php://output','w');
            fputcsv($out,['ID','Company','Job','Quote','Client','CHF','Date',
                'Method','Reference','Status','Received At','Refunded At']);
            for($page=1;$page<=100;$page++) {
                $filters['page']=$page;$filters['per_page']=100;
                $set=$this->service->listing(null,$filters);
                foreach($set['records'] as $r)fputcsv($out,[
                    $r['id'],$r['company_id'],$safe($r['job']),$r['quote_id'],
                    $safe($r['client_name']),
                    number_format($r['amount_minor']/100,2,'.',''),
                    $r['deposit_date'],$safe($r['payment_method']),
                    $safe($r['reference_number']),$r['status'],
                    $r['received_at'],$r['refunded_at'],
                ]);
                if($page>=$set['last_page'])break;
            }
            fclose($out);
        },'sign2digital-deposits-'.now()->format('Ymd').'.csv',[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
