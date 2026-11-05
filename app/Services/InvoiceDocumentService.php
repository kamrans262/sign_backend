<?php

namespace App\Services;

use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M17.2: frozen, revision-bound invoice issuance. Never issue from Flutter
 * bytes or from unverified quote PDFs. No payment-gateway side effects.
 */
final class InvoiceDocumentService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    private function manager(Request $request, Company $company): void
    {
        $actor=$request->user();
        if ($actor?->is_platform_admin) return;
        $member=$actor?->id ? $company->users()->where('users.id',$actor->id)
            ->wherePivot('status','active')->first() : null;
        abort_unless(in_array($member?->pivot?->role,['owner','admin'],true),403);
    }

    private static function label(string $value, int $limit=200): string
    {
        $value=mb_substr(trim($value),0,$limit);
        $converted=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);
        return preg_replace('/[\x00-\x1F\x7F]/',' ',(string)$converted)??'';
    }

    private static function money(int $minor): string
    {
        return 'CHF '.number_format($minor/100,2,'.',"'");
    }

    private function snapshot(object $invoice, Company $company): array
    {
        $detail=$this->invoices->detail((int)$company->id,(int)$invoice->id);
        $record=$detail['invoice'];
        abort_unless((int)$record['total_minor']>0 && $invoice->currency==='CHF',409);
        $items=$detail['items'];
        $subtotal=0;
        foreach ($items as $item) {
            $computed=(int)$item['quantity']*(int)$item['unit_price_minor'];
            abort_unless($computed===(int)$item['total_minor'],409,'Invoice item reconciliation failed.');
            $subtotal+=$computed;
        }
        abort_unless($subtotal===(int)$record['subtotal_minor']
            && $subtotal+(int)$record['vat_minor']===(int)$record['total_minor'],409,
            'Invoice financial reconciliation failed.');
        $deposits=DB::table('service_invoice_deposit_allocations as a')
            ->join('job_deposits as d','d.id','=','a.job_deposit_id')
            ->where('a.company_id',$company->id)
            ->where('a.service_invoice_id',$invoice->id)
            ->whereColumn('a.company_id','d.company_id')
            ->where('d.status','received')->orderBy('a.id')
            ->get(['a.job_deposit_id','a.amount_minor'])->map(fn($r)=>[
                'deposit_id'=>(int)$r->job_deposit_id,'amount_minor'=>(int)$r->amount_minor,
            ])->all();
        abort_unless(array_sum(array_column($deposits,'amount_minor'))===
            (int)$record['deposit_paid_minor'],409,'Deposit reconciliation failed.');
        // Snapshot source references; source content remains protected by M7/M10.
        return [
            'version'=>1,'company_id'=>(int)$company->id,
            'company_name'=>$company->name,'company_address'=>$company->address??'',
            'company_vat'=>$company->vat_number??'',
            'invoice_id'=>(int)$invoice->id,'revision'=>(int)$invoice->revision,
            'number'=>$record['number'],'type'=>$record['type'],
            'job'=>$record['job'],'job_reference'=>$record['job_reference'],
            'client_name'=>$record['client_name'],
            'client_email'=>$record['client_email'],
            'billing_address'=>$record['billing_address'],
            'invoice_date'=>$record['invoice_date'],'due_date'=>$record['due_date'],
            'currency'=>'CHF','iban'=>$record['iban']?:($company->iban??''),
            'vat_basis_points'=>(int)$record['vat_basis_points'],
            'subtotal_minor'=>$subtotal,'vat_minor'=>(int)$record['vat_minor'],
            'total_minor'=>(int)$record['total_minor'],
            'deposit_paid_minor'=>(int)$record['deposit_paid_minor'],
            'received_minor'=>(int)$record['received_minor'],
            'amount_due_at_issue_minor'=>(int)$record['amount_due_minor'],
            'source_quote_id'=>$record['quote_id'],
            'source_addenda_ids'=>$record['source_addenda_ids'],
            'items'=>$items,'deposits'=>$deposits,
            'notes'=>$record['notes'],'terms'=>$record['terms'],
            'approved_at'=>$invoice->approved_at,
        ];
    }

    private function pdf(array $s): string
    {
        abort_unless(class_exists(\FPDF::class),503,'PDF rendering library unavailable.');
        $pdf=new \FPDF('P','mm','A4');
        $pdf->SetTitle(self::label('Sign2Digital Invoice '.$s['number']));
        $pdf->SetAuthor('Sign2Digital');
        $pdf->SetAutoPageBreak(true,22);
        $pdf->SetMargins(19,19,19);
        $pdf->AddPage();
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Arial','B',20);
        $pdf->Cell(172,12,'Invoice',0,1,'R');
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(172,6,self::label($s['number']),0,1,'R');
        $pdf->SetDrawColor(22,98,130);
        $pdf->SetLineWidth(.45);
        $pdf->Line(19,$pdf->GetY()+3,191,$pdf->GetY()+3);
        $pdf->Ln(12);
        $pdf->SetFont('Arial','B',12);
        $pdf->MultiCell(172,7,self::label($s['company_name']));
        $pdf->SetFont('Arial','',10);
        if($s['company_address'])$pdf->MultiCell(172,5,self::label($s['company_address'],450));
        if($s['company_vat'])$pdf->MultiCell(172,5,'VAT: '.self::label($s['company_vat']));
        $pdf->Ln(5);
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(86,6,'Bill to',0,0);
        $pdf->Cell(86,6,'Invoice information',0,1);
        $pdf->SetFont('Arial','',10);
        $y=$pdf->GetY();
        $pdf->MultiCell(86,6,self::label($s['client_name']));
        if($s['billing_address'])$pdf->MultiCell(86,5,self::label($s['billing_address'],400));
        $y2=$pdf->GetY();
        $pdf->SetXY(105,$y);
        $pdf->MultiCell(86,6,'Date: '.$s['invoice_date']."\nDue: ".$s['due_date'].
            "\nProject: ".self::label($s['job'],90));
        $pdf->SetY(max($y2,$pdf->GetY())+8);
        $pdf->SetFillColor(227,236,240);
        $pdf->SetFont('Arial','B',9);
        $pdf->Cell(98,9,'Description',0,0,'L',true);
        $pdf->Cell(20,9,'Qty',0,0,'R',true);
        $pdf->Cell(27,9,'Unit CHF',0,0,'R',true);
        $pdf->Cell(27,9,'Total CHF',0,1,'R',true);
        $pdf->SetFont('Arial','',9);
        foreach($s['items'] as $item){
            if($pdf->GetY()>254)$pdf->AddPage();
            $pdf->Cell(98,7,self::label($item['description'],115),0,0);
            $pdf->Cell(20,7,(string)$item['quantity'],0,0,'R');
            $pdf->Cell(27,7,number_format($item['unit_price_minor']/100,2,'.',"'"),0,0,'R');
            $pdf->Cell(27,7,number_format($item['total_minor']/100,2,'.',"'"),0,1,'R');
        }
        if($pdf->GetY()>222)$pdf->AddPage();
        $pdf->Ln(9);
        $lines=[
            'Subtotal'=>self::money($s['subtotal_minor']),
            'VAT ('.number_format($s['vat_basis_points']/100,2).'%)'=>self::money($s['vat_minor']),
            'Total'=>self::money($s['total_minor']),
            'Received deposits'=>self::money($s['deposit_paid_minor']),
            'Other payments at issue'=>self::money($s['received_minor']),
            'Outstanding at issue'=>self::money($s['amount_due_at_issue_minor']),
        ];
        foreach($lines as $name=>$value){
            $pdf->SetFont('Arial',$name==='Total'||$name==='Outstanding at issue'?'B':'',10);
            $pdf->Cell(125,6,$name,0,0,'R');
            $pdf->Cell(47,6,$value,0,1,'R');
        }
        $pdf->Ln(6);
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(172,6,'Payment instructions',0,1);
        $pdf->SetFont('Arial','',9);
        $pdf->MultiCell(172,5,'IBAN: '.self::label((string)$s['iban']));
        $pdf->MultiCell(172,5,'Reference: '.self::label($s['number']));
        // Never draw an unverified Swiss QR code or claim that one is valid.
        if($s['notes']){$pdf->Ln(3);$pdf->MultiCell(172,5,self::label($s['notes'],650));}
        if($s['terms']){$pdf->Ln(3);$pdf->MultiCell(172,5,self::label($s['terms'],650));}
        $bytes=$pdf->Output('S');
        abort_unless(is_string($bytes)&&str_starts_with($bytes,'%PDF-')
            &&str_contains(substr($bytes,-4096),'%%EOF'),503,
            'Issued invoice PDF generation failed.');
        return $bytes;
    }

    public function issue(Request $request, Company $company, int $invoice): array
    {
        $this->manager($request,$company);
        $request->validate(['confirm'=>['required','accepted']]);
        $newPath=null;
        try {
            $issue=DB::transaction(function()use($company,$request,$invoice,&$newPath){
                DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
                $row=DB::table('service_invoices')->where('company_id',$company->id)
                    ->where('id',$invoice)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($row->status,['approved','sent'],true),409,
                    'Only approved invoices can be issued.');
                $prior=DB::table('service_invoice_issues')
                    ->where('company_id',$company->id)
                    ->where('service_invoice_id',$invoice)
                    ->where('revision',$row->revision)->first();
                if($prior)return $prior;
                $snapshot=$this->snapshot($row,$company);
                $canonical=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $bytes=$this->pdf($snapshot);
                $sha=hash('sha256',$bytes);
                $newPath='invoices/'.$company->id.'/'.$invoice.'/issued/'.
                    $row->revision.'/'.Str::uuid().'_'.$sha.'.pdf';
                abort_unless(Storage::disk('local')->put($newPath,$bytes),503,
                    'Unable to store immutable invoice PDF.');
                $id=DB::table('service_invoice_issues')->insertGetId([
                    'company_id'=>$company->id,'service_invoice_id'=>$invoice,
                    'revision'=>$row->revision,'private_path'=>$newPath,
                    'pdf_sha256'=>$sha,'snapshot_sha256'=>hash('sha256',$canonical),
                    'byte_size'=>strlen($bytes),'snapshot'=>$canonical,
                    'issued_by'=>$request->user()?->id,'created_at'=>now(),
                ]);
                DB::table('service_invoice_events')->insert([
                    'company_id'=>$company->id,'service_invoice_id'=>$invoice,
                    'actor_user_id'=>$request->user()?->id,'event_type'=>'pdf_issued',
                    'metadata'=>json_encode(['sha256'=>$sha,'revision'=>$row->revision]),
                    'created_at'=>now(),
                ]);
                return DB::table('service_invoice_issues')->where('id',$id)->firstOrFail();
            });
        }catch(\Throwable $e){
            if($newPath)Storage::disk('local')->delete($newPath);
            throw $e;
        }
        // Replays must verify both immutable artifacts before reporting success.
        $this->verifiedSnapshot($issue);
        $this->bytes($issue);
        return $this->issuePayload($issue);
    }


    /** Verify the exact frozen source, independently of the PDF checksum. */
    private function verifiedSnapshot(object $issue): array
    {
        $canonical=(string)$issue->snapshot;
        abort_unless(hash_equals((string)$issue->snapshot_sha256,
            hash('sha256',$canonical)),409,'Issued invoice snapshot checksum mismatch.');
        $data=json_decode($canonical,true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($data)
            && (int)($data['invoice_id']??0)===(int)$issue->service_invoice_id
            && (int)($data['company_id']??0)===(int)$issue->company_id
            && (int)($data['revision']??0)===(int)$issue->revision,
            409,'Issued invoice snapshot identity mismatch.');
        return $data;
    }

    private function issueRow(int $company, int $invoice): object
    {
        $row=$this->invoices->find($company,$invoice);
        abort_unless(in_array($row->status,['approved','sent'],true),409,
            'Invoice has not been approved.');
        $issue=DB::table('service_invoice_issues')->where('company_id',$company)
            ->where('service_invoice_id',$invoice)->where('revision',$row->revision)
            ->firstOrFail();
        $this->verifiedSnapshot($issue);
        return $issue;
    }

    private function issuePayload(object $row): array
    {
        return ['invoice_id'=>(int)$row->service_invoice_id,
            'revision'=>(int)$row->revision,'sha256'=>$row->pdf_sha256,
            'byte_size'=>(int)$row->byte_size,'issued_at'=>$row->created_at,
            'filename'=>'Sign2Digital_Invoice_'.$row->service_invoice_id.'.pdf'];
    }

    public function document(int $company,int $invoice): array
    {
        return $this->issuePayload($this->issueRow($company,$invoice));
    }

    private function bytes(object $issue): string
    {
        $disk=Storage::disk('local');
        abort_unless($disk->exists($issue->private_path),503,
            'Issued invoice PDF unavailable.');
        $bytes=$disk->get($issue->private_path);
        abort_unless(is_string($bytes) && strlen($bytes)===(int)$issue->byte_size
            && hash_equals($issue->pdf_sha256,hash('sha256',$bytes)),409,
            'Issued invoice PDF integrity check failed.');
        return $bytes;
    }

    public function documentBytes(int $company,int $invoice): string
    {
        return $this->bytes($this->issueRow($company,$invoice));
    }

    private function linkFor(object $issue): array
    {
        $token=bin2hex(random_bytes(32));
        $expiry=now()->addDays(7);
        $id=DB::table('service_invoice_customer_links')->insertGetId([
            'company_id'=>$issue->company_id,'service_invoice_issue_id'=>$issue->id,
            'token_sha256'=>hash('sha256',$token),'expires_at'=>$expiry,
            'created_at'=>now(),
        ]);
        return ['link'=>route('invoices.customer',['token'=>$token]),
            'expires_at'=>$expiry->toIso8601String(),'id'=>$id];
    }

    public function createLink(Request $request, Company $company,int $invoice): array
    {
        $this->manager($request,$company);
        $issue=$this->issueRow($company->id,$invoice);
        $link=$this->linkFor($issue);
        DB::table('service_invoice_events')->insert([
            'company_id'=>$company->id,'service_invoice_id'=>$invoice,
            'actor_user_id'=>$request->user()?->id,'event_type'=>'customer_link_created',
            'metadata'=>json_encode(['issue_sha256'=>$issue->pdf_sha256,
                'expires_at'=>$link['expires_at']]),'created_at'=>now(),
        ]);
        return ['secure_link'=>$link['link'],'expires_at'=>$link['expires_at'],
            'pdf_sha256'=>$issue->pdf_sha256,'delivered'=>false];
    }

    private function customerIssue(string $token): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token)===1,404);
        $row=DB::table('service_invoice_customer_links as l')
            ->join('service_invoice_issues as d','d.id','=','l.service_invoice_issue_id')
            ->join('service_invoices as i','i.id','=','d.service_invoice_id')
            ->where('l.token_sha256',hash('sha256',$token))
            ->whereNull('l.revoked_at')->where('l.expires_at','>',now())
            ->whereColumn('l.company_id','d.company_id')
            ->whereColumn('d.company_id','i.company_id')
            ->whereIn('i.status',['approved','sent'])
            ->select('d.*','i.invoice_number')->first();
        abort_unless($row!==null,404);
        $this->verifiedSnapshot($row);
        return $row;
    }


    public function publicSummary(string $token): array
    {
        $issue=$this->customerIssue($token);
        $s=$this->verifiedSnapshot($issue);
        return ['number'=>$s['number'],'company_name'=>$s['company_name'],
            'client_name'=>$s['client_name'],'invoice_date'=>$s['invoice_date'],
            'due_date'=>$s['due_date'],'total_minor'=>$s['total_minor'],
            'amount_due_at_issue_minor'=>$s['amount_due_at_issue_minor']];
    }

    public function customerPdf(string $token): array
    {
        $row=$this->customerIssue($token);
        return ['bytes'=>$this->bytes($row),'filename'=>'Invoice_'.
            preg_replace('/[^A-Za-z0-9_-]/','_', $row->invoice_number).'.pdf'];
    }

    public function send(Request $request, Company $company,int $invoice): array
    {
        $this->manager($request,$company);
        $data=$request->validate([
            'recipient_email'=>['required','email','max:255'],
            'mode'=>['required',Rule::in(['link','pdf'])],
            'request_key'=>['required','string','between:8,100',
                'regex:/^[A-Za-z0-9._-]+$/D'],
            'message'=>['nullable','string','max:2000'],
        ]);
        if(in_array((string)config('mail.default'),['log','array','null'],true)){
            abort(503,'Invoice email delivery is not configured.');
        }
        $issue=$this->issueRow($company->id,$invoice);
        $previous=DB::table('service_invoice_deliveries')
            ->where('company_id',$company->id)->where('request_key',$data['request_key'])->first();
        if($previous){
            abort_unless((int)$previous->service_invoice_id===$invoice
                && $previous->recipient_email===$data['recipient_email']
                && $previous->mode===$data['mode'],409,'Delivery request key conflict.');
            abort_unless($previous->status==='sent',409,
                'Delivery outcome is not confirmed; reconcile before retrying.');
            return ['delivered'=>true,'pdf_attached'=>$previous->mode==='pdf',
                'pdf_sha256'=>$issue->pdf_sha256,'replayed'=>true];
        }
        $link=$this->linkFor($issue);
        try{
            $id=DB::table('service_invoice_deliveries')->insertGetId([
                'company_id'=>$company->id,'service_invoice_id'=>$invoice,
                'service_invoice_issue_id'=>$issue->id,'customer_link_id'=>$link['id'],
                'actor_user_id'=>$request->user()?->id,
                'recipient_email'=>$data['recipient_email'],'mode'=>$data['mode'],
                'request_key'=>$data['request_key'],'status'=>'pending',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }catch(\Throwable $e){
            DB::table('service_invoice_customer_links')->where('id',$link['id'])
                ->update(['revoked_at'=>now()]);
            throw $e;
        }
        $snapshot=$this->verifiedSnapshot($issue);
        $number=$snapshot['number'];
        $filename='Sign2Digital_Invoice_'.preg_replace('/[^A-Za-z0-9_-]/','_',$number).'.pdf';
        try{
            $attachment=$data['mode']==='pdf'?$this->bytes($issue):null;
            Mail::raw("Invoice ".$number."\n\n".($data['message']??'')."\n\n".
                "Review your issued invoice at this private link (expires in 7 days):\n".
                $link['link'],function($mail)use($data,$number,$attachment,$filename){
                    $mail->to($data['recipient_email'])
                        ->subject('Sign2Digital invoice '.$number);
                    if($attachment!==null)$mail->attachData($attachment,$filename,
                        ['mime'=>'application/pdf']);
                });
        }catch(\Throwable $error){
            report($error);
            DB::table('service_invoice_deliveries')->where('id',$id)
                ->update(['status'=>'failed','updated_at'=>now()]);
            DB::table('service_invoice_customer_links')->where('id',$link['id'])
                ->update(['revoked_at'=>now()]);
            abort(502,'Invoice delivery was not confirmed.');
        }
        DB::transaction(function()use($company,$invoice,$id,$request,$issue,$data):void{
            DB::table('service_invoice_deliveries')->where('id',$id)
                ->update(['status'=>'sent','sent_at'=>now(),'updated_at'=>now()]);
            DB::table('service_invoices')->where('id',$invoice)
                ->where('company_id',$company->id)->where('status','approved')
                ->update(['status'=>'sent','sent_at'=>now(),'updated_at'=>now()]);
            DB::table('service_invoice_events')->insert([
                'company_id'=>$company->id,'service_invoice_id'=>$invoice,
                'actor_user_id'=>$request->user()?->id,'event_type'=>'email_submitted',
                'metadata'=>json_encode(['issue_sha256'=>$issue->pdf_sha256,
                    'mode'=>$data['mode'],'delivery_id'=>$id]),'created_at'=>now(),
            ]);
        });
        AuditLogger::record('invoices.sent',$request->user(),null,$company,[
            'invoice_id'=>$invoice,'delivery_id'=>$id,'mode'=>$data['mode']],$request);
        return ['delivered'=>true,'pdf_attached'=>$data['mode']==='pdf',
            'pdf_sha256'=>$issue->pdf_sha256,'replayed'=>false];
    }

    public function reconciliation(int $company,int $invoice): array
    {
        $detail=$this->invoices->detail($company,$invoice);
        $record=$detail['invoice'];
        $total=(int)$record['total_minor'];
        $deposits=(int)$record['deposit_paid_minor'];
        $paid=(int)$record['received_minor'];
        $balance=max(0,$total-$deposits-$paid);
        abort_unless($balance===(int)$record['amount_due_minor']
            && $deposits+$paid<=$total,409,'Invoice ledger mismatch.');
        $issue=DB::table('service_invoice_issues')->where('company_id',$company)
            ->where('service_invoice_id',$invoice)->orderByDesc('revision')->first();
        return ['total_minor'=>$total,'deposits_minor'=>$deposits,
            'payments_minor'=>$paid,'outstanding_minor'=>$balance,
            'issued_pdf_sha256'=>$issue?->pdf_sha256,
            'snapshot_outstanding_minor'=>$issue?
                (int)($this->verifiedSnapshot($issue)['amount_due_at_issue_minor']):null,
            'status'=>$record['status']];
    }
}
