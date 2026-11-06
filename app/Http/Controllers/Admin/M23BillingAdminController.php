<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\M23BillingService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

final class M23BillingAdminController extends Controller
{
    public function __construct(private readonly M23BillingService $billing) {}

    /**
     * Preserve a complete, append-only snapshot of every price, benefit,
     * quota and publication revision. No secret keys are ever stored.
     */
    private function snapshotPlan(int $planId, Request $request): void
    {
        $row=DB::table('m23_plans')->where('id',$planId)->firstOrFail();
        DB::table('m23_plan_revisions')->insert([
            'plan_id'=>$planId,
            'revision'=>(int)$row->revision,
            'status'=>(string)$row->status,
            'snapshot'=>json_encode($row,JSON_THROW_ON_ERROR),
            'changed_by_user_id'=>$request->user()->id,
            'created_at'=>now(),
        ]);
    }

    public function index(Request $request): View
    {
        $filters=$request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'q'=>['nullable','string','max:120'],
            'status'=>['nullable',Rule::in(['free','trialing','active','past_due','unpaid',
                'paused','canceled','incomplete','pending_activation'])],
        ]);
        $companies=DB::table('companies as c')
            ->leftJoin('m23_company_subscriptions as s','s.company_id','=','c.id')
            ->leftJoin('m23_plans as p','p.id','=','s.plan_id')
            ->select('c.id','c.name','c.status as company_status',
                's.status as billing_status','s.period_ends_at',
                'p.name as plan_name','s.stripe_customer_id');
        if (!empty($filters['company_id']))$companies->where('c.id',$filters['company_id']);
        if (!empty($filters['status'])){
            if ($filters['status']==='free')$companies->where(
                fn($q)=>$q->whereNull('s.status')->orWhere('s.status','free'));
            else $companies->where('s.status',$filters['status']);
        }
        if (!empty($filters['q']))$companies->where('c.name','like',
            '%'.addcslashes($filters['q'],'%_').'%');
        $settings=$this->billing->settings();
        return view('admin.billing.index',[
            'plans'=>DB::table('m23_plans')->orderBy('code')->orderBy('period')->get(),
            'companies'=>$companies->orderBy('c.name')->paginate(20)->withQueryString(),
            'settings'=>$settings,
            'filters'=>$filters,
            'grants'=>DB::table('m23_subscription_grants as g')
                ->join('companies as c','c.id','=','g.company_id')
                ->select('g.*','c.name as company_name')
                ->orderByDesc('g.id')->limit(50)->get(),
            'billingInvoices'=>DB::table('m23_billing_invoices')
                ->orderByDesc('id')->limit(30)->get(),
            'providerEvents'=>DB::table('m23_provider_events')
                ->orderByDesc('id')->limit(30)->get(),
            'issuanceCounts'=>DB::table('m23_document_usage')
                ->select('company_id')->selectRaw('count(*) as total')
                ->groupBy('company_id')->pluck('total','company_id')->all(),
            'companyOptions'=>Company::orderBy('name')->get(['id','name']),
        ]);
    }

    public function editPlan(?int $plan=null): View
    {
        $row=$plan?DB::table('m23_plans')->where('id',$plan)->firstOrFail():null;
        return view('admin.billing.plan',[
            'plan'=>$row,'supportedFeatures'=>M23BillingService::FEATURES,
            'revisions'=>$row ? DB::table('m23_plan_revisions')
                ->where('plan_id',$row->id)
                ->orderByDesc('revision')->limit(50)->get() : collect(),
            'inUse'=>$row ? DB::table('m23_company_subscriptions')
                ->where('plan_id',$row->id)->exists():false,
        ]);
    }

    private function planPayload(Request $request): array
    {
        return $request->validate([
            'code'=>['required','string','max:48','regex:/^[a-z0-9_-]+$/'],
            'name'=>['required','string','max:120'],
            'period'=>['required',Rule::in(['monthly','yearly'])],
            'currency'=>['required',Rule::in(['CHF'])],
            'amount_minor'=>['required','integer','between:0,1000000000'],
            'description'=>['nullable','string','max:500'],
            'features'=>['nullable','array','max:8'],
            'features.*'=>['string','distinct',Rule::in(M23BillingService::FEATURES)],
            'document_limit'=>['nullable','integer','between:0,1000000'],
            'stripe_price_id'=>['nullable','string','max:255','regex:/^price_[A-Za-z0-9]+$/'],
        ]);
    }

    public function savePlan(Request $request,?int $plan=null): RedirectResponse
    {
        $data=$this->planPayload($request);
        $id=DB::transaction(function()use($plan,$data,$request):int {
            $row=$plan?DB::table('m23_plans')->where('id',$plan)->lockForUpdate()->first():null;
            if($plan!==null)abort_unless($row,404);
            $duplicate=DB::table('m23_plans')->where('code',$data['code'])
                ->where('period',$data['period'])
                ->when($plan!==null,fn($q)=>$q->where('id','!=',$plan))
                ->exists();
            if($duplicate)throw ValidationException::withMessages([
                'code'=>'This plan code and period already exist.',
            ]);
            if($row && DB::table('m23_company_subscriptions')
                ->where('plan_id',$plan)->exists()){
                throw ValidationException::withMessages([
                    'plan'=>'A subscribed plan is immutable; create a new plan revision.',
                ]);
            }
            $payload=[
                'code'=>$data['code'],'name'=>trim($data['name']),
                'period'=>$data['period'],'currency'=>$data['currency'],
                'amount_minor'=>$data['amount_minor'],
                'description'=>$data['description']??null,
                'features'=>json_encode($data['features']??[],JSON_THROW_ON_ERROR),
                'document_limit'=>$data['document_limit']??null,
                'stripe_price_id'=>$data['stripe_price_id']??null,
                'updated_by_user_id'=>$request->user()->id,
                'updated_at'=>now(),
            ];
            if($row){
                $payload['revision']=$row->revision+1;
                DB::table('m23_plans')->where('id',$plan)->update($payload);
                $id=(int)$plan;
            }else{
                $payload['status']='draft';
                $payload['created_at']=now();
                $id=DB::table('m23_plans')->insertGetId($payload);
            }
            $this->snapshotPlan($id,$request);
            $this->event(null,$request,'admin.plan.saved',
                ['plan_id'=>$id,'revision'=>$payload['revision']??1]);
            AuditLogger::record('admin.billing.plan_saved',$request->user(),null,null,
                ['plan_id'=>$id],$request);
            return $id;
        });
        return redirect()->route('admin.billing.plans.edit',$id)->with('success',
            'Plan saved. No customer charge was created.');
    }

    public function status(Request $request,int $plan): RedirectResponse
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['draft','published','archived'])],
        ]);
        DB::transaction(function()use($request,$plan,$data):void {
            $row=DB::table('m23_plans')->where('id',$plan)->lockForUpdate()->first();
            abort_unless($row,404);
            if($row->status===$data['status'])return;
            if(DB::table('m23_company_subscriptions')->where('plan_id',$plan)->exists()){
                abort(409,'A subscribed plan cannot change publication status.');
            }
            DB::table('m23_plans')->where('id',$plan)->update([
                'status'=>$data['status'],
                'revision'=>(int)$row->revision+1,
                'updated_by_user_id'=>$request->user()->id,
                'updated_at'=>now(),
            ]);
            $this->snapshotPlan($plan,$request);
            $this->event(null,$request,'admin.plan.status_changed',
                ['plan_id'=>$plan,'revision'=>(int)$row->revision+1,
                    'status'=>$data['status']]);
            AuditLogger::record('admin.billing.plan_status_changed',
                $request->user(),null,null,['plan_id'=>$plan,
                    'status'=>$data['status']],$request);
        });
        return back()->with('success','Plan publication status updated.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'free_document_limit'=>['required','integer','between:0,10000'],
            'trial_days'=>['nullable','integer','between:0,365'],
            'currency'=>['required',Rule::in(['CHF'])],
        ]);
        DB::transaction(function()use($data,$request):void {
            DB::table('m23_billing_settings')->where('id',1)->lockForUpdate()->firstOrFail();
            DB::table('m23_billing_settings')->where('id',1)->update([
                'free_document_limit'=>$data['free_document_limit'],
                'trial_days'=>$data['trial_days']??null,
                'currency'=>'CHF',
                // Plan/trial edits must not mutate activation switches.
                // A separately authorized server rollout owns both flags.
                'updated_by_user_id'=>$request->user()->id,'updated_at'=>now(),
            ]);
            $this->event(null,$request,'admin.settings.changed',[
                'free_document_limit'=>$data['free_document_limit'],
                'trial_days'=>$data['trial_days']??null]);
            AuditLogger::record('admin.billing.settings_changed',$request->user(),
                null,null,['free_document_limit'=>$data['free_document_limit']],$request);
        });
        return back()->with('success','Trial settings saved; enforcement and Stripe remain OFF.');
    }

    public function grant(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
            'label'=>['required','string','max:120'],
            'reason'=>['required','string','min:10','max:2000'],
            'expires_at'=>['required','date','after:now','before_or_equal:'.now()->addYear()->toDateTimeString()],
        ]);
        DB::transaction(function()use($data,$request):void {
            $company=Company::query()->where('id',$data['company_id'])
                ->lockForUpdate()->firstOrFail();
            $id=DB::table('m23_subscription_grants')->insertGetId([
                'company_id'=>$company->id,'label'=>trim($data['label']),
                'reason'=>trim($data['reason']),
                'starts_at'=>now(),'expires_at'=>$data['expires_at'],
                'created_by_user_id'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($company->id,$request,'admin.grant.created',
                ['grant_id'=>$id,'expires_at'=>$data['expires_at']]);
            AuditLogger::record('admin.billing.grant_created',$request->user(),
                $company,$company,['grant_id'=>$id],$request);
        });
        return back()->with('success','Time-bound administrative grant recorded; no Stripe payment claimed.');
    }

    public function revokeGrant(Request $request,int $grant): RedirectResponse
    {
        DB::transaction(function()use($request,$grant):void {
            $row=DB::table('m23_subscription_grants')
                ->where('id',$grant)->lockForUpdate()->first();
            abort_unless($row,404);
            DB::table('m23_subscription_grants')->where('id',$grant)
                ->where('expires_at','>',now())->update([
                    'expires_at'=>now(),'updated_at'=>now(),
                ]);
            $this->event((int)$row->company_id,$request,'admin.grant.revoked',['grant_id'=>$grant]);
            AuditLogger::record('admin.billing.grant_revoked',$request->user(),null,
                Company::find($row->company_id),['grant_id'=>$grant],$request);
        });
        return back()->with('success','Grant revoked.');
    }

    private function event(?int $companyId,Request $request,string $type,array $metadata=[]): void
    {
        DB::table('m23_subscription_events')->insert([
            'company_id'=>$companyId,
            'actor_user_id'=>$request->user()->id,
            'event_type'=>$type,
            'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }
}
