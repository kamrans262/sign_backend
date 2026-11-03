<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

final class M8BrowserAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'name'=>'Synthetic Browser Company','owner_user_id'=>$owner->id,
            'status'=>'active','country_iso_code'=>'CH',
        ]);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create([
            'company_id'=>$company->id,
            'full_name'=>'Signing Customer','source'=>'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);

        $pdf=new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica','',12);
        $pdf->Cell(120,12,'Synthetic approved quote for browser tests only.');
        $source=$pdf->Output('S');
        $created=$this->postJson('/api/v1/quotes',[
            'client_id'=>$client->id,'client_name'=>'Signing Customer',
            'client_email'=>'signer@example.test','quote_date'=>'2026-11-04',
            'currency'=>'CHF','vat_basis_points'=>810,
            'deposit_basis_points'=>3000,
            'terms'=>'Browser signing acceptance terms.',
            'items'=>[['title'=>'Synthetic repair service',
                'quantity'=>1,'unit_price_minor'=>12000]],
        ])->assertCreated();
        $id=$created->json('data.quote.id');
        $this->post('/api/v1/quotes/'.$id.'/approve',[
            'confirmed'=>'1','expected_revision'=>'1',
            'file'=>UploadedFile::fake()->createWithContent('approved.pdf',$source),
        ],['Accept'=>'application/json'])->assertOk();
        $request=$this->postJson('/api/v1/quotes/'.$id.'/signature-requests',[
            'signer_name'=>'Signing Customer',
            'signer_email'=>'signer@example.test',
            'delivery'=>'link',
        ])->assertCreated();
        $url=$request->json('data.secure_link');
        return [basename(parse_url($url,PHP_URL_PATH)),SignatureRequest::firstOrFail()];
    }

    private function png(): string
    {
        $width=300;$height=100;
        $raw='';
        for($y=0;$y<$height;$y++){
            $row="\x00";
            for($x=0;$x<$width;$x++) {
                $ink=$x>20 && $x<280 && abs($y-(30+$x/9))<2;
                $row.=$ink ? "\x12\x25\x36" : "\xFF\xFF\xFF";
            }
            $raw.=$row;
        }
        $chunk=static fn(string $name,string $bytes): string =>
            pack('N',strlen($bytes)).$name.$bytes.pack('N',crc32($name.$bytes));
        return "\x89PNG\r\n\x1a\n".
            $chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).
            $chunk('IDAT',gzcompress($raw,6)).
            $chunk('IEND','');
    }

    private function fields(SignatureRequest $signing): array
    {
        return [
            'quoteId'=>(string)$signing->service_quote_id,
            'requestId'=>$signing->request_uuid,
            'documentId'=>$signing->document_uuid,
            'signerId'=>$signing->signer_uuid,
            'documentVersion'=>$signing->approved_revision.':'.$signing->approved_pdf_sha256,
            'consentReference'=>$signing->consent_reference,
            'method'=>'draw','consentAccepted'=>'1',
            'signaturePngBase64'=>base64_encode($this->png()),
        ];
    }

    public function test_customer_browser_form_reuses_m7_document_and_explicit_consent(): void
    {
        [$token,$signing]=$this->fixture();
        $html=$this->get('/sign/'.$token)->assertOk()
            ->assertSee('Synthetic repair service')
            ->assertSee('General Terms')
            ->assertSee('Customer Signature')
            ->assertSee('Confirm Signature')
            ->assertSee('consentAccepted')
            ->assertSee('data-sign-method="draw"',false)
            ->assertSee('data-sign-method="type"',false)
            ->assertSee('data-sign-method="upload"',false)
            ->assertSee('id="s2d-pad"',false)
            ->assertSee('name="_token"',false)
            ->assertHeader('Cache-Control','private, no-store, max-age=0')
            ->getContent();
        $this->assertStringNotContainsString('FIGMA DEMONSTRATION',$html);
    }

    public function test_browser_form_refuses_missing_consent_and_accepts_real_signature_once(): void
    {
        [$token,$signing]=$this->fixture();
        $fields=$this->fields($signing);
        $this->post('/sign/'.$token.'/submit',[
            ...$fields,'consentAccepted'=>'0',
        ],['Accept'=>'text/html'])->assertStatus(422);
        $this->assertDatabaseCount('signed_document_artifacts',0);
        $this->post('/sign/'.$token.'/submit',$fields,['Accept'=>'text/html'])
            ->assertStatus(303)
            ->assertRedirect(route('signing.public.receipt.browser',['token'=>$token]));
        $this->assertDatabaseCount('signed_document_artifacts',1);
        $this->get('/sign/'.$token.'/receipt')->assertOk()
            ->assertSee('Document Signed!');
        $this->get('/sign/'.$token.'/signed-pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->post('/sign/'.$token.'/submit',$fields,['Accept'=>'text/html'])
            ->assertStatus(410);
        $this->assertDatabaseCount('signed_document_artifacts',1);
    }

    public function test_exports_only_synthetic_html_for_chromium_browser_acceptance(): void
    {
        if (getenv('M8_BROWSER_EXPORT')!=='1') {
            $this->markTestSkipped('CI Chromium export explicitly disabled.');
        }
        [$token,$signing]=$this->fixture();
        $response=$this->get('/sign/'.$token)->assertOk();
        $html=$response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('sign2digital-signing',$html);
        $dir=base_path('build/m8-stage2');
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            $this->fail('Unable to create synthetic browser acceptance fixture directory.');
        }
        $path=$dir.'/customer_signing.html';
        $this->assertNotFalse(file_put_contents($path,$html,LOCK_EX));
        @chmod($path,0600);
    }
}
