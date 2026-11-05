<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M18GeneralDocumentIssueTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        Storage::fake('local');
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>'M18 Issue Company','owner_user_id'=>$owner->id,
            'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Test Document Signer','email'=>'document@example.test',
            'source'=>'manual']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$owner,$company,$client];
    }

    private function create(?int $client=null): int
    {
        $result=$this->postJson('/api/v1/general-documents',[
            'type'=>'letter','title'=>'Notice of Completion','locale'=>'en',
            'client_id'=>$client,'job_id'=>null,'source'=>'manual',
            'request_key'=>'m18-issue-create-0000001',
            'blocks'=>[
                ['type'=>'heading','text'=>'Approved Notice','bold'=>true,'italic'=>false],
                ['type'=>'paragraph','text'=>'Review our completed work.',
                    'bold'=>false,'italic'=>false],
            ],
        ])->assertCreated();
        return (int)$result->json('data.document.id');
    }

    private function approve(int $id): void
    {
        $this->postJson('/api/v1/general-documents/'.$id.'/review',
            ['confirm'=>true,'lock_version'=>1])->assertOk();
        $this->postJson('/api/v1/general-documents/'.$id.'/approve',
            ['confirm'=>true,'lock_version'=>2])->assertOk();
    }

    private function issued(int $id): array
    {
        return $this->postJson('/api/v1/general-documents/'.$id.'/issue',
            ['confirm'=>true])->assertOk()->json('data');
    }

    private function ink(): string
    {
        $image=imagecreatetruecolor(240,90);
        $white=imagecolorallocate($image,255,255,255);
        $black=imagecolorallocate($image,18,37,54);
        imagefilledrectangle($image,0,0,239,89,$white);
        imagesetthickness($image,4);
        for($i=0;$i<6;$i++)imageline($image,12+$i*30,60,35+$i*30,20,$black);
        ob_start();imagepng($image);$data=ob_get_clean();
        imagedestroy($image);
        return base64_encode($data);
    }

    public function test_approved_revision_issues_one_authoritative_pdf_and_m9_catalog_exposes_it(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);
        $this->getJson('/api/v1/general-documents/'.$id.'/pdf')->assertStatus(404);
        $this->postJson('/api/v1/general-documents/'.$id.'/issue',
            ['confirm'=>true])->assertStatus(409);
        $this->approve($id);
        $first=$this->issued($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$first['sha256']);
        $this->assertSame($first['sha256'],$this->issued($id)['sha256']);
        $this->assertDatabaseCount('general_document_issues',1);
        $pdf=$this->get('/api/v1/general-documents/'.$id.'/pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-',$pdf);
        $this->assertSame(hash('sha256',$pdf),$first['sha256']);
        $this->getJson('/api/v1/documents?kind=general')->assertOk()
            ->assertJsonPath('data.documents.0.has_pdf',true)
            ->assertJsonPath('data.documents.0.sha256',$first['sha256']);
        $this->get('/api/v1/documents/general-'.$id.'/pdf')->assertOk();
        $this->getJson('/api/v1/general-documents/'.$id.'/issued')->assertOk()
            ->assertJsonPath('data.issue.revision',1)
            ->assertJsonCount(0,'data.signatures');
    }

    public function test_customer_link_revocation_expiry_and_pdf_tampering_fail_closed(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);
        $this->approve($id);
        $this->issued($id);
        $link=$this->postJson('/api/v1/general-documents/'.$id.'/customer-link')
            ->assertOk()->json('data');
        $url=$link['secure_link'];
        $this->assertStringContainsString('/general-document/',$url);
        $this->get($url)->assertOk()->assertSee('Notice of Completion');
        $this->get($url.'/pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        $token=basename(parse_url($url,PHP_URL_PATH));
        $this->assertDatabaseHas('general_document_customer_links',[
            'token_sha256'=>hash('sha256',$token)]);
        $this->assertDatabaseMissing('general_document_customer_links',[
            'token_sha256'=>$token]);
        $this->deleteJson('/api/v1/general-documents/'.$id.
            '/customer-links/'.$link['id'])->assertOk();
        $this->get($url)->assertNotFound();
        $second=$this->postJson('/api/v1/general-documents/'.$id.'/customer-link')
            ->assertOk()->json('data');
        $issue=DB::table('general_document_issues')->firstOrFail();
        Storage::disk('local')->put($issue->private_path,'invalid');
        $this->get('/api/v1/general-documents/'.$id.'/pdf')->assertStatus(409);
        $this->get($second['secure_link'].'/pdf')->assertStatus(409);
        $this->postJson('/api/v1/general-documents/'.$id.'/customer-link')
            ->assertStatus(409);
    }

    public function test_signed_pdf_binds_original_approval_consent_and_signer(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);
        $this->approve($id);$issued=$this->issued($id);
        $signed=$this->postJson('/api/v1/general-documents/'.$id.'/signature-requests',[
            'signer_name'=>'Test Document Signer',
            'signer_email'=>'document@example.test','confirm'=>true,
        ])->assertCreated()->json('data');
        $url=$signed['signature_link'];
        $this->get($url)->assertOk()->assertSee('Review and Sign General Document');
        $json=$this->getJson($url)->assertOk()->json('data');
        $this->get($url.'/pdf')->assertOk();
        $payload=[
            'request_uuid'=>$json['request_uuid'],'document_uuid'=>$json['document_uuid'],
            'consent_reference'=>$json['consent_reference'],
            'revision'=>$json['revision'],
            'signer_name'=>'Test Document Signer','signature_png'=>$this->ink(),
            'consent'=>true,
        ];
        $this->postJson($url.'/submit',array_merge($payload,
            ['consent_reference'=>str_repeat('0',64)]))->assertStatus(403);
        $this->postJson($url.'/submit',$payload)->assertOk()
            ->assertJsonPath('data.signed',true);
        $this->postJson($url.'/submit',$payload)->assertStatus(409);
        $receipt=$this->getJson($url.'/receipt')->assertOk()->json('data');
        $this->assertSame($issued['sha256'],$receipt['source_pdf_sha256']);
        $signedBytes=$this->get($url.'/signed-pdf')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-',$signedBytes);
        $this->assertSame(hash('sha256',$signedBytes),
            $receipt['signed_pdf_sha256']);
        $this->get('/api/v1/general-documents/'.$id.'/signatures/'.$signed['id'].
            '/signed-pdf')->assertOk();
        $this->assertDatabaseCount('general_document_signature_requests',1);
        $this->assertDatabaseHas('general_document_signature_requests',[
            'id'=>$signed['id'],'status'=>'signed']);
        $this->assertDatabaseHas('general_documents',['id'=>$id,'status'=>'approved']);
    }

    public function test_signature_revocation_cross_company_and_expiry_are_enforced(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);$this->approve($id);$this->issued($id);
        $sig=$this->postJson('/api/v1/general-documents/'.$id.'/signature-requests',[
            'signer_name'=>'Test Document Signer',
            'signer_email'=>'document@example.test','confirm'=>true,
        ])->assertCreated()->json('data');
        $this->deleteJson('/api/v1/general-documents/'.$id.'/signature-requests/'.
            $sig['id'])->assertOk();
        $this->get($sig['signature_link'])->assertStatus(410);
        $sig2=$this->postJson('/api/v1/general-documents/'.$id.'/signature-requests',[
            'signer_name'=>'Test Document Signer',
            'signer_email'=>'document@example.test','confirm'=>true,
        ])->assertCreated()->json('data');
        $other=Company::create(['name'=>'Other','owner_user_id'=>$user->id,
            'country_iso_code'=>'CH','status'=>'active']);
        $other->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $this->withHeader('X-Company-Id',(string)$other->id);
        $this->getJson('/api/v1/general-documents/'.$id.'/issued')->assertNotFound();
        $this->postJson('/api/v1/general-documents/'.$id.'/issue',
            ['confirm'=>true])->assertNotFound();
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->travel(8)->days();
        $this->get($sig2['signature_link'])->assertStatus(410);
    }

    public function test_email_transport_is_explicit_idempotent_and_no_auto_send(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);$this->approve($id);$this->issued($id);
        $params=['recipient_email'=>'document@example.test','mode'=>'pdf',
            'request_key'=>'m18-mail-once-12345678','message'=>'Please review.'];
        config()->set('mail.default','log');
        $this->postJson('/api/v1/general-documents/'.$id.'/send',$params)
            ->assertStatus(503);
        $this->assertDatabaseCount('general_document_deliveries',0);
        config()->set('mail.default','smtp');
        Mail::fake();
        $this->postJson('/api/v1/general-documents/'.$id.'/send',$params)
            ->assertOk()->assertJsonPath('data.email_submitted',true)
            ->assertJsonPath('data.replayed',false);
        $this->postJson('/api/v1/general-documents/'.$id.'/send',$params)
            ->assertOk()->assertJsonPath('data.replayed',true);
        $this->postJson('/api/v1/general-documents/'.$id.'/send',array_merge(
            $params,['recipient_email'=>'other@example.test']))->assertStatus(409);
        $this->assertDatabaseCount('general_document_deliveries',1);
        $this->assertDatabaseHas('general_document_deliveries',[
            'recipient_email'=>'document@example.test','status'=>'sent']);
    }

    public function test_immutable_source_snapshot_tampering_and_draft_signing_rejected(): void
    {
        [$user,$company,$client]=$this->tenant();
        $id=$this->create($client->id);
        $this->postJson('/api/v1/general-documents/'.$id.'/signature-requests',[
            'signer_name'=>'Test','signer_email'=>'document@example.test',
            'confirm'=>true])->assertStatus(404);
        $this->approve($id);$this->issued($id);
        $issue=DB::table('general_document_issues')->firstOrFail();
        DB::table('general_document_issues')->where('id',$issue->id)
            ->update(['snapshot'=>'{"fake":"document"}']);
        $this->get('/api/v1/general-documents/'.$id.'/pdf')->assertStatus(409);
        $this->postJson('/api/v1/general-documents/'.$id.'/issue',
            ['confirm'=>true])->assertStatus(409);
    }

    public function test_admin_can_issue_and_view_delivery_audits_in_existing_module(): void
    {
        [$owner,$company,$client]=$this->tenant();
        $id=$this->create($client->id);$this->approve($id);
        $admin=User::factory()->create(['is_platform_admin'=>true,'is_active'=>true]);
        $this->actingAs($admin);
        $this->post('/admin/general-documents/'.$id.'/issue',
            ['confirm'=>true])->assertRedirect();
        $this->get('/admin/general-documents/'.$id.'/edit')->assertOk()
            ->assertSee('Official PDF')
            ->assertSee('Customer Signature Request');
        $this->get('/admin/general-documents/'.$id.'/issued-pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->post('/admin/general-documents/'.$id.'/customer-link',[])
            ->assertRedirect();
        $this->assertDatabaseCount('general_document_customer_links',1);
    }
}
