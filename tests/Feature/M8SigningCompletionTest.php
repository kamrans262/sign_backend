<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\SignatureRequest as Signing;
use App\Models\SignedDocumentArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class M8SigningCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name = 'M8 Signature Business'): array
    {
        $user=User::factory()->create([
            'name'=>'Business Owner','is_active'=>true,'email_verified_at'=>now(),
        ]);
        $company=Company::create([
            'name'=>$name,'owner_user_id'=>$user->id,
            'status'=>'active','country_iso_code'=>'CH',
        ]);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create([
            'company_id'=>$company->id,'full_name'=>'Signing Customer','source'=>'manual',
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$user,$company,$client];
    }

    private function pdfSource(): string
    {
        $pdf=new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica','',12);
        $pdf->Cell(100,10,'Original approved quote PDF. Do not edit.');
        return $pdf->Output('S');
    }

    private function signaturePng(): string
    {
        // A fully valid 300x100 RGB PNG created without GD dependency.
        $width=300;$height=100;
        $raw='';
        for ($y=0;$y<$height;$y++) {
            $row="\x00";
            for ($x=0;$x<$width;$x++) {
                $ink=($x>20 && $x<280 && abs($y-(30+$x/9))<2);
                $row.=$ink ? "\x12\x25\x36" : "\xFF\xFF\xFF";
            }
            $raw.=$row;
        }
        $chunk=static function(string $name,string $data): string {
            return pack('N',strlen($data)).$name.$data.
                pack('N',crc32($name.$data));
        };
        return "\x89PNG\r\n\x1a\n".
            $chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).
            $chunk('IDAT',gzcompress($raw,6)).
            $chunk('IEND','');
    }

    private function approvedQuote(Client $client): array
    {
        $pdf=$this->pdfSource();
        $result=$this->postJson('/api/v1/quotes', [
            'client_id'=>$client->id,
            'client_name'=>'Signing Customer',
            'client_email'=>'customer@example.test',
            'quote_date'=>'2026-11-04',
            'currency'=>'CHF',
            'vat_basis_points'=>810,
            'deposit_basis_points'=>3000,
            'terms'=>'Approved fixed terms. Customer must consent.',
            'items'=>[['title'=>'Approved electrical repair','quantity'=>1,'unit_price_minor'=>12000]],
        ])->assertCreated();
        $id=(int)$result->json('data.quote.id');
        $this->post('/api/v1/quotes/'.$id.'/approve', [
            'confirmed'=>'1','expected_revision'=>'1',
            'file'=>UploadedFile::fake()->createWithContent('approved.pdf',$pdf),
        ],['Accept'=>'application/json'])->assertOk();
        return [ServiceQuote::findOrFail($id),$pdf];
    }

    private function remote(Signing $record,string $token): array
    {
        return [
            'quoteId'=>(string)$record->service_quote_id,
            'requestId'=>$record->request_uuid,
            'documentId'=>$record->document_uuid,
            'signerId'=>$record->signer_uuid,
            'documentVersion'=>$record->approved_revision.':'.$record->approved_pdf_sha256,
            'consentReference'=>$record->consent_reference,
            'method'=>'draw',
            'signaturePngBase64'=>base64_encode($this->signaturePng()),
            'consentAccepted'=>true,
            'capturedAt'=>now()->toIso8601String(),
        ];
    }

    public function test_remote_signing_preserves_frozen_pdf_and_finalizes_exactly_once(): void
    {
        Storage::fake('local');
        [$user,$company,$client]=$this->tenant();
        [$quote,$source]=$this->approvedQuote($client);
        $result=$this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test',
            'delivery'=>'link',
        ])->assertCreated();
        $link=$result->json('data.secure_link');
        $token=basename(parse_url($link,PHP_URL_PATH));
        $record=Signing::firstOrFail();
        $data=$this->remote($record,$token);
        $this->getJson('/api/public/signing/'.$token)->assertOk();
        $signed=$this->postJson('/api/public/signing/'.$token.'/submit',$data)
            ->assertCreated()->assertJsonPath('documentId',$record->document_uuid)
            ->assertJsonPath('quoteId',(string)$quote->id)
            ->assertJsonPath('emailDelivered',false)
            ->json();
        $artifact=SignedDocumentArtifact::firstOrFail();
        $bytes=base64_decode($signed['signedPdfBase64'],true);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith('%PDF-',$bytes);
        $this->assertSame(hash('sha256',$bytes),$artifact->signed_pdf_sha256);
        $this->assertSame($source,Storage::disk('local')->get($quote->approved_pdf_path));
        $this->assertSame($bytes,Storage::disk('local')->get($artifact->signed_pdf_path));
        $this->assertNotSame($source,$bytes);
        $this->assertDatabaseHas('signature_requests',['id'=>$record->id,'status'=>'signed']);
        $this->assertDatabaseCount('signed_document_artifacts',1);
        $this->assertDatabaseCount('signature_events',4); // request, viewed, signed, completed
        $this->postJson('/api/public/signing/'.$token.'/submit',$data)->assertStatus(410);
        $this->assertDatabaseCount('signed_document_artifacts',1);

        $this->get('/sign/'.$token.'/receipt')->assertOk()->assertSee('Document Signed!');
        $this->getJson('/api/public/signing/'.$token.'/receipt')->assertOk()
            ->assertJsonPath('data.signedPdfSha256',$artifact->signed_pdf_sha256);
        $this->get('/sign/'.$token.'/signed-pdf')->assertOk()
            ->assertContent($bytes);
        $this->getJson('/api/v1/signed-documents?quote_id='.$quote->id)
            ->assertOk()->assertJsonPath('data.total',1);
        $this->get('/api/v1/signed-documents/'.$artifact->id.'/pdf')
            ->assertOk()->assertContent($bytes);
    }

    public function test_remote_signing_requires_matching_identifiers_and_consent(): void
    {
        Storage::fake('local');
        [$user,$company,$client]=$this->tenant();
        [$quote,$source]=$this->approvedQuote($client);
        $result=$this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Signing Customer','signer_email'=>'customer@example.test',
            'delivery'=>'link',
        ])->assertCreated();
        $record=Signing::firstOrFail();
        $token=basename(parse_url($result->json('data.secure_link'),PHP_URL_PATH));
        $data=$this->remote($record,$token);
        $this->postJson('/api/public/signing/'.$token.'/submit',[
            ...$data,'consentAccepted'=>false,
        ])->assertUnprocessable();
        $this->postJson('/api/public/signing/'.$token.'/submit',[
            ...$data,'signerId'=>'bad-other-id',
        ])->assertUnprocessable();
        $this->postJson('/api/public/signing/'.$token.'/submit',[
            ...$data,'consentReference'=>str_repeat('0',64),
        ])->assertForbidden();
        $this->postJson('/api/public/signing/'.$token.'/submit',[
            ...$data,'method'=>'type','typedName'=>'Someone Else',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('signed_document_artifacts',0);
    }

    public function test_optional_business_signature_is_verified_before_remote_customer_signing(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        [$quote,$source] = $this->approvedQuote($client);
        $context = $this->getJson('/api/v1/quotes/'.$quote->id.'/business-signature-context')
            ->assertOk()->json('data.context');

        $issued = $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', [
            'signer_name' => 'Signing Customer',
            'signer_email' => 'customer@example.test',
            'delivery' => 'link',
            'business_authorization' => $context['authorization'],
            'business_signature_png_base64' => base64_encode($this->signaturePng()),
            'business_signature_method' => 'draw',
            'business_consent_accepted' => true,
        ])->assertCreated();
        $record = Signing::firstOrFail();
        $this->assertSame($context['request_id'], $record->request_uuid);
        $this->assertSame($context['document_id'], $record->document_uuid);
        $this->assertSame($owner->id, $record->business_user_id);
        $this->assertNotNull($record->business_signed_at);
        $this->assertSame(hash('sha256', $this->signaturePng()),
            $record->business_signature_sha256);
        $this->assertSame($this->signaturePng(),
            Storage::disk('local')->get($record->business_signature_path));

        $token = basename(parse_url($issued->json('data.secure_link'), PHP_URL_PATH));
        $this->get('/sign/'.$token)->assertOk()
            ->assertSee('Business Signature')
            ->assertSee('Business Owner');
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', [
            'signer_name' => 'Signing Customer',
            'signer_email' => 'customer@example.test',
            'delivery' => 'link',
        ])->assertStatus(409);

        $completed = $this->postJson('/api/public/signing/'.$token.'/submit',
            $this->remote($record, $token))->assertCreated()->json();
        $artifact = SignedDocumentArtifact::firstOrFail();
        $this->assertSame(['Business', 'Customer'],
            array_column($artifact->evidence['signers'], 'role'));
        $signedPdf = base64_decode($completed['signedPdfBase64'], true);
        $this->assertSame(hash('sha256', $signedPdf), $artifact->signed_pdf_sha256);
        $this->assertSame($source, Storage::disk('local')->get($quote->approved_pdf_path));
        $this->assertDatabaseHas('signature_requests',
            ['id'=>$record->id, 'status'=>'signed']);
        $this->assertDatabaseCount('signed_document_artifacts', 1);
    }

    public function test_remote_issuer_signature_context_and_evidence_cannot_be_forged(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        [$quote,$source] = $this->approvedQuote($client);
        $context = $this->getJson('/api/v1/quotes/'.$quote->id.'/business-signature-context')
            ->assertOk()->json('data.context');
        $request = [
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test',
            'delivery'=>'link',
            'business_authorization'=>$context['authorization'],
            'business_signature_png_base64'=>base64_encode($this->signaturePng()),
            'business_signature_method'=>'type',
            'business_signature_typed_name'=>'Someone Else',
            'business_consent_accepted'=>true,
        ];
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', $request)
            ->assertUnprocessable();
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', [
            ...$request,
            'business_signature_method'=>'draw',
            'business_authorization'=>'tampered-token',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('signature_requests', 0);

        $issued = $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', [
            ...$request,
            'business_signature_method'=>'draw',
        ])->assertCreated();
        $record = Signing::firstOrFail();
        Storage::disk('local')->put($record->business_signature_path, 'corrupted');
        $token = basename(parse_url($issued->json('data.secure_link'), PHP_URL_PATH));
        $this->postJson('/api/public/signing/'.$token.'/submit',
            $this->remote($record, $token))->assertStatus(503);
        $this->assertDatabaseCount('signed_document_artifacts', 0);
    }

    private function strokes(): array
    {
        return [[
            ['x'=>0.1,'y'=>0.2], ['x'=>0.2,'y'=>0.4],
            ['x'=>0.5,'y'=>0.3], ['x'=>0.7,'y'=>0.6],
        ]];
    }

    public function test_in_person_requires_business_identity_and_both_signatures(): void
    {
        Storage::fake('local');
        [$user,$company,$client]=$this->tenant();
        [$quote,$source]=$this->approvedQuote($client);
        $session=$this->postJson('/api/v1/quotes/'.$quote->id.'/in-person-signing',[])
            ->assertCreated()->json('data');
        $payload=[
            'quoteId'=>$session['quoteId'],
            'documentId'=>$session['documentId'],
            'requestId'=>$session['requestId'],
            'customerSignerId'=>$session['customerSignerId'],
            'businessSignerId'=>$session['businessSignerId'],
            'documentVersion'=>$session['documentVersion'],
            'consentReference'=>$session['consentReference'],
            'consentAccepted'=>true,
            'customerStrokes'=>$this->strokes(),
            'businessStrokes'=>$this->strokes(),
        ];
        $id=(int)$session['request_id'];
        $this->postJson('/api/v1/signature-requests/'.$id.'/complete-in-person',[
            ...$payload,'businessSignerId'=>$session['customerSignerId'],
        ])->assertUnprocessable();
        $foreign=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company->users()->attach($foreign->id,['role'=>'admin','status'=>'active']);
        Sanctum::actingAs($foreign);
        $this->postJson('/api/v1/signature-requests/'.$id.'/complete-in-person',$payload)
            ->assertForbidden();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/signature-requests/'.$id.'/complete-in-person',$payload)
            ->assertCreated()->assertJsonPath('data.documentId',$session['documentId']);
        $this->assertDatabaseHas('signature_requests',['id'=>$id,'status'=>'signed']);
        $this->assertDatabaseCount('signed_document_artifacts',1);
        $this->postJson('/api/v1/signature-requests/'.$id.'/complete-in-person',$payload)
            ->assertStatus(409);
        $this->getJson('/api/public/signing/'.str_repeat('A',43))->assertNotFound();
    }

    public function test_customer_and_company_owner_both_receive_the_exact_immutable_pdf(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        [$quote,$source]=$this->approvedQuote($client);
        $issued=$this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test','delivery'=>'link',
        ])->assertCreated();
        $record=Signing::firstOrFail();
        $token=basename(parse_url($issued->json('data.secure_link'),PHP_URL_PATH));
        $this->postJson('/api/public/signing/'.$token.'/submit',
            $this->remote($record,$token))->assertCreated()
            ->assertJsonPath('emailDelivered',false);
        $artifact=SignedDocumentArtifact::firstOrFail();
        $sent=[];
        config(['mail.default'=>'smtp']);
        Mail::shouldReceive('raw')->twice()->andReturnUsing(
            function (string $body, callable $callback) use (&$sent): void {
                $message=new class {
                    public string $recipient='';
                    public string $digest='';
                    public string $file='';
                    public function to(string $address): self {
                        $this->recipient=$address;return $this;
                    }
                    public function subject(string $subject): self {
                        return $this;
                    }
                    public function attachData(string $bytes,string $name,array $metadata): self {
                        $this->digest=hash('sha256',$bytes);
                        $this->file=$name;
                        return $this;
                    }
                };
                $callback($message);
                $sent[$message->recipient]=[
                    'digest'=>$message->digest,
                    'filename'=>$message->file,
                ];
            }
        );
        $confirmed=app(\App\Services\SignatureCompletionService::class)
            ->emailFinalPdf($artifact);
        $this->assertTrue($confirmed);
        $this->assertCount(2,$sent);
        $this->assertArrayHasKey('customer@example.test',$sent);
        $this->assertArrayHasKey($owner->email,$sent);
        foreach($sent as $delivery){
            $this->assertSame($artifact->signed_pdf_sha256,$delivery['digest']);
            $this->assertSame('Sign2Digital_Signed_Quote.pdf',$delivery['filename']);
        }
        $this->assertDatabaseCount('signed_document_artifacts',1);
        $this->assertDatabaseHas('signature_requests',
            ['id'=>$record->id,'status'=>'signed']);
    }

    public function test_signed_document_download_rejects_other_company_and_corruption(): void
    {
        Storage::fake('local');
        [$user,$company,$client]=$this->tenant();
        [$quote,$source]=$this->approvedQuote($client);
        $session=$this->postJson('/api/v1/quotes/'.$quote->id.'/in-person-signing',[])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/signature-requests/'.$session['request_id'].'/complete-in-person',[
            'quoteId'=>$session['quoteId'],'documentId'=>$session['documentId'],
            'requestId'=>$session['requestId'],
            'customerSignerId'=>$session['customerSignerId'],
            'businessSignerId'=>$session['businessSignerId'],
            'documentVersion'=>$session['documentVersion'],
            'consentReference'=>$session['consentReference'],
            'consentAccepted'=>true,
            'customerStrokes'=>$this->strokes(),'businessStrokes'=>$this->strokes(),
        ])->assertCreated();
        $artifact=SignedDocumentArtifact::firstOrFail();
        [$other,$otherCompany]=$this->tenant('Another Signing Company');
        $this->get('/api/v1/signed-documents/'.$artifact->id.'/pdf')->assertNotFound();
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        Storage::disk('local')->put($artifact->signed_pdf_path,'Modified bytes');
        $this->get('/api/v1/signed-documents/'.$artifact->id.'/pdf')->assertStatus(503);
    }
}
