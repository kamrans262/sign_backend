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

/**
 * M18.2: company-isolated, revision-bound general-document issue and signature.
 * Unlike quote signatures, these requests reference general-document issues.
 * No customer document is created from mobile-side PDF bytes.
 */
final class GeneralDocumentIssueService
{
    public function __construct(
        private readonly GeneralDocumentService $documents,
        private readonly SignedPdfService $signedPdf,
    ) {}

    private function manager(Request $request,Company $company): void
    {
        $user=$request->user();
        abort_unless($user!==null,401);
        if($user->is_platform_admin)return;
        $member=$company->users()->where('users.id',$user->id)
            ->wherePivot('status','active')->first();
        abort_unless(in_array($member?->pivot?->role,['owner','admin'],true),403);
    }

    private function event(int $company,int $document,?int $actor,
        string $name,array $metadata=[]): void
    {
        DB::table('general_document_events')->insert([
            'company_id'=>$company,'general_document_id'=>$document,
            'actor_user_id'=>$actor,'event_type'=>$name,
            'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    private static function label(string $text,int $limit=1000): string
    {
        $text=mb_substr(trim($text),0,$limit);
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
        return preg_replace('/[\x00-\x1F\x7F]/',' ',(string)$encoded)??'';
    }

    private function source(object $doc,Company $company): array
    {
        abort_unless($doc->status==='approved'
            && (int)$doc->approved_revision===(int)$doc->current_revision,409,
            'Only a frozen approved general document can be issued.');
        $revision=DB::table('general_document_revisions')
            ->where('company_id',$company->id)->where('general_document_id',$doc->id)
            ->where('revision',$doc->approved_revision)->firstOrFail();
        abort_unless(hash_equals($revision->content_sha256,
            hash('sha256',$revision->content_json)),409,
            'Approved document revision integrity failed.');
        $blocks=json_decode($revision->content_json,true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($blocks)&&count($blocks)>0,409);
        $sanitized=GeneralDocumentService::blocks($blocks);
        abort_unless(json_encode($sanitized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES)===$revision->content_json,409,
            'Approved revision differs from the canonical document.');
        $client=$doc->client_id===null?null:DB::table('clients')
            ->where('company_id',$company->id)->where('id',$doc->client_id)->firstOrFail();
        $job=$doc->service_job_id===null?null:DB::table('service_jobs')
            ->where('company_id',$company->id)->where('id',$doc->service_job_id)->firstOrFail();
        $attachmentRows=DB::table('general_document_attachments')
            ->where('company_id',$company->id)->where('general_document_id',$doc->id)
            ->whereNull('removed_at')->orderBy('id')->get();
        $attachments=[];
        foreach($attachmentRows as $attachment){
            // A referenced attachment must still be available and checksum-clean.
            $this->documents->attachmentBytes($company->id,$doc->id,$attachment->id);
            $attachments[]=['id'=>(int)$attachment->id,'name'=>$attachment->original_name,
                'sha256'=>$attachment->sha256,'byte_size'=>(int)$attachment->byte_size];
        }
        return [
            'kind'=>'general','version'=>1,'company_id'=>(int)$company->id,
            'company_name'=>$company->name,'company_address'=>$company->address??'',
            'company_vat'=>$company->vat_number??'',
            'document_id'=>(int)$doc->id,'number'=>$doc->number,
            'type'=>$doc->document_type,'title'=>$doc->title,'locale'=>$doc->locale,
            'revision'=>(int)$revision->revision,'revision_sha256'=>$revision->content_sha256,
            'approved_at'=>$doc->approved_at,
            'client_id'=>$client?->id,'client_name'=>$client?->full_name??'',
            'job_id'=>$job?->id,'job_title'=>$job?->title??'',
            'blocks'=>$sanitized,'attachments'=>$attachments,
        ];
    }

    private function render(array $snapshot): string
    {
        abort_unless(class_exists(\FPDF::class),503,'PDF renderer unavailable.');
        $pdf=new \FPDF('P','mm','A4');
        $pdf->SetTitle(self::label('Sign2Digital '.$snapshot['title'],200));
        $pdf->SetAuthor('Sign2Digital');
        $pdf->SetMargins(20,20,20);
        $pdf->SetAutoPageBreak(true,20);
        $pdf->AddPage();
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Arial','B',20);
        $pdf->MultiCell(170,10,self::label($snapshot['title'],255));
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(102,112,133);
        $pdf->Cell(170,6,self::label($snapshot['number']),0,1);
        $pdf->SetDrawColor(22,98,130);
        $pdf->Line(20,$pdf->GetY()+2,190,$pdf->GetY()+2);
        $pdf->Ln(9);
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Arial','B',10);
        $pdf->MultiCell(170,6,self::label($snapshot['company_name'],255));
        $pdf->SetFont('Arial','',9);
        if($snapshot['company_address'])$pdf->MultiCell(170,5,
            self::label($snapshot['company_address'],500));
        if($snapshot['company_vat'])$pdf->MultiCell(170,5,
            'VAT: '.self::label($snapshot['company_vat']));
        if($snapshot['client_name'])$pdf->MultiCell(170,6,
            'To: '.self::label($snapshot['client_name'],255));
        if($snapshot['job_title'])$pdf->MultiCell(170,6,
            'Project: '.self::label($snapshot['job_title'],255));
        $pdf->Ln(8);
        foreach($snapshot['blocks'] as $block){
            $heading=$block['type']==='heading';
            $bold=$heading||$block['bold'];
            $italic=$block['italic'];
            $pdf->SetFont('Arial',($bold?'B':'').($italic?'I':''),$heading?12:10);
            $prefix=match($block['type']){
                'bullet'=>'- ','numbered'=>'- ',default=>'',
            };
            // FPDF's MultiCell wraps and paginates long paragraphs.
            $pdf->MultiCell(170,$heading?8:6,$prefix.self::label($block['text'],4000));
            $pdf->Ln($heading?3:4);
        }
        if($snapshot['attachments']){
            $pdf->Ln(5);$pdf->SetFont('Arial','B',10);
            $pdf->MultiCell(170,6,'Related attachments (stored separately)');
            $pdf->SetFont('Arial','',9);
            foreach($snapshot['attachments'] as $file){
                $pdf->MultiCell(170,5,self::label($file['name'],90).
                    ' / SHA-256: '.$file['sha256']);
            }
        }
        $pdf->Ln(6);
        $pdf->SetFont('Arial','I',8);
        $pdf->MultiCell(170,5,'Approved source revision '.$snapshot['revision'].
            '. An electronic signature, when supplied, is recorded in a separate signed PDF.');
        $bytes=$pdf->Output('S');
        abort_unless(is_string($bytes)&&strlen($bytes)<=5*1024*1024
            && str_starts_with($bytes,'%PDF-')
            && str_contains(substr($bytes,-4096),'%%EOF'),503,
            'Official general document PDF could not be verified.');
        return $bytes;
    }

    private function issueRow(int $company,int $document): object
    {
        $doc=$this->documents->find($company,$document);
        abort_unless($doc->status==='approved'
            && (int)$doc->current_revision===(int)$doc->approved_revision,409,
            'Document is not approved.');
        $issue=DB::table('general_document_issues')
            ->where('company_id',$company)->where('general_document_id',$document)
            ->where('approved_revision',$doc->approved_revision)->firstOrFail();
        $this->verifySnapshot($issue);
        return $issue;
    }

    private function verifySnapshot(object $issue): array
    {
        $json=(string)$issue->snapshot;
        abort_unless(hash_equals((string)$issue->snapshot_sha256,
            hash('sha256',$json)),409,'Issued document snapshot integrity failed.');
        $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($data)
            && ($data['kind']??'')==='general'
            && (int)($data['company_id']??0)===(int)$issue->company_id
            && (int)($data['document_id']??0)===(int)$issue->general_document_id
            && (int)($data['revision']??0)===(int)$issue->approved_revision
            && hash_equals((string)$issue->revision_sha256,
                (string)($data['revision_sha256']??'')),409,
            'Issued document identity validation failed.');
        return $data;
    }

    private function bytes(object $issue): string
    {
        $this->verifySnapshot($issue);
        $disk=Storage::disk('local');
        abort_unless($disk->exists($issue->private_path),503,'Official PDF unavailable.');
        $bytes=$disk->get($issue->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$issue->byte_size
            && hash_equals($issue->pdf_sha256,hash('sha256',$bytes))
            && str_starts_with($bytes,'%PDF-')
            && str_contains(substr($bytes,-4096),'%%EOF'),409,
            'Official PDF integrity check failed.');
        return $bytes;
    }

    public function issuedPdf(int $company,int $document): string
    {
        return $this->bytes($this->issueRow($company,$document));
    }

    private function issuePayload(object $issue): array
    {
        return ['document_id'=>(int)$issue->general_document_id,
            'revision'=>(int)$issue->approved_revision,'sha256'=>$issue->pdf_sha256,
            'byte_size'=>(int)$issue->byte_size,
            'issued_at'=>$issue->created_at,
            'filename'=>'Sign2Digital_Document_'.$issue->general_document_id.'.pdf'];
    }

    public function metadata(int $company,int $document): array
    {
        $issue=$this->issueRow($company,$document);
        $this->bytes($issue);
        $requests=DB::table('general_document_signature_requests')
            ->where('company_id',$company)->where('general_document_issue_id',$issue->id)
            ->orderByDesc('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'signer_name'=>$r->signer_name,
                'signer_email'=>$r->signer_email,'status'=>$r->status,
                'expires_at'=>$r->expires_at,'revoked_at'=>$r->revoked_at,
                'signed_pdf_sha256'=>$r->signed_pdf_sha256,
                'signed_at'=>$r->signed_at])->all();
        $deliveries=DB::table('general_document_deliveries')
            ->where('company_id',$company)->where('general_document_issue_id',$issue->id)
            ->orderByDesc('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'recipient_email'=>$r->recipient_email,
                'mode'=>$r->mode,'status'=>$r->status,'sent_at'=>$r->sent_at])->all();
        $links=DB::table('general_document_customer_links')
            ->where('company_id',$company)->where('general_document_issue_id',$issue->id)
            ->orderByDesc('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'expires_at'=>$r->expires_at,
                'revoked_at'=>$r->revoked_at])->all();
        return ['issue'=>$this->issuePayload($issue),'signatures'=>$requests,
            'deliveries'=>$deliveries,'links'=>$links];
    }

    public function issue(Request $request,Company $company,int $document): array
    {
        $this->manager($request,$company);
        $request->validate(['confirm'=>['required','accepted']]);
        $createdPath=null;
        try {
            $issue=DB::transaction(function()use($company,$document,$request,&$createdPath){
                DB::table('companies')->where('id',$company->id)
                    ->lockForUpdate()->firstOrFail();
                $doc=DB::table('general_documents')
                    ->where('company_id',$company->id)->where('id',$document)
                    ->lockForUpdate()->firstOrFail();
                abort_unless($doc->status==='approved' && $doc->approved_revision!==null
                    && (int)$doc->approved_revision===(int)$doc->current_revision,409,
                    'Review and approve the document before issuing.');
                $prior=DB::table('general_document_issues')
                    ->where('company_id',$company->id)
                    ->where('general_document_id',$document)
                    ->where('approved_revision',$doc->approved_revision)->first();
                if($prior)return $prior;
                $snapshot=$this->source($doc,$company);
                $json=json_encode($snapshot,JSON_THROW_ON_ERROR|
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $bytes=$this->render($snapshot);
                $digest=hash('sha256',$bytes);
                $createdPath='general-documents/issued/'.$company->id.'/'.$document.
                    '/'.$doc->approved_revision.'/'.Str::uuid().'_'.$digest.'.pdf';
                abort_unless(Storage::disk('local')->put($createdPath,$bytes),503,
                    'Official document storage failed.');
                $id=DB::table('general_document_issues')->insertGetId([
                    'company_id'=>$company->id,'general_document_id'=>$document,
                    'approved_revision'=>$doc->approved_revision,
                    'revision_sha256'=>$snapshot['revision_sha256'],
                    'snapshot'=>$json,'snapshot_sha256'=>hash('sha256',$json),
                    'private_path'=>$createdPath,'pdf_sha256'=>$digest,
                    'byte_size'=>strlen($bytes),'issued_by'=>$request->user()?->id,
                    'created_at'=>now(),
                ]);
                $this->event($company->id,$document,$request->user()?->id,
                    'official_pdf_issued',['revision'=>$doc->approved_revision,
                        'pdf_sha256'=>$digest]);
                return DB::table('general_document_issues')->where('id',$id)->firstOrFail();
            });
        }catch(\Throwable $e){
            if($createdPath!==null)Storage::disk('local')->delete($createdPath);
            throw $e;
        }
        $this->bytes($issue); // Always verify idempotent replay, too.
        AuditLogger::record('general_documents.issued',$request->user(),null,
            $company,['document_id'=>$document],$request);
        return $this->issuePayload($issue);
    }

    private function createLink(object $issue): array
    {
        $token=bin2hex(random_bytes(32));
        $expiry=now()->addDays(7);
        $id=DB::table('general_document_customer_links')->insertGetId([
            'company_id'=>$issue->company_id,'general_document_issue_id'=>$issue->id,
            'token_sha256'=>hash('sha256',$token),
            'expires_at'=>$expiry,'created_at'=>now(),
        ]);
        return ['id'=>$id,'secure_link'=>route('general-document.public.show',['token'=>$token]),
            'expires_at'=>$expiry->toIso8601String()];
    }

    public function link(Request $request,Company $company,int $document): array
    {
        $this->manager($request,$company);
        $issue=$this->issueRow($company->id,$document);
        $this->bytes($issue);
        $link=$this->createLink($issue);
        $this->event($company->id,$document,$request->user()?->id,
            'customer_link_created',['link_id'=>$link['id'],
                'expires_at'=>$link['expires_at']]);
        return [...$link,'sha256'=>$issue->pdf_sha256,'delivered'=>false];
    }

    public function revokeLink(Request $request,Company $company,
        int $document,int $link): array
    {
        $this->manager($request,$company);
        $issue=$this->issueRow($company->id,$document);
        $changed=DB::table('general_document_customer_links')
            ->where('company_id',$company->id)
            ->where('general_document_issue_id',$issue->id)
            ->where('id',$link)->whereNull('revoked_at')
            ->update(['revoked_at'=>now()]);
        abort_unless($changed===1,404);
        $this->event($company->id,$document,$request->user()?->id,
            'customer_link_revoked',['link_id'=>$link]);
        return ['revoked'=>true,'id'=>$link];
    }

    private function byLink(string $token): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token)===1,404);
        $issue=DB::table('general_document_customer_links as l')
            ->join('general_document_issues as i','i.id','=','l.general_document_issue_id')
            ->join('general_documents as d','d.id','=','i.general_document_id')
            ->join('companies as c','c.id','=','i.company_id')
            ->where('l.token_sha256',hash('sha256',$token))
            ->whereNull('l.revoked_at')->where('l.expires_at','>',now())
            ->where('c.status','active')->where('d.status','approved')
            ->whereColumn('l.company_id','i.company_id')
            ->whereColumn('d.company_id','i.company_id')
            ->whereColumn('d.current_revision','i.approved_revision')
            ->select('i.*')->firstOrFail();
        $this->bytes($issue);
        return $issue;
    }

    public function publicDocument(string $token): array
    {
        $s=$this->verifySnapshot($this->byLink($token));
        return ['number'=>$s['number'],'title'=>$s['title'],
            'company_name'=>$s['company_name'],'client_name'=>$s['client_name'],
            'type'=>$s['type'],'revision'=>$s['revision']];
    }

    public function publicPdf(string $token): array
    {
        $issue=$this->byLink($token);
        return ['bytes'=>$this->bytes($issue),
            'filename'=>'General_Document_'.$issue->general_document_id.'.pdf'];
    }

    public function requestSignature(Request $request,Company $company,int $document): array
    {
        $this->manager($request,$company);
        $data=$request->validate([
            'signer_name'=>['required','string','between:2,255'],
            'signer_email'=>['required','email','max:255'],
            'confirm'=>['required','accepted'],
        ]);
        $issue=$this->issueRow($company->id,$document);
        $this->bytes($issue);
        $token=bin2hex(random_bytes(32));
        $uuid=(string)Str::uuid();
        $documentUuid=(string)Str::uuid();
        $consent=hash('sha256',$issue->pdf_sha256."\n".$uuid."\n".$documentUuid);
        $expiry=now()->addDays(7);
        $id=DB::table('general_document_signature_requests')->insertGetId([
            'company_id'=>$company->id,'general_document_issue_id'=>$issue->id,
            'token_sha256'=>hash('sha256',$token),'request_uuid'=>$uuid,
            'document_uuid'=>$documentUuid,'consent_reference'=>$consent,
            'signer_name'=>trim($data['signer_name']),
            'signer_email'=>mb_strtolower(trim($data['signer_email'])),
            'status'=>'ready','expires_at'=>$expiry,
            'created_by'=>$request->user()?->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->event($company->id,$document,$request->user()?->id,
            'signature_requested',['signature_request_id'=>$id,
                'pdf_sha256'=>$issue->pdf_sha256]);
        return ['id'=>$id,'signature_link'=>route('general-document.sign.show',
                ['token'=>$token]),'expires_at'=>$expiry->toIso8601String(),
            'request_uuid'=>$uuid,'document_uuid'=>$documentUuid,
            'signer_name'=>$data['signer_name'],'status'=>'ready'];
    }

    public function revokeSignature(Request $request,Company $company,int $document,
        int $signature): array
    {
        $this->manager($request,$company);
        $issue=$this->issueRow($company->id,$document);
        $changed=DB::table('general_document_signature_requests')
            ->where('company_id',$company->id)->where('general_document_issue_id',$issue->id)
            ->where('id',$signature)->whereIn('status',['ready','viewed'])
            ->whereNull('revoked_at')->update(['revoked_at'=>now(),
                'status'=>'revoked','updated_at'=>now()]);
        abort_unless($changed===1,409,'Signature request cannot be revoked.');
        $this->event($company->id,$document,$request->user()?->id,
            'signature_request_revoked',['signature_request_id'=>$signature]);
        return ['revoked'=>true,'id'=>$signature];
    }

    private function signedBytes(object $signing): string
    {
        abort_unless($signing->signed_pdf_path!==null
            && $signing->signed_pdf_sha256!==null,409,
            'This general document has not been signed.');
        $disk=Storage::disk('local');
        abort_unless($disk->exists($signing->signed_pdf_path),503);
        $bytes=$disk->get($signing->signed_pdf_path);
        abort_unless(is_string($bytes)&&str_starts_with($bytes,'%PDF-')
            && str_contains(substr($bytes,-4096),'%%EOF')
            && hash_equals($signing->signed_pdf_sha256,hash('sha256',$bytes)),409,
            'Signed PDF integrity check failed.');
        return $bytes;
    }

    private function signing(string $token,bool $requireActive=true): array
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$token)===1,404);
        $item=DB::table('general_document_signature_requests')
            ->where('token_sha256',hash('sha256',$token))->firstOrFail();
        $issue=DB::table('general_document_issues as i')
            ->join('general_documents as d','d.id','=','i.general_document_id')
            ->join('companies as c','c.id','=','i.company_id')
            ->where('i.id',$item->general_document_issue_id)
            ->where('i.company_id',$item->company_id)
            ->whereColumn('d.company_id','i.company_id')
            ->whereColumn('d.current_revision','i.approved_revision')
            ->where('d.status','approved')->where('c.status','active')
            ->select('i.*')->firstOrFail();
        abort_unless($item->revoked_at===null
            && \Illuminate\Support\Carbon::parse($item->expires_at)->isFuture(),410,
            'The signing link is no longer active.');
        if($requireActive)abort_unless(in_array($item->status,['ready','viewed'],true),
            409,'This signature request has already been completed.');
        $this->bytes($issue);
        return [$item,$issue];
    }

    public function signingSummary(string $token): array
    {
        [$s,$issue]=$this->signing($token,false);
        $snapshot=$this->verifySnapshot($issue);
        return ['number'=>$snapshot['number'],'title'=>$snapshot['title'],
            'company_name'=>$snapshot['company_name'],
            'signer_name'=>$s->signer_name,'signer_email'=>$s->signer_email,
            'status'=>$s->status,'request_uuid'=>$s->request_uuid,
            'document_uuid'=>$s->document_uuid,'consent_reference'=>$s->consent_reference,
            'revision'=>(int)$issue->approved_revision,
            'expires_at'=>$s->expires_at];
    }

    public function signingPdf(string $token): array
    {
        [$signing,$issue]=$this->signing($token,false);
        return ['bytes'=>$this->bytes($issue),'filename'=>'General_Document_'.$issue->general_document_id.'.pdf'];
    }

    public function complete(Request $request,string $token): array
    {
        [$item,$issue]=$this->signing($token);
        $data=$request->validate([
            'request_uuid'=>['required','uuid'],'document_uuid'=>['required','uuid'],
            'consent_reference'=>['required','string','size:64'],
            'revision'=>['required','integer','min:1'],
            'signer_name'=>['required','string','max:255'],
            'signature_png'=>['required','string','max:410000'],
            'consent'=>['required','accepted'],
        ]);
        abort_unless(hash_equals($item->request_uuid,$data['request_uuid'])
            && hash_equals($item->document_uuid,$data['document_uuid'])
            && hash_equals($item->consent_reference,$data['consent_reference'])
            && (int)$issue->approved_revision===(int)$data['revision'],403,
            'The signature request identifiers do not match this document.');
        abort_unless(mb_strtolower(trim($data['signer_name']))===
            mb_strtolower(trim($item->signer_name)),422,
            'Please sign using the invited name.');
        $png=base64_decode($data['signature_png'],true);
        abort_unless(is_string($png),422,'Signature image is not valid base64.');
        try {SignedPdfService::validatePng($png);}
        catch(\InvalidArgumentException $error){abort(422,$error->getMessage());}
        $now=now()->utc();
        $snapshot=$this->verifySnapshot($issue);
        $signed=$this->signedPdf->build($this->bytes($issue),
            $snapshot['number'],$issue->pdf_sha256,$item->document_uuid,
            $item->request_uuid,$now,[
                ['role'=>'customer','name'=>$item->signer_name,
                    'method'=>'drawn','png'=>$png],
            ],$item->consent_reference,'General Document');
        $digest=hash('sha256',$signed);
        $path='general-documents/signed/'.$issue->company_id.'/'.$issue->general_document_id.
            '/'.$item->id.'/'.Str::uuid().'_'.$digest.'.pdf';
        abort_unless(Storage::disk('local')->put($path,$signed),503,
            'Signed PDF could not be stored.');
        try{
            $result=DB::transaction(function()use($item,$issue,$path,$digest,
                $request,$now,$png):array{
                $current=DB::table('general_document_signature_requests')
                    ->where('id',$item->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($current->status,['ready','viewed'],true)
                    && $current->revoked_at===null
                    && \Illuminate\Support\Carbon::parse($current->expires_at)->isFuture()
                    && $current->signed_pdf_path===null,409,
                    'This signing request is no longer available.');
                $this->issueRow($issue->company_id,$issue->general_document_id);
                DB::table('general_document_signature_requests')->where('id',$item->id)
                    ->update(['status'=>'signed','signed_at'=>$now,
                        'signed_pdf_path'=>$path,'signed_pdf_sha256'=>$digest,
                        'evidence_json'=>json_encode([
                            'approved_pdf_sha256'=>$issue->pdf_sha256,
                            'source_revision'=>$issue->approved_revision,
                            'request_uuid'=>$item->request_uuid,
                            'document_uuid'=>$item->document_uuid,
                            'consent_reference'=>$item->consent_reference,
                            'consent_accepted'=>true,'signature_sha256'=>hash('sha256',$png),
                            'signer_name'=>$item->signer_name,
                            'signed_at'=>$now->toIso8601String(),
                            'ip'=>$request->ip(),
                            'user_agent'=>mb_substr((string)$request->userAgent(),0,400),
                        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                        'updated_at'=>now()]);
                $this->event($issue->company_id,$issue->general_document_id,null,
                    'customer_signed',['signature_request_id'=>$item->id,
                        'pdf_sha256'=>$digest]);
                return ['signed'=>true,'signed_pdf_sha256'=>$digest,
                    'signed_at'=>$now->toIso8601String()];
            });
        }catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
        return $result;
    }

    public function receipt(string $token): array
    {
        [$signing,$issue]=$this->signing($token,false);
        abort_unless($signing->status==='signed',409,'Document is not signed.');
        $this->signedBytes($signing);
        $s=$this->verifySnapshot($issue);
        return ['number'=>$s['number'],'title'=>$s['title'],
            'signer_name'=>$signing->signer_name,'signed_at'=>$signing->signed_at,
            'source_pdf_sha256'=>$issue->pdf_sha256,
            'signed_pdf_sha256'=>$signing->signed_pdf_sha256];
    }

    public function signedForCustomer(string $token): string
    {
        [$signing]=$this->signing($token,false);
        abort_unless($signing->status==='signed',409);
        return $this->signedBytes($signing);
    }

    public function signedForBusiness(int $company,int $document,int $signature): string
    {
        $issue=$this->issueRow($company,$document);
        $row=DB::table('general_document_signature_requests')
            ->where('company_id',$company)->where('general_document_issue_id',$issue->id)
            ->where('id',$signature)->where('status','signed')->firstOrFail();
        return $this->signedBytes($row);
    }

    public function send(Request $request,Company $company,int $document): array
    {
        $this->manager($request,$company);
        $data=$request->validate([
            'recipient_email'=>['required','email','max:255'],
            'mode'=>['required',Rule::in(['link','pdf','signature'])],
            'signature_request_id'=>['required_if:mode,signature','nullable','integer','min:1'],
            'request_key'=>['required','string','between:8,100',
                'regex:/^[A-Za-z0-9._-]+$/D'],
            'message'=>['nullable','string','max:2000'],
        ]);
        abort_if(in_array((string)config('mail.default'),
            ['log','array','null',''],true),503,
            'Outgoing email is not configured. No message was sent.');
        $issue=$this->issueRow($company->id,$document);
        $this->bytes($issue);
        $signature=null;
        if($data['mode']==='signature'){
            $signature=DB::table('general_document_signature_requests')
                ->where('company_id',$company->id)
                ->where('general_document_issue_id',$issue->id)
                ->where('id',$data['signature_request_id'])
                ->whereIn('status',['ready','viewed'])->whereNull('revoked_at')
                ->where('expires_at','>',now())->firstOrFail();
            abort_unless(mb_strtolower($signature->signer_email)===
                mb_strtolower($data['recipient_email']),422,
                'Signing invitation must go to the invited signer.');
            // Signing links are bearer capabilities and are never stored in clear;
            // require the caller to present the original link for this send.
            $presented=$request->validate(['signature_link'=>['required','url','max:2048']]);
            $url=parse_url($presented['signature_link']);
            $token=basename((string)($url['path']??''));
            abort_unless(($url['scheme']??'')==='https'
                && hash_equals((string)$signature->token_sha256,
                    hash('sha256',$token))
                && rtrim(($url['host']??''),'.')===
                    rtrim((string)parse_url(route('general-document.sign.show',
                        ['token'=>$token]),PHP_URL_HOST),'.'),403,
                'Signing invitation does not match this request.');
        }
        $fingerprint=hash('sha256',json_encode([
            $document,$issue->id,mb_strtolower($data['recipient_email']),
            $data['mode'],$data['signature_request_id']??null,
            $data['message']??'',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $prior=DB::table('general_document_deliveries')
            ->where('company_id',$company->id)->where('request_key',$data['request_key'])->first();
        if($prior){
            abort_unless((int)$prior->general_document_issue_id===(int)$issue->id
                && hash_equals($prior->request_fingerprint,$fingerprint),409,
                'Delivery key already used for a different request.');
            abort_unless($prior->status==='sent',409,
                'Delivery result is uncertain. Review Admin history before retrying.');
            return ['email_submitted'=>true,'replayed'=>true,
                'mode'=>$prior->mode,'sha256'=>$issue->pdf_sha256];
        }
        $link=null;
        $url=$data['mode']==='signature'?$presented['signature_link']:null;
        if($data['mode']!=='signature'){
            $link=$this->createLink($issue);
            $url=$link['secure_link'];
        }
        try{
            $id=DB::table('general_document_deliveries')->insertGetId([
                'company_id'=>$company->id,'general_document_issue_id'=>$issue->id,
                'general_document_customer_link_id'=>$link['id']??null,
                'general_document_signature_request_id'=>$signature?->id,
                'actor_user_id'=>$request->user()?->id,
                'recipient_email'=>mb_strtolower(trim($data['recipient_email'])),
                'mode'=>$data['mode'],'status'=>'pending',
                'request_key'=>$data['request_key'],'request_fingerprint'=>$fingerprint,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }catch(\Throwable $e){
            if($link!==null)DB::table('general_document_customer_links')
                ->where('id',$link['id'])->update(['revoked_at'=>now()]);
            throw $e;
        }
        $s=$this->verifySnapshot($issue);
        try{
            $bytes=$data['mode']==='pdf'?$this->bytes($issue):null;
            Mail::raw('Sign2Digital document '.$s['number']."\n\n".
                ($data['message']??'')."\n\n".
                "Open the private document link (expires in 7 days):\n".$url,
                function($mail)use($data,$s,$bytes){
                    $mail->to($data['recipient_email'])->subject(
                        'Sign2Digital Document '.$s['number']);
                    if($bytes!==null)$mail->attachData($bytes,
                        'Sign2Digital_Document_'.$s['number'].'.pdf',
                        ['mime'=>'application/pdf']);
                });
        }catch(\Throwable $error){
            report($error);
            DB::table('general_document_deliveries')->where('id',$id)
                ->update(['status'=>'failed','updated_at'=>now()]);
            if($link!==null)DB::table('general_document_customer_links')
                ->where('id',$link['id'])->update(['revoked_at'=>now()]);
            abort(502,'Email handoff failed. Check delivery history before retry.');
        }
        DB::transaction(function()use($id,$issue,$document,$request,$data):void{
            DB::table('general_document_deliveries')->where('id',$id)
                ->update(['status'=>'sent','sent_at'=>now(),'updated_at'=>now()]);
            $this->event($issue->company_id,$document,$request->user()?->id,
                'email_submitted',['mode'=>$data['mode'],'delivery_id'=>$id,
                    'issue_sha256'=>$issue->pdf_sha256]);
        });
        return ['email_submitted'=>true,'replayed'=>false,
            'mode'=>$data['mode'],'sha256'=>$issue->pdf_sha256];
    }
}
