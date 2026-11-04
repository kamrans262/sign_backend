<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\LabourService;
use App\Services\LabourTimerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class LabourController extends Controller
{
    public function __construct(
        private readonly LabourService $labour,
        private readonly LabourTimerService $timers,
    ) {}

    private function company(int $id): Company
    {
        return Company::query()->where('status','active')->findOrFail($id);
    }

    private function entry(int $id): object
    {
        return DB::table('job_labour_entries')->where('id',$id)->firstOrFail();
    }

    private function choices(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')
                ->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->where('category','!=','archived')
                ->orderByDesc('id')->limit(500)->get([
                    'id','title','reference_number','company_id',
                ]),
            'workers'=>User::query()->where('is_active',true)
                ->orderBy('name')->limit(600)->get(['id','name']),
        ];
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'company_id'=>['nullable','integer','exists:companies,id'],
            'job_id'=>['nullable','integer','min:1'],
            'worker_id'=>['nullable','integer','min:1'],
            'kind'=>['nullable',Rule::in(['labour','time_sheet'])],
            'status'=>['nullable',Rule::in(['pending','approved','void'])],
            'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d'],
            'q'=>['nullable','string','max:255'],
            'page'=>['nullable','integer','min:1'],
        ]);
    }

    public function index(Request $request): View
    {
        $filters=$this->filters($request);
        $result=$this->labour->listing(null,$filters);
        return view('admin.labour.index',array_merge($this->choices(),[
            'result'=>$result,'filters'=>$filters,
            'running'=>$this->timers->active(
                empty($filters['company_id'])?null:(int)$filters['company_id']),
        ]));
    }

    public function create(): View
    {
        return view('admin.labour.form',$this->choices());
    }

    public function edit(int $entry): View
    {
        $raw=$this->entry($entry);
        return view('admin.labour.form',array_merge($this->choices(),[
            'record'=>$this->labour->payload($this->labour->find($raw->company_id,$entry)),
            'history'=>$this->labour->history($raw->company_id,$entry),
            'evidence'=>$this->labour->evidence($raw->company_id,$entry),
            'summary'=>$this->labour->jobTotals($raw->company_id,$raw->service_job_id),
        ]));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'company_id'=>['required','integer','exists:companies,id'],
        ]);
        $result=$this->labour->save($request,$this->company($data['company_id']));
        return redirect()->route('admin.labour.edit',$result['id'])
            ->with('success','Pending labour entry created.');
    }

    public function update(Request $request,int $entry)
    {
        $raw=$this->entry($entry);
        $this->labour->save($request,$this->company($raw->company_id),$entry);
        return redirect()->route('admin.labour.edit',$entry)
            ->with('success','Labour entry updated.');
    }

    public function approve(Request $request,int $entry)
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $raw=$this->entry($entry);
        $this->labour->transition($request,$this->company($raw->company_id),$entry,'approve');
        return redirect()->route('admin.labour.edit',$entry)
            ->with('success','Labour time approved and locked.');
    }

    public function void(Request $request,int $entry)
    {
        $raw=$this->entry($entry);
        $this->labour->transition($request,$this->company($raw->company_id),$entry,'void');
        return redirect()->route('admin.labour.edit',$entry)
            ->with('success','Labour entry voided with audit reason.');
    }

    public function upload(Request $request,int $entry)
    {
        $raw=$this->entry($entry);
        $this->labour->upload($request,$this->company($raw->company_id),$entry);
        return redirect()->route('admin.labour.edit',$entry)
            ->with('success','Private evidence attached.');
    }

    public function removeEvidence(Request $request,int $entry,int $evidence)
    {
        $raw=$this->entry($entry);
        $this->labour->removeEvidence($request,$this->company($raw->company_id),
            $entry,$evidence);
        return redirect()->route('admin.labour.edit',$entry)
            ->with('success','Evidence removed from active view; audit retained.');
    }

    public function evidenceFile(int $entry,int $evidence)
    {
        $raw=$this->entry($entry);
        $file=$this->labour->evidenceFile($raw->company_id,$entry,$evidence);
        return response($file['bytes'],200,[
            'Content-Type'=>$file['mime'],'X-Content-Type-Options'=>'nosniff',
            'Content-Disposition'=>'attachment; filename="labour-evidence-'.$evidence.
                ($file['mime']==='application/pdf'?'.pdf':
                ($file['mime']==='image/png'?'.png':'.jpg')).'"',
            'Cache-Control'=>'private, no-store',
        ]);
    }

    public function stopTimer(Request $request,int $company,int $worker)
    {
        $request->merge(['worker_id'=>$worker,
            'request_key'=>$request->input('request_key')?:\Illuminate\Support\Str::uuid()->toString()]);
        $this->timers->perform($request,$this->company($company),'stop');
        return redirect()->route('admin.labour.index')
            ->with('success','Worker timer stopped; pending timesheet created.');
    }

    public function export(Request $request)
    {
        $filters=$this->filters($request);
        unset($filters['page']);
        $filters['page']=1;$filters['per_page']=100;
        $safe=static function(mixed $value):string {
            $str=str_replace(["\r","\n"],' ',(string)($value??''));
            return preg_match('/^[=+@-]/',$str)?"'".$str:$str;
        };
        return response()->streamDownload(function()use($filters,$safe):void {
            $out=fopen('php://output','w');
            fputcsv($out,['Entry','Company','Job','Worker','Kind','Date',
                'Minutes','Hourly rate CHF','Cost CHF','Status']);
            for($page=1;$page<=100;$page++) {
                $filters['page']=$page;
                $set=$this->labour->listing(null,$filters);
                foreach($set['records'] as $r)fputcsv($out,[
                    $r['id'],$r['company_id'],$safe($r['job']),
                    $safe($r['worker_name']),$safe($r['kind']),$r['work_date'],
                    $r['duration_minutes'],
                    $r['hourly_rate_minor']===null?'':number_format(
                        $r['hourly_rate_minor']/100,2,'.',''),
                    $r['cost_minor']===null?'':number_format(
                        $r['cost_minor']/100,2,'.',''),$safe($r['status']),
                ]);
                if($page>=$set['last_page'])break;
            }
            fclose($out);
        },'sign2digital-labour-'.now()->format('Ymd').'.csv',[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
