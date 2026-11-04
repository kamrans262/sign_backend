<?php

namespace Tests\Feature;

use App\Models\Addendum;
use App\Models\AddendumSigningRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\SignatureRequest;
use App\Models\SignedDocumentArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

final class M10AddendaManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name='Owner Business'): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>$name,'owner_user_id'=>$owner->id,
            'status'=>'active','country_iso_code'=>'CH']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Original Customer','source'=>'manual']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$owner,$company,$client];
    }

    private function signedOriginal(Company $company,Client $client): array
    {
        $pdf=new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica','',12);
        $pdf->Cell(150,10,'Original signed quote - immutable');
        $bytes=$pdf->Output('S');
        $sha=hash('sha256',$bytes);
        $path='signatures/signed/'.$company->id.'/original-'.$sha.'.pdf';
        Storage::disk('local')->put($path,$bytes);
        $quote=ServiceQuote::create([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'reference_number'=>'Q-SIGNED-'.$company->id,
            'client_name'=>'Original Customer',
            'client_email'=>'customer@example.test',
            'quote_date'=>'2026-11-04','currency'=>'CHF',
            'status'=>'sent','revision'=>2,'link_version'=>1,
            'terms'=>'Original approved agreement','subtotal_minor'=>10000,
            'vat_minor'=>810,'total_minor'=>10810,'deposit_minor'=>0,
            'approved_pdf_path'=>'quotes/approved/fake',
            'approved_pdf_sha256'=>str_repeat('1',64),'approved_pdf_revision'=>2,
        ]);
        $signing=SignatureRequest::create([
            'company_id'=>$company->id,'service_quote_id'=>$quote->id,
            'request_uuid'=>(string)\Illuminate\Support\Str::uuid(),
            'document_uuid'=>(string)\Illuminate\Support\Str::uuid(),
            'signer_uuid'=>(string)\Illuminate\Support\Str::uuid(),
            'token_sha256'=>hash('sha256','old-token-'.$company->id),
            'signer_name'=>'Original Customer',
            'signer_email'=>'customer@example.test',
            'approved_revision'=>2,'approved_pdf_sha256'=>str_repeat('1',64),
            'consent_reference'=>str_repeat('2',64),'quote_link_version'=>1,
            'status'=>'signed','mode'=>'remote','expires_at'=>now()->addDays(3),
            'signed_at'=>now(),
        ]);
        $artifact=SignedDocumentArtifact::create([
            'signature_request_id'=>$signing->id,'company_id'=>$company->id,
            'service_quote_id'=>$quote->id,
            'source_pdf_sha256'=>str_repeat('1',64),'source_revision'=>2,
            'signed_pdf_path'=>$path,'signed_pdf_sha256'=>$sha,
            'evidence'=>['mode'=>'remote'],'created_at'=>now(),
        ]);
        return [$artifact,$bytes,$sha];
    }

    private function body(int $id): array
    {
        return [
            'source_signed_artifact_id'=>$id,
            'number'=>'A-2026-0005','title'=>'Roof extension',
            'change_description'=>'Add waterproof tiles without changing the original contract.',
            'effective_date'=>'2026-11-04','additional_work'=>'Extra tiles',
            'quantity'=>3,'unit_price_minor'=>10000,'vat_basis_points'=>810,
        ];
    }

    public function test_real_signed_source_required_and_money_is_server_calculated(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        $this->postJson('/api/v1/addenda',$this->body(999))->assertNotFound();
        [$artifact,$signed,$hash]=$this->signedOriginal($company,$client);
        $created=$this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertCreated()->assertJsonPath('data.addendum.total_minor',32430)
            ->assertJsonPath('data.addendum.vat_minor',2430)
            ->assertJsonPath('data.addendum.currency','CHF')
            ->assertJsonPath('data.addendum.status','draft');
        $id=$created->json('data.addendum.id');
        $this->assertDatabaseCount('addenda',1);
        $this->assertSame($hash,Addendum::findOrFail($id)->source_pdf_sha256);
        $this->getJson('/api/v1/addenda')->assertOk()
            ->assertJsonPath('data.addenda.0.id',$id);
        $this->getJson('/api/v1/addenda/'.$id.'/history')->assertOk()
            ->assertJsonPath('data.events.0.type','created');
        $this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertStatus(409);
        $this->assertSame($signed,Storage::disk('local')->get($artifact->signed_pdf_path));
    }

    public function test_edit_is_draft_only_revision_guarded_and_approval_freezes_bytes(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        [$artifact,$signed]=$this->signedOriginal($company,$client);
        $id=$this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertCreated()->json('data.addendum.id');
        $update=$this->body($artifact->id);
        unset($update['source_signed_artifact_id']);
        $update['expected_revision']=1;
        $update['quantity']=2;
        $this->patchJson('/api/v1/addenda/'.$id,[
            ...$update,'expected_revision'=>2,
        ])->assertStatus(409);
        $this->patchJson('/api/v1/addenda/'.$id,$update)->assertOk()
            ->assertJsonPath('data.addendum.revision',2)
            ->assertJsonPath('data.addendum.total_minor',21620);
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>1,
        ])->assertStatus(409);
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>2,
        ])->assertOk()->assertJsonPath('data.addendum.status','approved');
        $item=Addendum::findOrFail($id);
        $frozen=Storage::disk('local')->get($item->approved_pdf_path);
        $this->assertStringStartsWith('%PDF-',$frozen);
        $this->assertSame(hash('sha256',$frozen),$item->approved_pdf_sha256);
        $this->get('/api/v1/addenda/'.$id.'/pdf')->assertOk()->assertContent($frozen);
        $this->patchJson('/api/v1/addenda/'.$id,$update)->assertStatus(409);
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>2,
        ])->assertStatus(409);
        $this->assertSame($signed,Storage::disk('local')->get($artifact->signed_pdf_path));
        $this->assertDatabaseCount('addendum_revisions',2);
    }

    public function test_cross_tenant_source_and_addendum_requests_are_never_visible(): void
    {
        Storage::fake('local');
        [$one,$company,$client]=$this->tenant();
        [$artifact]=$this->signedOriginal($company,$client);
        $id=$this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertCreated()->json('data.addendum.id');
        [$two,$other]=$this->tenant('Different Company');
        $this->postJson('/api/v1/addenda',$this->body($artifact->id))->assertNotFound();
        $this->getJson('/api/v1/addenda')->assertOk()->assertJsonPath('data.total',0);
        $this->getJson('/api/v1/addenda/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/addenda/'.$id,[
            ...array_diff_key($this->body($artifact->id),['source_signed_artifact_id'=>null]),
            'expected_revision'=>1,
        ])->assertNotFound();
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>1,
        ])->assertNotFound();
        $this->get('/api/v1/addenda/'.$id.'/pdf')->assertNotFound();
    }

    public function test_only_approved_verified_addendum_can_issue_separate_hashed_signature_link(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        [$artifact]=$this->signedOriginal($company,$client);
        $id=$this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertCreated()->json('data.addendum.id');
        $request=['signer_name'=>'Original Customer',
            'signer_email'=>'customer@example.test','delivery'=>'link'];
        $this->postJson('/api/v1/addenda/'.$id.'/signature-requests',$request)
            ->assertStatus(409);
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>1,
        ])->assertOk();
        $this->postJson('/api/v1/addenda/'.$id.'/signature-requests',[
            ...$request,'signer_email'=>'attacker@example.test',
        ])->assertStatus(422);
        $response=$this->postJson('/api/v1/addenda/'.$id.'/signature-requests',
            $request)->assertCreated()->assertJsonPath('data.delivered',false);
        $url=$response->json('data.secure_link');
        $token=basename(parse_url($url,PHP_URL_PATH));
        $this->assertSame(43,strlen($token));
        $sign=AddendumSigningRequest::firstOrFail();
        $this->assertSame(hash('sha256',$token),$sign->token_sha256);
        $this->assertNotSame($token,$sign->token_sha256);
        $this->postJson('/api/v1/addenda/'.$id.'/signature-requests',
            $request)->assertStatus(409);
        $this->getJson('/api/public/addenda/'.$token)->assertOk()
            ->assertJsonPath('data.addendum.id',$id);
        $this->get('/addendum-sign/'.$token)->assertOk()
            ->assertSee('Addendum Preview')->assertSee('Confirm Signature');
        $this->postJson('/api/v1/addenda/'.$id.'/signature-requests/'.$sign->id.
            '/revoke',[])->assertOk()
            ->assertJsonPath('data.request.status','revoked');
        $this->getJson('/api/public/addenda/'.$token)->assertStatus(410);
    }

    public function test_tampered_original_or_approved_bytes_are_never_accepted(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        [$artifact]=$this->signedOriginal($company,$client);
        $id=$this->postJson('/api/v1/addenda',$this->body($artifact->id))
            ->assertCreated()->json('data.addendum.id');
        Storage::disk('local')->put($artifact->signed_pdf_path,'not-a-PDF');
        $this->postJson('/api/v1/addenda/'.$id.'/approve',[
            'confirmed'=>true,'expected_revision'=>1,
        ])->assertStatus(503);
        $this->assertDatabaseMissing('addenda',['id'=>$id,'status'=>'approved']);
    }
}
