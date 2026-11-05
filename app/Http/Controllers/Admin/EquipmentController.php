<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\EquipmentLoanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class EquipmentController extends Controller
{
    public function __construct(private readonly EquipmentLoanService $service) {}

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }
    private function asset(int $id): object
    {
        return DB::table('equipment_assets')->where('id',$id)
            ->whereNull('archived_at')->firstOrFail();
    }
    private function loan(int $id): object
    {
        return DB::table('equipment_loans')->where('id',$id)->firstOrFail();
    }
    private function choices(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name','country_iso_code']),
            'clients'=>DB::table('clients')->whereNull('deleted_at')
                ->orderBy('full_name')->limit(700)->get([
                    'id','company_id','full_name','email',
                ]),
            'jobs'=>DB::table('service_jobs')->orderByDesc('id')->limit(700)
                ->get(['id','company_id','client_id','title']),
            'assets'=>DB::table('equipment_assets')->whereNull('archived_at')
                ->orderBy('code')->limit(700)->get([
                    'id','company_id','code','name','status',
                ]),
        ];
    }
    public function index(Request $request)
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'q'=>['nullable','string','max:255'],
            'status'=>['nullable',Rule::in(['available','in_use','maintenance'])],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.equipment.index',array_merge($this->choices(),[
            'filters'=>$filters,'result'=>$this->service->assets(null,$filters),
        ]));
    }
    public function create()
    {
        return view('admin.equipment.form',array_merge($this->choices(),[
            'record'=>null,
        ]));
    }
    public function edit(int $equipment)
    {
        $r=$this->asset($equipment);
        $loans=$this->service->loans($r->company_id,[
            'equipment_asset_id'=>$equipment,'per_page'=>100,
            'today'=>$this->service->companyDate($this->company($r->company_id)),
        ]);
        return view('admin.equipment.form',array_merge($this->choices(),[
            'record'=>$this->service->assetPayload(
                $this->service->asset($r->company_id,$equipment)),
            'loans'=>$loans,
        ]));
    }
    public function store(Request $request)
    {
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
        ]);
        $record=$this->service->saveAsset($request,$this->company($data['company_id']));
        return redirect()->route('admin.equipment.edit',$record['id'])
            ->with('success','Equipment saved and audited.');
    }
    public function update(Request $request,int $equipment)
    {
        $r=$this->asset($equipment);
        $this->service->saveAsset($request,$this->company($r->company_id),$equipment);
        return redirect()->route('admin.equipment.edit',$equipment)
            ->with('success','Equipment updated.');
    }
    public function archive(Request $request,int $equipment)
    {
        $r=$this->asset($equipment);
        $this->service->archiveAsset($request,$this->company($r->company_id),
            $equipment);
        return redirect()->route('admin.equipment.index')
            ->with('success','Equipment archived. Lending history preserved.');
    }
    public function usage(Request $request,int $equipment)
    {
        $r=$this->asset($equipment);
        $this->service->usage($request,$this->company($r->company_id),$equipment);
        return redirect()->route('admin.equipment.edit',$equipment)
            ->with('success','Usage recorded.');
    }
    public function maintenance(Request $request,int $equipment)
    {
        $r=$this->asset($equipment);
        $this->service->maintenance($request,$this->company($r->company_id),
            $equipment);
        return redirect()->route('admin.equipment.edit',$equipment)
            ->with('success','Maintenance recorded.');
    }
    public function upload(Request $request,int $equipment)
    {
        $r=$this->asset($equipment);
        $this->service->addPhoto($request,$this->company($r->company_id),
            $equipment);
        return redirect()->route('admin.equipment.edit',$equipment)
            ->with('success','Equipment photo stored privately and fingerprinted.');
    }
    public function photo(Request $request,int $equipment,int $photo)
    {
        $r=$this->asset($equipment);
        $data=$this->service->photoBytes($r->company_id,$equipment,$photo);
        return response($data['bytes'],200,[
            'Content-Type'=>$data['mime'],
            'Content-Disposition'=>'attachment; filename="equipment-photo-'.$photo.'"',
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function removePhoto(Request $request,int $equipment,int $photo)
    {
        $r=$this->asset($equipment);
        $this->service->removePhoto($request,$this->company($r->company_id),
            $equipment,$photo);
        return redirect()->route('admin.equipment.edit',$equipment)
            ->with('success','Photo removed from active listing; audit retained.');
    }
    public function loans(Request $request)
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'status'=>['nullable',Rule::in(['active','overdue','returned'])],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
        ]);
        if(!empty($filters['company_id'])){
            $filters['today']=$this->service->companyDate(
                $this->company((int)$filters['company_id']));
        }
        return view('admin.equipment.loans',array_merge($this->choices(),[
            'filters'=>$filters,'result'=>$this->service->loans(null,$filters),
        ]));
    }
    public function loanCreate()
    {
        return view('admin.equipment.loan-form',array_merge($this->choices(),[
            'record'=>null,
        ]));
    }
    public function loanEdit(int $loan)
    {
        $r=$this->loan($loan);
        return view('admin.equipment.loan-form',array_merge($this->choices(),[
            'record'=>$this->service->loanPayload($r,
                $this->service->companyDate($this->company($r->company_id))),
            'history'=>$this->service->loanHistory($r->company_id,$loan),
        ]));
    }
    public function loanStore(Request $request)
    {
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
        ]);
        if(!$request->filled('request_key'))$request->merge([
            'request_key'=>'admin-loan-'.(string)\Illuminate\Support\Str::uuid(),
        ]);
        $loan=$this->service->createLoan(
            $request,$this->company((int)$data['company_id']));
        return redirect()->route('admin.equipment.loan.edit',$loan['id'])
            ->with('success','Equipment loan recorded.');
    }
    public function loanUpdate(Request $request,int $loan)
    {
        $r=$this->loan($loan);
        $this->service->updateLoan($request,$this->company($r->company_id),$loan);
        return redirect()->route('admin.equipment.loan.edit',$loan)
            ->with('success','Loan details updated.');
    }
    public function loanExtend(Request $request,int $loan)
    {
        $r=$this->loan($loan);
        $this->service->transitionLoan($request,$this->company($r->company_id),
            $loan,'extend');
        return redirect()->route('admin.equipment.loan.edit',$loan)
            ->with('success','Return date extended and audited.');
    }
    public function loanReturn(Request $request,int $loan)
    {
        $r=$this->loan($loan);
        $this->service->transitionLoan($request,$this->company($r->company_id),
            $loan,'return');
        return redirect()->route('admin.equipment.loan.edit',$loan)
            ->with('success','Equipment marked returned. Loan history preserved.');
    }
}
