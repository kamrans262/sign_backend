<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class WorkAcceptanceCustomerService
{
    public function __construct(
        private readonly WorkAcceptanceService $reports,
        private readonly SignedPdfService $signer,
    ) {}

    public function byToken(string $token): object
    {
        abort_unless((bool)preg_match('/^[A-Za-z0-9_-]{43}$/D',$token),404);
        return DB::table('work_acceptance_requests')
            ->where('token_sha256',hash('sha256',$token))->firstOrFail();
    }

    public function active(object $request): object
    {
        abort_unless(in_array($request->status,['ready','sent','viewed'],true)
            && $request->revoked_at===null
            && \Illuminate\Support\Carbon::parse($request->expires_at)->isFuture(),410,
            'This acceptance link has expired or is no longer active.');
        $row=$this->reports->find((int)$request->company_id,
            (int)$request->work_acceptance_id);
        abort_unless(in_array($row->status,['approved','sent','viewed'],true)
            && (int)$row->revision===(int)$request->approved_revision
            && hash_equals($row->approved_pdf_sha256??'',(string)$request->approved_pdf_sha256)
            && DB::table('companies')->where('id',$row->company_id)
                ->where('status','active')->exists(),410,
            'The approved report no longer matches this request.');
        $this->reports->approvedBytes($row);
        return $row;
    }

    public function open(Request $http,string $token): array
    {
        $item=$this->byToken($token);
        $row=$this->active($item);
        DB::transaction(function()use($item,$http):void {
            $locked=DB::table('work_acceptance_requests')->where('id',$item->id)
                ->lockForUpdate()->firstOrFail();
            $report=$this->active($locked);
            if($locked->viewed_at===null) {
                DB::table('work_acceptance_requests')->where('id',$item->id)->update([
                    'status'=>'viewed','viewed_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('work_acceptances')->where('id',$report->id)
                    ->update(['status'=>'viewed','updated_at'=>now()]);
                $this->reports->event($report,'client_viewed',null,$item->id,[
                    'ip'=>$http->ip(),'user_agent'=>mb_substr((string)$http->userAgent(),0,500),
                ]);
            }
        });
        return ['request'=>$item,'report'=>$this->reports->find($row->company_id,$row->id)];
    }

    private static function label(string $text,int $max=6000): string
    {
        $text=mb_substr($text,0,$max);
        $t=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/',' ',(string)$t)??'';
    }

    /** Appends the actual customer decision before signing, so nothing is hidden. */
    private function respondedPdf(string $source,object $report,array $data,
        array $photos): string
    {
        $pdf=new Fpdi();
        $count=$pdf->setSourceFile(StreamReader::createByString($source));
        abort_unless($count>=1&&$count<=50,422,'Unsupported report PDF.');
        foreach(range(1,$count) as $i) {
            $template=$pdf->importPage($i);
            $size=$pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'],[$size['width'],$size['height']]);
            $pdf->useTemplate($template,0,0,$size['width'],$size['height'],true);
        }
        $pdf->SetMargins(19,19,19);
        $pdf->SetAutoPageBreak(true,18);
        $pdf->AddPage('P','A4');
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Arial','B',15);
        $pdf->MultiCell(0,9,'Customer Work Acceptance Response');
        $pdf->SetFont('Arial','',10);
        $rows=[
            'Work acceptance'=>$report->number,
            'Decision'=>$data['decision']==='accepted_with_reservations'
                ? 'Accepted with reservations' : 'Accepted',
            'Customer'=>$data['customer_name'],
            'Signed decision date (UTC)'=>now()->utc()->format('Y-m-d H:i:s').' UTC',
            'Reservations'=>$data['reservation']??'None',
            'Outstanding issues'=>$data['outstanding_issues']??'None',
            'Additional comments'=>$data['comment']??'None',
            'Optional rating'=>isset($data['rating'])?(string)$data['rating'].' / 5':'Not provided',
        ];
        foreach($rows as $label=>$value) {
            $pdf->SetFont('Arial','B',10);
            $pdf->MultiCell(0,7,self::label($label));
            $pdf->SetFont('Arial','',10);
            $pdf->MultiCell(0,6,self::label($value));
            $pdf->Ln(2);
        }
        foreach($photos as $index=>$photo) {
            $pdf->SetFont('Arial','B',10);
            $pdf->MultiCell(0,7,'Customer Evidence Photo #'.($index+1));
            $pdf->SetFont('Arial','',8);
            $pdf->MultiCell(0,5,'SHA-256: '.$photo['sha']);
            $ratio=$photo['width']/max(1,$photo['height']);
            $w=min(100.0,83.0*$ratio);$h=$w/$ratio;
            if($h>90){$h=90;$w=90*$ratio;}
            if($pdf->GetY()+$h+12>266)$pdf->AddPage('P','A4');
            $y=$pdf->GetY();
            $pdf->Image($photo['path'],19,$y,$w,$h,
                $photo['mime']==='image/png'?'PNG':'JPG');
            $pdf->SetY($y+$h+5);
        }
        return $pdf->Output('S');
    }

    private function verifyInk(string $png): void
    {
        SignedPdfService::validatePng($png);
        $img=@imagecreatefromstring($png);
        abort_unless($img!==false,422,'Invalid signature drawing.');
        try {
            $w=imagesx($img);$h=imagesy($img);
            $marks=0;
            for($y=0;$y<$h;$y+=max(1,(int)floor($h/75))) {
                for($x=0;$x<$w;$x+=max(1,(int)floor($w/150))) {
                    $rgba=imagecolorat($img,$x,$y);
                    $alpha=($rgba>>24)&0x7F;
                    $rgb=$rgba&0xFFFFFF;
                    $r=($rgb>>16)&255;$g=($rgb>>8)&255;$b=$rgb&255;
                    if($alpha<110&&($r+$g+$b)<510)$marks++;
                }
            }
            abort_unless($marks>=12,422,'Draw a visible signature to accept the work.');
        } finally {
            imagedestroy($img);
        }
    }

    public function submit(Request $http,string $token): array
    {
        $link=$this->byToken($token);
        $this->active($link);
        $data=$http->validate([
            'request_uuid'=>['required','uuid'],
            'document_uuid'=>['required','uuid'],
            'consent_reference'=>['required','string','size:64'],
            'revision'=>['required','integer','min:1'],
            'decision'=>['required','in:accepted,accepted_with_reservations'],
            'customer_name'=>['required','string','max:255'],
            'reservation'=>['nullable','string','max:12000'],
            'outstanding_issues'=>['nullable','string','max:12000'],
            'comment'=>['nullable','string','max:5000'],
            'rating'=>['nullable','integer','between:1,5'],
            'signature_png'=>['required','string','max:410000'],
            'consent'=>['required','accepted'],
            'photos'=>['nullable','array','max:4'],
            'photos.*'=>['required','file','mimes:jpg,jpeg,png','max:3072'],
        ]);
        abort_unless(hash_equals($link->request_uuid,$data['request_uuid']) &&
            hash_equals($link->document_uuid,$data['document_uuid']) &&
            hash_equals($link->consent_reference,$data['consent_reference']) &&
            (int)$link->approved_revision===(int)$data['revision'],403,
            'The accepted document identifiers do not match.');
        abort_unless(mb_strtolower(trim($link->signer_name))===
            mb_strtolower(trim($data['customer_name'])),422,
            'Customer name must match the invited signer.');
        if($data['decision']==='accepted_with_reservations' &&
            trim((string)($data['reservation']??''))==='' &&
            trim((string)($data['outstanding_issues']??''))==='' &&
            empty($data['photos'])) {
            throw ValidationException::withMessages([
                'reservation'=>'Describe at least one reservation, issue or attach photo evidence.',
            ]);
        }
        $png=base64_decode($data['signature_png'],true);
        abort_unless(is_string($png),422,'Invalid signature image.');
        $this->verifyInk($png);
        $uploads=[];
        foreach($http->file('photos',[]) as $file) {
            $bytes=file_get_contents($file->getRealPath());
            $dims=is_string($bytes)?@getimagesizefromstring($bytes):false;
            abort_unless(is_array($dims)&&in_array($dims['mime']??'',
                ['image/png','image/jpeg'],true) && strlen($bytes)<=3072*1024 &&
                $dims[0]>0 && $dims[1]>0 && $dims[0]<11000 && $dims[1]<11000,
                422,'Invalid customer photo.');
            $uploads[]=[
                'bytes'=>$bytes,'mime'=>$dims['mime'],'width'=>$dims[0],
                'height'=>$dims[1],'sha'=>hash('sha256',$bytes),
            ];
        }
        $created=[];
        try {
            return DB::transaction(function()use($http,$link,$data,$png,$uploads,&$created):array {
                $locked=DB::table('work_acceptance_requests')
                    ->where('id',$link->id)->lockForUpdate()->firstOrFail();
                $row=$this->active($locked);
                abort_unless(!DB::table('work_acceptance_artifacts')
                    ->where('work_acceptance_id',$row->id)->exists(),409,
                    'Work acceptance has already been signed.');
                $store=Storage::disk('local');
                $prefix='work-acceptance/signed/'.$row->company_id.'/'.$row->id.'/'.
                    $locked->id.'/';
                $photos=[];
                foreach($uploads as $n=>$photo) {
                    $path=$prefix.'photo-'.($n+1).'-'.$photo['sha'].
                        ($photo['mime']==='image/png'?'.png':'.jpg');
                    abort_unless(!$store->exists($path)&&$store->put($path,$photo['bytes']),
                        503,'Unable to store customer evidence.');
                    $created[]=$path;
                    $photos[]=array_merge($photo,['path'=>$store->path($path)]);
                    DB::table('work_acceptance_customer_photos')->insert([
                        'company_id'=>$row->company_id,'work_acceptance_id'=>$row->id,
                        'work_acceptance_request_id'=>$locked->id,
                        'private_path'=>$path,'mime_type'=>$photo['mime'],
                        'byte_size'=>strlen($photo['bytes']),'sha256'=>$photo['sha'],
                        'created_at'=>now(),
                    ]);
                }
                $approved=$this->reports->approvedBytes($row);
                $response=$this->respondedPdf($approved,$row,$data,$photos);
                abort_unless(strlen($response)<=5*1024*1024,422,
                    'Customer evidence exceeds the maximum signed PDF size.');
                $signed=$this->signer->build(
                    $response,$row->number,hash('sha256',$response),
                    $locked->document_uuid,$locked->request_uuid,now()->utc(),
                    [[
                        'role'=>'Customer','name'=>$data['customer_name'],
                        'method'=>'draw','png'=>$png,
                    ]],$locked->consent_reference,'Work Acceptance');
                abort_unless(strlen($signed)<12*1024*1024 &&
                    str_starts_with($signed,'%PDF-') &&
                    str_contains(substr($signed,-4096),'%%EOF'),503,
                    'Signed acceptance PDF is invalid.');
                $sha=hash('sha256',$signed);
                $pdfPath=$prefix.$sha.'.pdf';
                $sigPath=$prefix.'signature-'.hash('sha256',$png).'.png';
                foreach([[$pdfPath,$signed],[$sigPath,$png]] as [$path,$bytes]) {
                    abort_unless(!$store->exists($path)&&$store->put($path,$bytes) &&
                        hash_equals(hash('sha256',$bytes),
                            hash('sha256',$store->get($path))),503,
                        'Signed acceptance storage integrity failed.');
                    $created[]=$path;
                }
                $managed=DB::table('managed_documents')->insertGetId([
                    'company_id'=>$row->company_id,'client_id'=>$row->client_id,
                    'service_job_id'=>$row->service_job_id,
                    'created_by'=>$locked->created_by,'category'=>'reports',
                    'number'=>$row->number,'title'=>'Work Acceptance '.$row->number,
                    'status'=>'file','current_version'=>1,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('managed_document_versions')->insert([
                    'company_id'=>$row->company_id,'managed_document_id'=>$managed,
                    'version'=>1,'original_name'=>$row->number.'-signed.pdf',
                    'private_path'=>$pdfPath,'mime_type'=>'application/pdf',
                    'byte_size'=>strlen($signed),'sha256'=>$sha,
                    'uploaded_by'=>$locked->created_by,'created_at'=>now(),
                ]);
                DB::table('document_events')->insert([
                    'company_id'=>$row->company_id,'target_kind'=>'managed',
                    'target_id'=>$managed,'event_type'=>'created',
                    'metadata'=>json_encode(['source_acceptance_id'=>$row->id]),
                    'created_at'=>now(),
                ]);
                $evidence=[
                    'request_uuid'=>$locked->request_uuid,
                    'document_uuid'=>$locked->document_uuid,
                    'accepted_revision'=>$locked->approved_revision,
                    'original_approved_sha256'=>$locked->approved_pdf_sha256,
                    'signed_source_sha256'=>hash('sha256',$response),
                    'decision'=>$data['decision'],'consent_accepted'=>true,
                    'consent_reference'=>$locked->consent_reference,
                    'signature_sha256'=>hash('sha256',$png),
                    'ip_address'=>$http->ip(),
                    'user_agent'=>mb_substr((string)$http->userAgent(),0,500),
                    'signed_at_utc'=>now()->utc()->toIso8601String(),
                    'customer_photo_sha256'=>array_column($uploads,'sha'),
                ];
                DB::table('work_acceptance_artifacts')->insert([
                    'company_id'=>$row->company_id,'work_acceptance_id'=>$row->id,
                    'work_acceptance_request_id'=>$locked->id,
                    'signature_path'=>$sigPath,'signature_sha256'=>hash('sha256',$png),
                    'signed_pdf_path'=>$pdfPath,'signed_pdf_sha256'=>$sha,
                    'evidence'=>json_encode($evidence,JSON_THROW_ON_ERROR),
                    'created_at'=>now(),
                ]);
                DB::table('work_acceptances')->where('id',$row->id)->update([
                    'status'=>$data['decision'],
                    'signed_at'=>now(),'acceptance_result'=>$data['decision'],
                    'customer_name'=>$data['customer_name'],
                    'customer_reservation'=>$data['reservation']??null,
                    'customer_outstanding_issues'=>$data['outstanding_issues']??null,
                    'customer_rating'=>$data['rating']??null,
                    'customer_comment'=>$data['comment']??null,
                    'managed_document_id'=>$managed,
                    'signed_pdf_path'=>$pdfPath,'signed_pdf_sha256'=>$sha,
                    'updated_at'=>now(),
                ]);
                DB::table('work_acceptance_requests')->where('id',$locked->id)->update([
                    'status'=>'signed','signed_at'=>now(),'updated_at'=>now(),
                ]);
                $this->reports->event($row,'customer_signed',null,$locked->id,[
                    'decision'=>$data['decision'],'signed_pdf_sha256'=>$sha,
                    'consent_reference'=>$locked->consent_reference,
                ]);
                return [
                    'report'=>$this->reports->payload($this->reports->find($row->company_id,$row->id)),
                    'request_uuid'=>$locked->request_uuid,
                    'signed_pdf_sha256'=>$sha,
                ];
            });
        } catch(\Throwable $error) {
            foreach($created as $path)Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function receipt(string $token): array
    {
        $request=$this->byToken($token);
        abort_unless($request->status==='signed',410);
        $report=$this->reports->find($request->company_id,$request->work_acceptance_id);
        abort_unless($report->signed_pdf_sha256,409);
        $this->reports->signedBytes($report);
        return ['number'=>$report->number,'decision'=>$report->acceptance_result,
            'signed_at'=>$report->signed_at,'pdf_sha256'=>$report->signed_pdf_sha256];
    }

    public function signedBytes(string $token): string
    {
        $request=$this->byToken($token);
        abort_unless($request->status==='signed',410);
        $report=$this->reports->find($request->company_id,$request->work_acceptance_id);
        return $this->reports->signedBytes($report);
    }
}
