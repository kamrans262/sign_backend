<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceQuoteController extends Controller
{
    private function company(Request $request): Company
    {
        $company = $request->attributes->get('company');
        abort_unless($company instanceof Company, 403);
        return $company;
    }

    private function scoped(Request $request, int $id): ServiceQuote
    {
        return ServiceQuote::query()->where('company_id', $this->company($request)->id)
            ->with('items')->findOrFail($id);
    }

    private function payload(ServiceQuote $quote): array
    {
        $quote->loadMissing('items');
        return [
            'id' => $quote->id,
            'company_id' => $quote->company_id,
            'client_id' => $quote->client_id,
            'job_id' => $quote->service_job_id,
            'reference_number' => $quote->reference_number,
            'quote_number' => $quote->custom_quote_number ?: $quote->reference_number,
            'client_name' => $quote->client_name,
            'client_email' => $quote->client_email ?? '',
            'client_phone' => $quote->client_phone ?? '',
            'billing_address' => $quote->billing_address ?? '',
            'quote_date' => $quote->quote_date?->format('Y-m-d'),
            'valid_until' => $quote->valid_until?->format('Y-m-d'),
            'currency' => $quote->currency,
            'vat_basis_points' => $quote->vat_basis_points,
            'deposit_basis_points' => $quote->deposit_basis_points,
            'terms' => $quote->terms ?? '',
            'payment_details' => $quote->payment_details,
            'source_method' => $quote->source_method,
            'status' => $quote->status,
            'revision' => $quote->revision,
            'subtotal_minor' => $quote->subtotal_minor,
            'vat_minor' => $quote->vat_minor,
            'total_minor' => $quote->total_minor,
            'deposit_minor' => $quote->deposit_minor,
            'approved_at' => $quote->approved_at?->toIso8601String(),
            'sent_at' => $quote->sent_at?->toIso8601String(),
            'items' => $quote->items->map(fn ($item) => [
                'id' => $item->id,
                'position' => $item->position,
                'title' => $item->title,
                'description' => $item->description ?? '',
                'quantity' => $item->quantity,
                'dimensions' => $item->dimensions,
                'unit_price_minor' => $item->unit_price_minor,
                'other_fields' => $item->other_fields ?? [],
                'unresolved_fields' => $item->unresolved_fields ?? [],
            ])->values(),
        ];
    }

    private function fields(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['nullable','integer','min:1'],
            'job_id' => ['nullable','integer','min:1'],
            'quote_number' => ['nullable','string','max:40','regex:~^[A-Za-z0-9][A-Za-z0-9 _./-]*$~'],
            'client_name' => ['nullable','string','max:255'],
            'client_email' => ['nullable','email','max:255'],
            'client_phone' => ['nullable','string','max:60'],
            'billing_address' => ['nullable','string','max:4000'],
            'expected_revision' => ['sometimes','integer','min:1'],
            'quote_date' => ['required','date_format:Y-m-d'],
            'valid_until' => ['nullable','date_format:Y-m-d','after_or_equal:quote_date'],
            'currency' => ['required', Rule::in(['CHF','EUR','GBP','USD'])],
            'vat_basis_points' => ['required','integer','between:0,10000'],
            'deposit_basis_points' => ['required','integer','between:0,10000'],
            'terms' => ['nullable','string','max:20000'],
            'source_method' => ['nullable',Rule::in(['text','voice','handwrittenPhoto','manual'])],
            'payment_details' => ['nullable','array:iban,accountHolder,street,buildingNumber,postalCode,city,country,paymentReference'],
            'payment_details.*' => ['nullable','string','max:255'],
            'items' => ['required','array','min:1','max:25'],
            'items.*.title' => ['required','string','max:255'],
            'items.*.description' => ['nullable','string','max:5000'],
            'items.*.quantity' => ['nullable','integer','between:1,100000'],
            'items.*.dimensions' => ['nullable','string','max:255'],
            'items.*.unit_price_minor' => ['nullable','integer','between:0,1000000000000'],
            'items.*.other_fields' => ['nullable','array','max:25'],
            'items.*.unresolved_fields' => ['nullable','array','max:25'],
            'items.*.unresolved_fields.*' => ['string','max:100'],
        ]);
        $companyId = $this->company($request)->id;
        $customQuoteNumber = trim((string)($data['quote_number'] ?? ''));
        if ($customQuoteNumber !== '') {
            $duplicate = ServiceQuote::query()
                ->where('company_id',$companyId)
                ->where('custom_quote_number',$customQuoteNumber)
                ->when($request->route('quote'), fn($query) =>
                    $query->whereKeyNot((int)$request->route('quote')))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'quote_number'=>'This quote number is already used in your company.',
                ]);
            }
        }
        $data['custom_quote_number'] = $customQuoteNumber ?: null;
        unset($data['quote_number']);
        if (!empty($data['client_id'])) {
            $valid = Client::query()->where('company_id', $companyId)->whereKey($data['client_id'])->first();
            if (!$valid) throw ValidationException::withMessages(['client_id'=>'Choose a client in this company.']);
        }
        if (!empty($data['job_id'])) {
            $job = ServiceJob::query()->where('company_id', $companyId)->whereKey($data['job_id'])->first();
            if (!$job || $job->category === 'archived') {
                throw ValidationException::withMessages(['job_id'=>'Choose an active job in this company.']);
            }
            if (!empty($data['client_id']) && $job->client_id !== (int) $data['client_id']) {
                throw ValidationException::withMessages(['client_id'=>'Selected client must match the job.']);
            }
            $data['client_id'] = $job->client_id;
        }
        $data['client_name'] = trim((string)($data['client_name'] ?? ''));
        $data['terms'] = trim((string)($data['terms'] ?? ''));
        foreach ($data['items'] as &$item) {
            $item['title'] = trim($item['title']);
            $item['description'] = trim((string) ($item['description'] ?? ''));
            if ($item['title'] === '') {
                throw ValidationException::withMessages(['items'=>'Each work item requires a title.']);
            }
            $item['other_fields'] = $item['other_fields'] ?? [];
            $item['unresolved_fields'] = $item['unresolved_fields'] ?? [];
        }
        unset($item);
        return $data;
    }

    private function amounts(array $items, int $vatBp, int $depositBp): array
    {
        $sum = 0;
        foreach ($items as $item) {
            if (($item['quantity'] ?? null) === null || ($item['unit_price_minor'] ?? null) === null) {
                return ['subtotal_minor'=>null,'vat_minor'=>null,'total_minor'=>null,'deposit_minor'=>null];
            }
            $sum += (int)$item['quantity'] * (int)$item['unit_price_minor'];
            if ($sum > 100000000000000) {
                throw ValidationException::withMessages(['items'=>'Quote total is above the supported limit.']);
            }
        }
        $vat = intdiv($sum * $vatBp + 5000, 10000);
        $total = $sum + $vat;
        return [
            'subtotal_minor'=>$sum, 'vat_minor'=>$vat, 'total_minor'=>$total,
            'deposit_minor'=>intdiv($total * $depositBp + 5000, 10000),
        ];
    }

    private function persist(Request $request, ?ServiceQuote $quote): ServiceQuote
    {
        $data = $this->fields($request);
        $company = $this->company($request);
        $items = $data['items'];
        unset($data['items']);
        $expectedRevision = $data['expected_revision'] ?? null;
        unset($data['expected_revision']);
        $data['service_job_id'] = $data['job_id'] ?? null;
        unset($data['job_id']);
        $data = array_merge($data, $this->amounts($items,
            (int)$data['vat_basis_points'], (int)$data['deposit_basis_points']));
        return DB::transaction(function () use ($request,$quote,$data,$items,$company,$expectedRevision): ServiceQuote {
            if ($quote === null) {
                $quote = ServiceQuote::create(array_merge($data, [
                    'company_id'=>$company->id,
                    'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
                    'reference_number'=>'PENDING-'.bin2hex(random_bytes(8)),
                    'status'=>'draft','revision'=>1,
                ]));
                $quote->update(['reference_number'=>sprintf('Q-%d-%06d', now()->year, $quote->id)]);
            } else {
                // Lock and compare the latest revision inside this transaction;
                // two devices must never silently overwrite one another.
                $quote = ServiceQuote::query()
                    ->where('company_id',$company->id)
                    ->lockForUpdate()->findOrFail($quote->id);
                if ($expectedRevision !== null &&
                    (int)$quote->revision !== (int)$expectedRevision) {
                    abort(409, 'Quote was updated elsewhere. Reload before editing.');
                }
                abort_if($quote->status !== 'draft', 409,
                    'Approved, sent or archived quotes are immutable. Create a new draft.');
                // Immutable company, original author and reference remain server-owned.
                $quote->update(array_merge($data, [
                    'updated_by'=>$request->user()->id,'revision'=>$quote->revision + 1,
                ]));
                $quote->items()->delete();
            }
            foreach ($items as $index=>$item) {
                $quote->items()->create(array_merge($item, ['position'=>$index+1]));
            }
            $snapshot = $this->payload($quote->fresh()->load('items'));
            $quote->revisions()->create([
                'revision'=>$quote->revision,'snapshot'=>$snapshot,
                'actor_user_id'=>$request->user()->id,'created_at'=>now(),
            ]);
            AuditLogger::record($quote->revision === 1
                ? 'mobile.quote.created' : 'mobile.quote.updated',
                $request->user(),$quote,$company,
                metadata:['revision'=>$quote->revision],request:$request);
            return $quote->fresh()->load('items');
        });
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q'=>['nullable','string','max:255'],
            'status'=>['nullable',Rule::in(['draft','approved','sent','archived'])],
            'client_id'=>['nullable','integer','min:1'],
            'job_id'=>['nullable','integer','min:1'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','min:1','max:100'],
        ]);
        $query = ServiceQuote::query()->where('company_id', $this->company($request)->id)
            ->with('items')->latest('id');
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['client_id'])) $query->where('client_id',$filters['client_id']);
        if (!empty($filters['job_id'])) $query->where('service_job_id',$filters['job_id']);
        if (!empty($filters['q'])) {
            $q = trim($filters['q']);
            $query->where(fn($builder) => $builder
                ->where('reference_number','like','%'.$q.'%')
                ->orWhere('client_name','like','%'.$q.'%'));
        }
        $result = $query->paginate((int)($filters['per_page'] ?? 50));
        return response()->json(['data'=>[
            'quotes'=>$result->getCollection()->map(fn($q)=>$this->payload($q))->values(),
            'pagination'=>['page'=>$result->currentPage(),
                'last_page'=>$result->lastPage(),'total'=>$result->total()],
        ]]);
    }

    public function show(Request $request, int $quote): JsonResponse
    {
        return response()->json(['data'=>['quote'=>$this->payload($this->scoped($request,$quote))]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data'=>['quote'=>$this->payload($this->persist($request,null))]], 201);
    }

    public function update(Request $request, int $quote): JsonResponse
    {
        return response()->json(['data'=>['quote'=>$this->payload(
            $this->persist($request,$this->scoped($request,$quote))
        )]]);
    }

    public function approve(Request $request, int $quote): JsonResponse
    {
        $request->validate(['confirmed'=>['required','accepted']]);
        $item = $this->scoped($request,$quote);
        abort_if($item->status !== 'draft',409,'Only a draft can be approved.');
        if ($item->client_name === '' || trim((string)$item->terms) === '' ||
            $item->items->isEmpty() || $item->total_minor === null) {
            throw ValidationException::withMessages(['quote'=>'Complete client, terms and every price before approval.']);
        }
        foreach ($item->items as $row) {
            if ($row->quantity === null || $row->unit_price_minor === null ||
                $row->title === '' ||
                ($row->dimensions !== null && trim($row->dimensions) === '') ||
                !empty($row->unresolved_fields)) {
                throw ValidationException::withMessages(['items'=>'Resolve every field before approval.']);
            }
        }
        DB::transaction(function () use ($request,$item):void {
            $item->update(['status'=>'approved','approved_at'=>now(),
                'updated_by'=>$request->user()->id,'revision'=>$item->revision+1]);
            $item->revisions()->create(['revision'=>$item->revision,
                'snapshot'=>$this->payload($item->fresh()->load('items')),
                'actor_user_id'=>$request->user()->id,'created_at'=>now()]);
            AuditLogger::record('mobile.quote.approved',$request->user(),$item,
                $this->company($request),request:$request);
        });
        return response()->json(['data'=>['quote'=>$this->payload($item->fresh()->load('items'))]]);
    }

    public function archive(Request $request, int $quote): JsonResponse
    {
        $item = $this->scoped($request,$quote);
        abort_if($item->status === 'archived',409);
        $item->update(['status_before_archive'=>$item->status,
            'status'=>'archived','archived_at'=>now(),
            'link_version'=>$item->link_version + 1,
            'updated_by'=>$request->user()->id]);
        AuditLogger::record('mobile.quote.archived',$request->user(),$item,
            $this->company($request),request:$request);
        return response()->json(['message'=>'Quote archived.']);
    }

    public function share(Request $request, int $quote): JsonResponse
    {
        $data = $request->validate([
            'method'=>['required',Rule::in(['email','secure_link'])],
            'recipient_email'=>['nullable','required_if:method,email','email','max:255'],
            'message'=>['nullable','string','max:2000'],
        ]);
        $item = $this->scoped($request,$quote);
        abort_unless(in_array($item->status,['approved','sent'],true),409,
            'Approve the quote before sharing it.');
        // Signed link expires after 7 days; no public raw ID enumeration.
        $link = URL::temporarySignedRoute('quotes.public',
            now()->addDays(7), ['quote'=>$item->id,'v'=>$item->link_version]);
        if ($data['method'] === 'secure_link') {
            AuditLogger::record('mobile.quote.link_generated',$request->user(),$item,
                $this->company($request),request:$request);
            return response()->json(['data'=>['secure_link'=>$link,
                'expires_at'=>now()->addDays(7)->toIso8601String(),
                'delivered'=>false]]);
        }
        if (in_array((string) config('mail.default'), ['log','array'], true)) {
            return response()->json(['message'=>'Email delivery is not configured.'],503);
        }
        try {
            Mail::raw("Quote ".($item->custom_quote_number ?: $item->reference_number)."\n\n".($data['message'] ?? '')."\n\n".
                "Review your approved quote using this secure link (valid 7 days):\n".$link,
                fn($mail)=>$mail->to($data['recipient_email'])->subject(
                    'Sign2Digital quote '.($item->custom_quote_number ?: $item->reference_number)));
        } catch (\Throwable $error) {
            report($error);
            return response()->json(['message'=>'Unable to send the quote email.'],502);
        }
        $item->update(['status'=>'sent','sent_at'=>now(),'updated_by'=>$request->user()->id]);
        AuditLogger::record('mobile.quote.sent',$request->user(),$item,
            $this->company($request),metadata:['method'=>'email'],request:$request);
        return response()->json(['data'=>['delivered'=>true]]);
    }

    public function shareEmailPdf(Request $request, int $quote): JsonResponse
    {
        $data = $request->validate([
            'recipient_email'=>['required','email','max:255'],
            'message'=>['nullable','string','max:2000'],
            'file'=>['required','file','max:5120'],
        ]);
        $item = $this->scoped($request, $quote);
        abort_unless(in_array($item->status,['approved','sent'],true),409,
            'Approve the quote before emailing a PDF.');
        $uploaded = $request->file('file');
        if (!$uploaded || !$uploaded->isValid()) {
            throw ValidationException::withMessages(['file'=>'The selected PDF cannot be read.']);
        }
        $bytes = file_get_contents($uploaded->getRealPath());
        // Do not attach arbitrary files merely renamed with a .pdf suffix.
        // Accept only a bounded, recognizable PDF; no web-root persistence.
        if (!is_string($bytes) || strlen($bytes)<12 ||
            strlen($bytes)>5*1024*1024 ||
            !str_starts_with($bytes,'%PDF-') ||
            !str_contains(substr($bytes,-4096),'%%EOF')) {
            throw ValidationException::withMessages(['file'=>'Upload a valid PDF smaller than 5 MB.']);
        }
        if (in_array((string) config('mail.default'),['log','array','null'],true)) {
            return response()->json(['message'=>'Email delivery is not configured.'],503);
        }
        $link = URL::temporarySignedRoute('quotes.public',
            now()->addDays(7),['quote'=>$item->id,'v'=>$item->link_version]);
        $number = $item->custom_quote_number ?: $item->reference_number;
        $filename = 'Sign2Digital_Quote_'.preg_replace('/[^A-Za-z0-9_-]/','_', $number).'.pdf';
        try {
            Mail::raw("Quote {$number}\n\n".($data['message']??'')."\n\n".
                "The reviewed quote PDF is attached. Confirm the same approved ".
                "information through this secure seven-day link:\n".$link,
                function ($mail) use ($data,$number,$bytes,$filename): void {
                    $mail->to($data['recipient_email'])
                        ->subject('Sign2Digital quote '.$number)
                        ->attachData($bytes,$filename,['mime'=>'application/pdf']);
                });
        } catch (\Throwable $error) {
            report($error);
            return response()->json(['message'=>'Unable to send the quote PDF email.'],502);
        }
        $item->update(['status'=>'sent','sent_at'=>now(),'updated_by'=>$request->user()->id]);
        AuditLogger::record('mobile.quote.sent',$request->user(),$item,
            $this->company($request),
            metadata:['method'=>'email','pdf_attachment'=>true],request:$request);
        return response()->json(['data'=>[
            'delivered'=>true, 'pdf_attached'=>true,
        ]]);
    }

    public function revisions(Request $request,int $quote): JsonResponse
    {
        $item=$this->scoped($request,$quote);
        return response()->json(['data'=>['revisions'=>$item->revisions()
            ->get(['revision','snapshot','created_at'])->toArray()]]);
    }

    public function publicShow(Request $request, int $quote)
    {
        $item=ServiceQuote::query()->with(['items','company:id,name,address,email,phone_number,vat_number'])->findOrFail($quote);
        abort_unless(in_array($item->status,['approved','sent'],true),404);
        // Archive invalidates all previously issued URLs, including after restore.
        abort_unless((string)$request->query('v') === (string)$item->link_version,404);
        if (!$request->expectsJson()) {
            return response()->view('public.quote',['quote'=>$item],200,
                ['Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex']);
        }
        $data=$this->payload($item);
        unset($data['company_id'],$data['client_id'],$data['job_id'],
            $data['payment_details']);
        return response()->json(['data'=>['quote'=>$data]],200,
            ['Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex']);
    }
}
