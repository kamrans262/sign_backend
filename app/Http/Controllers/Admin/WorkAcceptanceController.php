<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Services\WorkAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkAcceptanceController extends Controller
{
    public function __construct(private readonly WorkAcceptanceService $reports) {}

    private function row(int $id): object
    {
        return DB::table('work_acceptances')->where('id',$id)->firstOrFail();
    }

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:255'],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'status'=>['nullable',Rule::in([
                'draft','approved','sent','viewed','accepted','accepted_with_reservations',
            ])],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.acceptances.index',[
            'result'=>$this->reports->list(null,$filters),
            'filters'=>$filters,
            'companies'=>Company::query()->orderBy('name')->get(['id','name']),
        ]);
    }

    private function formOptions(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->with('company:id,name')
                ->where('category','!=','archived')
                ->orderByDesc('id')->limit(500)
                ->get(['id','company_id','client_id','reference_number','title']),
        ];
    }

    public function create(): View
    {
        return view('admin.acceptances.form',$this->formOptions());
    }

    public function edit(int $acceptance): View
    {
        $row=$this->row($acceptance);
        $photos=DB::table('job_photos')->where('company_id',$row->company_id)
            ->where('service_job_id',$row->service_job_id)
            ->whereNull('archived_at')->orderByDesc('id')->limit(100)
            ->get(['id','category','caption']);
        return view('admin.acceptances.form',array_merge($this->formOptions(),[
            'record'=>$this->reports->payload($row),
            'history'=>$this->reports->events($row->company_id,$acceptance),
            'requests'=>$this->reports->requests($row->company_id,$acceptance),
            'revisions'=>$this->reports->revisions($row->company_id,$acceptance),
            'photos'=>$photos,
        ]));
    }

    public function store(Request $request)
    {
        $fields=$request->validate(['company_id'=>['required','integer',
            'exists:companies,id']]);
        $report=$this->reports->save($request,$this->company($fields['company_id']));
        return redirect()->route('admin.acceptances.edit',$report['id'])
            ->with('success','Work acceptance draft created.');
    }

    public function update(Request $request,int $acceptance)
    {
        $row=$this->row($acceptance);
        $this->reports->save($request,$this->company($row->company_id),$acceptance);
        return back()->with('success','Acceptance draft updated.');
    }

    public function approve(Request $request,int $acceptance)
    {
        $row=$this->row($acceptance);
        $this->reports->approve($request,$this->company($row->company_id),$acceptance);
        return back()->with('success','Acceptance version approved and locked.');
    }

    public function send(Request $request,int $acceptance)
    {
        $row=$this->row($acceptance);
        $this->reports->send($request,$this->company($row->company_id),$acceptance);
        return back()->with('success','Secure acceptance request emailed to customer.');
    }

    public function revoke(Request $request,int $acceptance,int $signature)
    {
        $row=$this->row($acceptance);
        $this->reports->revoke($request,$this->company($row->company_id),
            $acceptance,$signature);
        return back()->with('success','Customer signing request revoked.');
    }

    public function pdf(Request $request,int $acceptance,string $kind)
    {
        abort_unless(in_array($kind,['approved','signed'],true),404);
        $row=$this->row($acceptance);
        $bytes=$kind==='signed'?$this->reports->signedBytes($row):
            $this->reports->approvedBytes($row);
        $this->reports->event($row,'downloaded',$request->user()?->id,null,['kind'=>$kind]);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$row->number.'-'.$kind.'.pdf"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
