<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Services\SiteReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SiteReportController extends Controller
{
    public function __construct(private readonly SiteReportService $reports) {}

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
            'kind'=>['nullable',Rule::in(['site_report','meeting_minutes'])],
            'status'=>['nullable',Rule::in(['draft','approved','sent','archived'])],
            'page'=>['nullable','integer','min:1'],
        ]);
        return view('admin.reports.index',[
            'result'=>$this->reports->listing(null,$filters),
            'filters'=>$filters,
            'companies'=>Company::query()->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->orderByDesc('id')->limit(300)->get(['id','company_id','title','reference_number']),
        ]);
    }

    private function options(): array
    {
        return [
            'companies'=>Company::query()->where('status','active')->orderBy('name')->get(['id','name']),
            'jobs'=>ServiceJob::query()->with('company:id,name')
                ->where('category','!=','archived')->orderByDesc('id')->limit(500)
                ->get(['id','company_id','client_id','title','reference_number']),
        ];
    }

    public function create(): View
    {
        return view('admin.reports.form',$this->options());
    }

    public function edit(int $report): View
    {
        $row=$this->reports->find(
            (int)\Illuminate\Support\Facades\DB::table('site_reports')->where('id',$report)->value('company_id'),
            $report);
        return view('admin.reports.form',array_merge($this->options(),[
            'report'=>$this->reports->payload($row),
            'history'=>$this->reports->history($row->company_id,$report),
            'revisions'=>$this->reports->revisions($row->company_id,$report),
        ]));
    }

    private function transform(Request $request): void
    {
        $data=$request->validate([
            'kind'=>['nullable',Rule::in(['site_report','meeting_minutes'])],
            'sections'=>['nullable','array'],
            'sections.*'=>['nullable','string','max:6000'],
            'attendees_lines'=>['nullable','string','max:12000'],
            'absentees_lines'=>['nullable','string','max:12000'],
            'decisions_lines'=>['nullable','string','max:12000'],
            'next_steps_lines'=>['nullable','string','max:12000'],
            'action_items_lines'=>['nullable','string','max:12000'],
        ]);
        $lines=static fn(string $key)=>array_values(array_filter(array_map(
            'trim',preg_split('/\R/u',(string)($data[$key]??'')) ?: []),
            fn(string $value)=>$value!==''));
        $actions=array_map(fn(string $text)=>['description'=>$text],
            $lines('action_items_lines'));
        $request->merge(['content'=>[
            'sections'=>array_filter($data['sections']??[],fn($v)=>is_string($v)&&trim($v)!==''),
            'attendees'=>$lines('attendees_lines'),
            'absentees'=>$lines('absentees_lines'),
            'decisions'=>$lines('decisions_lines'),
            'next_steps'=>$lines('next_steps_lines'),
            'action_items'=>$actions,
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['company_id'=>['required','integer','exists:companies,id']]);
        $company=$this->company((int)$request->input('company_id'));
        $this->transform($request);
        $report=$this->reports->save($request,$company);
        return redirect()->route('admin.reports.edit',$report['id'])
            ->with('success','Report draft created.');
    }

    public function update(Request $request,int $report): RedirectResponse
    {
        $existing=$this->editRow($report);
        $this->transform($request);
        $this->reports->save($request,$this->company($existing->company_id),$report);
        return redirect()->route('admin.reports.edit',$report)->with('success','Draft saved.');
    }

    private function editRow(int $id): object
    {
        return \Illuminate\Support\Facades\DB::table('site_reports')->where('id',$id)->firstOrFail();
    }

    public function approve(Request $request,int $report): RedirectResponse
    {
        $item=$this->editRow($report);
        $this->reports->approve($request,$this->company($item->company_id),$report);
        return back()->with('success','Approved PDF saved to Document Management.');
    }

    public function send(Request $request,int $report): RedirectResponse
    {
        $item=$this->editRow($report);
        $this->reports->send($request,$this->company($item->company_id),$report);
        return back()->with('success','Approved report sent to client email.');
    }

    public function archive(Request $request,int $report): RedirectResponse
    {
        $item=$this->editRow($report);
        $this->reports->archive($request,$this->company($item->company_id),$report);
        return redirect()->route('admin.reports.index')->with('success','Report archived.');
    }

    public function pdf(int $report)
    {
        $item=$this->editRow($report);
        $bytes=$this->reports->verifiedPdf($item->company_id,$report);
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$item->number.'.pdf"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
