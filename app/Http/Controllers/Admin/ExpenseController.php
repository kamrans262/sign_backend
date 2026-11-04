<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Services\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }

    private function expense(int $id): object
    {
        return DB::table('job_expenses')->where('id',$id)->firstOrFail();
    }

    private function choices(): array
    {
        return ['companies'=>Company::query()->where('status','active')
            ->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->where('category','!=','archived')
                ->orderByDesc('id')->limit(500)->get([
                    'id','company_id','reference_number','title','budget_chf',
                ])];
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in(['to_review','approved','reimbursed'])],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.expenses.index',array_merge($this->choices(),[
            'result'=>$this->service->listing(null,$filters),
            'filters'=>$filters,
        ]));
    }

    public function create(): View
    {
        return view('admin.expenses.form',$this->choices());
    }

    public function edit(int $expense): View
    {
        $raw=$this->expense($expense);
        $record=$this->service->payload($this->service->find($raw->company_id,$expense));
        return view('admin.expenses.form',array_merge($this->choices(),[
            'record'=>$record,
            'receipts'=>$this->service->receipts($raw->company_id,$expense),
            'history'=>$this->service->history($raw->company_id,$expense),
            'totals'=>$this->service->totals($raw->company_id,$raw->service_job_id),
        ]));
    }

    /** Keep Admin tag edits on the same validated service path as mobile. */
    private function tags(Request $request): void
    {
        if(!$request->exists('tags_text'))return;
        $pieces=explode(',',(string)$request->input('tags_text'));
        $tags=array_slice(array_values(array_unique(array_filter(
            array_map('trim',$pieces),fn($v)=>$v!==''
        ))),0,12);
        $request->merge(['tags'=>$tags]);
    }

    public function store(Request $request)
    {
        $this->tags($request);
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
        ]);
        $saved=$this->service->save($request,$this->company($data['company_id']));
        return redirect()->route('admin.expenses.edit',$saved['id'])
            ->with('success','Expense created for review.');
    }

    public function update(Request $request,int $expense)
    {
        $this->tags($request);
        $row=$this->expense($expense);
        $this->service->save($request,$this->company($row->company_id),$expense);
        return redirect()->route('admin.expenses.edit',$expense)
            ->with('success','Expense updated.');
    }

    public function upload(Request $request,int $expense)
    {
        $row=$this->expense($expense);
        $this->service->upload($request,$this->company($row->company_id),$expense);
        return redirect()->route('admin.expenses.edit',$expense)
            ->with('success','Original receipt saved and fingerprinted.');
    }

    public function removeReceipt(Request $request,int $expense,int $receipt)
    {
        $row=$this->expense($expense);
        $this->service->removeReceipt($request,$this->company($row->company_id),
            $expense,$receipt);
        return redirect()->route('admin.expenses.edit',$expense)
            ->with('success','Receipt removed from active view; audit retained.');
    }

    public function receipt(int $expense,int $receipt)
    {
        $row=$this->expense($expense);
        $file=$this->service->receiptFile($row->company_id,$expense,$receipt);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],
            'Content-Disposition'=>'attachment; filename="receipt-'.$receipt.
                ($file['mime']==='application/pdf'?'.pdf':
                ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function approve(Request $request,int $expense)
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $row=$this->expense($expense);
        $this->service->changeStatus($request,$this->company($row->company_id),
            $expense,'approve');
        return redirect()->route('admin.expenses.edit',$expense)->with('success',
            'Expense approved and locked against changes.');
    }

    public function requestReimbursement(Request $request,int $expense)
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $row=$this->expense($expense);
        $this->service->changeStatus($request,$this->company($row->company_id),
            $expense,'request-reimbursement');
        return redirect()->route('admin.expenses.edit',$expense)->with('success',
            'Reimbursement request recorded.');
    }

    public function reimburse(Request $request,int $expense)
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $row=$this->expense($expense);
        $this->service->changeStatus($request,$this->company($row->company_id),
            $expense,'reimburse');
        return redirect()->route('admin.expenses.edit',$expense)->with('success',
            'Reimbursement marked as paid and audited.');
    }
}
