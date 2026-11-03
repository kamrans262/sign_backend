<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceQuoteController extends Controller
{
    public function index(Request $request): View
    {
        $filter=$request->validate([
            'q'=>['nullable','string','max:255'],
            'company_id'=>['nullable','integer','exists:companies,id'],
            'status'=>['nullable',Rule::in(['draft','approved','sent','archived'])],
        ]);
        $query=ServiceQuote::query()->with(['company:id,name','client:id,full_name'])
            ->latest('id');
        if (!empty($filter['company_id'])) $query->where('company_id',$filter['company_id']);
        if (!empty($filter['status'])) $query->where('status',$filter['status']);
        if (!empty($filter['q'])) {
            $needle=trim($filter['q']);
            $query->where(fn($q)=>$q->where('reference_number','like','%'.$needle.'%')
                ->orWhere('client_name','like','%'.$needle.'%'));
        }
        return view('admin.quotes.index',[
            'quotes'=>$query->paginate(20)->withQueryString(),
            'companies'=>Company::query()->orderBy('name')->get(['id','name']),
            'search'=>trim((string)($filter['q']??'')),
            'companyFilter'=>(int)($filter['company_id']??0),
            'status'=>$filter['status']??'',
        ]);
    }

    private function options(): array
    {
        return [
            'companies'=>Company::query()->orderBy('name')->get(['id','name']),
            'clients'=>Client::query()->orderBy('full_name')->get(['id','company_id','full_name','company_name']),
            'jobs'=>ServiceJob::query()->whereNot('category','archived')->orderBy('id')
                ->get(['id','company_id','client_id','reference_number','title']),
        ];
    }

    public function create(): View { return view('admin.quotes.form',$this->options()); }

    public function edit(int $quote): View
    {
        return view('admin.quotes.form',array_merge($this->options(),[
            'quote'=>ServiceQuote::query()->with('items')->findOrFail($quote),
        ]));
    }

    private function fields(Request $request, ?ServiceQuote $existing): array
    {
        $data=$request->validate([
            'company_id'=>['required','integer',Rule::exists('companies','id')->where('status','active')],
            'client_id'=>['nullable','integer'],
            'service_job_id'=>['nullable','integer'],
            'quote_number'=>['nullable','string','max:40','regex:~^[A-Za-z0-9][A-Za-z0-9 _./-]*$~'],
            'client_name'=>['required','string','max:255'],
            'client_email'=>['nullable','email','max:255'],
            'client_phone'=>['nullable','string','max:60'],
            'billing_address'=>['nullable','string','max:4000'],
            'quote_date'=>['required','date_format:Y-m-d'],
            'valid_until'=>['nullable','date_format:Y-m-d','after_or_equal:quote_date'],
            'currency'=>['required',Rule::in(['CHF','EUR','GBP','USD'])],
            'vat_basis_points'=>['required','integer','between:0,10000'],
            'deposit_basis_points'=>['required','integer','between:0,10000'],
            'terms'=>['nullable','string','max:20000'],
            'payment_details'=>['nullable','array:iban,accountHolder,street,buildingNumber,postalCode,city,country,paymentReference'],
            'payment_details.*'=>['nullable','string','max:255'],
            'items'=>['required','array','min:1','max:25'],
            'items.*.title'=>['nullable','string','max:255'],
            'items.*.description'=>['nullable','string','max:5000'],
            'items.*.quantity'=>['nullable','integer','between:1,100000'],
            'items.*.dimensions'=>['nullable','string','max:255'],
            'items.*.unit_price'=>['nullable','regex:/^\d{1,11}(\.\d{1,2})?$/'],
        ]);
        if ($existing && $existing->company_id !== (int)$data['company_id']) {
            throw ValidationException::withMessages(['company_id'=>'Existing quote company is immutable.']);
        }
        $company=(int)$data['company_id'];
        $number=trim((string)($data['quote_number']??''));
        if ($number!=='') {
            $duplicate=ServiceQuote::query()
                ->where('company_id',$company)
                ->where('custom_quote_number',$number)
                ->when($existing, fn($q)=>$q->whereKeyNot($existing->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'quote_number'=>'Quote number is already in use in this company.',
                ]);
            }
        }
        $data['custom_quote_number']=$number!==''?$number:null;
        unset($data['quote_number']);
        if (!empty($data['client_id'])) {
            if (!Client::query()->where('company_id',$company)->whereKey($data['client_id'])->exists()) {
                throw ValidationException::withMessages(['client_id'=>'Client must belong to the selected company.']);
            }
        }
        if (!empty($data['service_job_id'])) {
            $job=ServiceJob::query()->where('company_id',$company)->whereKey($data['service_job_id'])
                ->whereNot('category','archived')->first();
            if (!$job || (!empty($data['client_id']) && $job->client_id !== (int)$data['client_id'])) {
                throw ValidationException::withMessages(['service_job_id'=>'Job and client must be in the selected company.']);
            }
            $data['client_id']=$job->client_id;
        }
        $items=[];
        foreach($data['items'] as $record) {
            $title=trim((string)($record['title']??''));
            if ($title==='') continue; // Blank extra rows in the admin editor.
            $price=trim((string)($record['unit_price']??''));
            $minor=null;
            if ($price!=='') {
                $split=explode('.',$price);
                if ((int)$split[0] > 10000000000) {
                    throw ValidationException::withMessages([
                        'items'=>'Unit price is above the supported limit.',
                    ]);
                }
                $minor=(int)$split[0]*100+(int)str_pad($split[1]??'',2,'0');
            }
            $items[]=[
                'position'=>count($items)+1,
                'title'=>$title,'description'=>trim((string)($record['description']??'')),
                'quantity'=>$record['quantity']??null,
                'dimensions'=>$record['dimensions']??null,
                'unit_price_minor'=>$minor,'other_fields'=>[],'unresolved_fields'=>[],
            ];
        }
        if (!$items) throw ValidationException::withMessages(['items'=>'Add a work item.']);
        $data['items']=$items;
        $data['client_name']=trim($data['client_name']);
        $data['terms']=trim((string)($data['terms']??''));
        $data['source_method']='manual';
        $subtotal=0;
        $complete=true;
        foreach($items as $item) {
            if ($item['quantity']===null || $item['unit_price_minor']===null) {
                $complete=false;break;
            }
            $subtotal+=(int)$item['quantity']*(int)$item['unit_price_minor'];
        }
        if ($subtotal>100000000000000) throw ValidationException::withMessages(['items'=>'Total too large.']);
        $vat=$complete?intdiv($subtotal*(int)$data['vat_basis_points']+5000,10000):null;
        $total=$complete?$subtotal+$vat:null;
        $data['subtotal_minor']=$complete?$subtotal:null;
        $data['vat_minor']=$vat;
        $data['total_minor']=$total;
        $data['deposit_minor']=$complete
            ?intdiv($total*(int)$data['deposit_basis_points']+5000,10000):null;
        return $data;
    }

    private function save(Request $request, ?ServiceQuote $quote): ServiceQuote
    {
        $data=$this->fields($request,$quote);
        $items=$data['items'];
        unset($data['items']);
        return DB::transaction(function () use ($request,$quote,$data,$items):ServiceQuote {
            if (!$quote) {
                $quote=ServiceQuote::create(array_merge($data,[
                    'created_by'=>$request->user()->id,
                    'updated_by'=>$request->user()->id,
                    'reference_number'=>'PENDING-'.bin2hex(random_bytes(8)),
                    'status'=>'draft','revision'=>1,
                ]));
                $quote->update(['reference_number'=>sprintf('Q-%d-%06d',now()->year,$quote->id)]);
            } else {
                abort_unless($quote->status==='draft',409);
                $quote->update(array_merge($data,['updated_by'=>$request->user()->id,
                    'revision'=>$quote->revision+1]));
                $quote->items()->delete();
            }
            foreach($items as $item) $quote->items()->create($item);
            $quote->revisions()->create([
                'revision'=>$quote->revision,
                'snapshot'=>$quote->fresh()->load('items')->toArray(),
                'actor_user_id'=>$request->user()->id,'created_at'=>now(),
            ]);
            AuditLogger::record('admin.quote.saved',$request->user(),$quote,
                Company::findOrFail($quote->company_id),
                metadata:['revision'=>$quote->revision],request:$request);
            return $quote;
        });
    }

    public function store(Request $request): RedirectResponse
    {
        $quote=$this->save($request,null);
        return redirect()->route('admin.quotes.edit',$quote)->with('success','Quote draft created.');
    }

    public function update(Request $request,int $quote): RedirectResponse
    {
        $target=ServiceQuote::findOrFail($quote);
        $this->save($request,$target);
        return back()->with('success','Quote draft saved with a new revision.');
    }

    public function approve(Request $request,int $quote): RedirectResponse
    {
        $target=ServiceQuote::with('items')->findOrFail($quote);
        abort_unless($target->status==='draft',409);
        if ($target->total_minor === null || !$target->client_name || !$target->terms ||
            $target->items->contains(fn($i)=>$i->quantity===null ||
                $i->unit_price_minor===null || ($i->dimensions!==null && trim($i->dimensions)===''))) {
            return back()->withErrors(['quote'=>'Complete client, prices and terms before approval.']);
        }
        DB::transaction(function () use ($target,$request):void {
            $target->update(['status'=>'approved','approved_at'=>now(),
                'revision'=>$target->revision+1,'updated_by'=>$request->user()->id]);
            $target->revisions()->create([
                'revision'=>$target->revision,'snapshot'=>$target->fresh()->load('items')->toArray(),
                'actor_user_id'=>$request->user()->id,'created_at'=>now(),
            ]);
            AuditLogger::record('admin.quote.approved',$request->user(),$target,
                Company::findOrFail($target->company_id),request:$request);
        });
        return back()->with('success','Quote approved.');
    }

    public function archive(Request $request,int $quote): RedirectResponse
    {
        $target=ServiceQuote::findOrFail($quote);
        abort_if($target->status==='archived',409);
        $target->update(['status_before_archive'=>$target->status,
            'status'=>'archived','archived_at'=>now(),
            'link_version'=>$target->link_version + 1]);
        AuditLogger::record('admin.quote.archived',$request->user(),$target,
            Company::findOrFail($target->company_id),request:$request);
        return redirect()->route('admin.quotes.index')->with('success','Quote archived.');
    }

    public function restore(Request $request,int $quote): RedirectResponse
    {
        $target=ServiceQuote::findOrFail($quote);
        abort_unless($target->status==='archived',409);
        $previous=$target->status_before_archive;
        $target->update(['status'=>in_array($previous,['draft','approved','sent'])?$previous:'draft',
            'archived_at'=>null,'status_before_archive'=>null]);
        AuditLogger::record('admin.quote.restored',$request->user(),$target,
            Company::findOrFail($target->company_id),request:$request);
        return redirect()->route('admin.quotes.edit',$target)->with('success','Quote restored.');
    }
}
