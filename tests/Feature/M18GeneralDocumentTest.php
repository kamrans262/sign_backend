<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M18GeneralDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name='M18 Company'): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>$name,'owner_user_id'=>$owner->id,
            'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Document Customer','email'=>'doc@example.test','source'=>'manual']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Document Job',
            'start_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'team_member_ids'=>[$owner->id],
        ])->assertCreated()->json('data.job.id');
        return [$owner,$company,$client,$job];
    }

    private function fields(?int $client=null,?int $job=null): array
    {
        return ['type'=>'letter','title'=>'Customer Letter',
            'locale'=>'en','client_id'=>$client,'job_id'=>$job,
            'source'=>'manual','request_key'=>'m18-request-unique-0001',
            'blocks'=>[
                ['type'=>'heading','text'=>'Notice','bold'=>true,'italic'=>false],
                ['type'=>'paragraph','text'=>'<script>alert(1)</script> is text',
                    'bold'=>false,'italic'=>false],
            ]];
    }

    private function draft(?int $client=null,?int $job=null): int
    {
        return (int)$this->postJson('/api/v1/general-documents',
            $this->fields($client,$job))->assertCreated()
            ->assertJsonPath('data.document.status','draft')
            ->json('data.document.id');
    }

    public function test_manual_creates_idempotent_revisions_and_approves_without_ai(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $id=$this->draft($client->id,$job);
        $this->postJson('/api/v1/general-documents',$this->fields($client->id,$job))
            ->assertCreated()->assertJsonPath('data.document.id',$id);
        $this->assertDatabaseCount('general_documents',1);
        $this->assertDatabaseCount('general_document_revisions',1);
        $this->assertDatabaseCount('general_document_events',1);
        $this->getJson('/api/v1/general-documents/'.$id)->assertOk()
            ->assertJsonPath('data.content.blocks.1.text','<script>alert(1)</script> is text')
            ->assertJsonCount(1,'data.revisions');
        $this->getJson('/api/v1/documents?kind=general')->assertOk()
            ->assertJsonPath('data.documents.0.id','general-'.$id)
            ->assertJsonPath('data.documents.0.has_pdf',false);
        $update=$this->fields($client->id,$job);
        unset($update['request_key']);
        $update['lock_version']=1;$update['title']='Revised Customer Letter';
        $this->patchJson('/api/v1/general-documents/'.$id,$update)->assertOk()
            ->assertJsonPath('data.document.revision',2)
            ->assertJsonPath('data.document.lock_version',2);
        $this->patchJson('/api/v1/general-documents/'.$id,$update)->assertStatus(409);
        $this->postJson('/api/v1/general-documents/'.$id.'/review',[
            'confirm'=>true,'lock_version'=>2])->assertOk()
            ->assertJsonPath('data.document.status','reviewed');
        $this->postJson('/api/v1/general-documents/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>3])->assertOk()
            ->assertJsonPath('data.document.status','approved')
            ->assertJsonPath('data.document.approved_revision',2);
        $this->patchJson('/api/v1/general-documents/'.$id,array_merge($update,
            ['lock_version'=>4]))->assertStatus(409);
        $this->get('/api/v1/documents/general-'.$id.'/pdf')->assertStatus(409);
        $this->assertDatabaseCount('general_document_revisions',2);
    }

    public function test_company_boundaries_and_relation_checks_block_cross_tenant(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $id=$this->draft($client->id,$job);
        $otherOwner=User::factory()->create(['is_active'=>true]);
        $other=Company::create(['name'=>'Other','owner_user_id'=>$otherOwner->id,
            'country_iso_code'=>'CH','status'=>'active']);
        $other->users()->attach($otherOwner->id,['role'=>'owner','status'=>'active']);
        Sanctum::actingAs($otherOwner);
        $this->withHeader('X-Company-Id',(string)$other->id);
        $this->getJson('/api/v1/general-documents/'.$id)->assertNotFound();
        $this->getJson('/api/v1/documents/general-'.$id)->assertNotFound();
        $this->postJson('/api/v1/general-documents',$this->fields($client->id,$job))
            ->assertStatus(422);
    }

    public function test_create_retry_changed_body_and_invalid_blocks_are_rejected(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $this->draft($client->id,$job);
        $different=$this->fields($client->id,$job);
        $different['title']='Unexpected different request';
        $this->postJson('/api/v1/general-documents',$different)->assertStatus(409);
        $bad=$this->fields($client->id,$job);
        $bad['request_key']='m18-new-bad-request';
        $bad['blocks']=[['type'=>'html','text'=>'<script>alert(1)</script>']];
        $this->postJson('/api/v1/general-documents',$bad)->assertUnprocessable();
        $this->assertDatabaseCount('general_document_revisions',1);
    }

    public function test_attachments_are_private_verified_and_frozen_after_approval(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant();
        $id=$this->draft($client->id,$job);
        $file=UploadedFile::fake()->image('evidence.png');
        $upload=$this->post('/api/v1/general-documents/'.$id.'/attachments',[
            'file'=>$file],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $this->get('/api/v1/general-documents/'.$id.'/attachments/'.$upload)
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->postJson('/api/v1/general-documents/'.$id.'/review',[
            'confirm'=>true,'lock_version'=>1])->assertOk();
        $this->postJson('/api/v1/general-documents/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>2])->assertOk();
        $this->deleteJson('/api/v1/general-documents/'.$id.'/attachments/'.$upload)
            ->assertStatus(409);
        $row=DB::table('general_document_attachments')->firstOrFail();
        Storage::disk('local')->put($row->private_path,'tampered');
        $this->get('/api/v1/general-documents/'.$id.'/attachments/'.$upload)
            ->assertStatus(409);
    }

    public function test_ai_quota_failure_does_not_save_or_approve_document(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        config()->set('services.openai.key','');
        $payload=['type'=>'letter','locale'=>'en','client_id'=>$client->id,
            'job_id'=>$job,'instructions'=>'Prepare a short customer update.'];
        $this->postJson('/api/v1/general-documents/ai/prepare',$payload)->assertStatus(503);
        config()->set('services.openai.key','fake-m18-test-key');
        Http::fake(['api.openai.com/*'=>Http::response(['error'=>[
            'code'=>'insufficient_quota']],429)]);
        $this->postJson('/api/v1/general-documents/ai/prepare',$payload)->assertStatus(503);
        $this->assertDatabaseCount('general_documents',0);
    }

    public function test_ai_suggestion_is_only_transient_unapproved_data(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        config()->set('services.openai.key','fake-m18-test-key');
        Http::fake(['api.openai.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'title'=>'Draft letter','paragraphs'=>['Check the pending work details.'],
            ])]]],
        ],200)]);
        $this->postJson('/api/v1/general-documents/ai/prepare',[
            'type'=>'letter','locale'=>'en','client_id'=>$client->id,
            'job_id'=>$job,'instructions'=>'Prepare a short customer update.',
        ])->assertOk()->assertJsonPath('data.suggestion.saved',false)
            ->assertJsonPath('data.suggestion.requires_user_review',true);
        $this->assertDatabaseCount('general_documents',0);
    }

    public function test_admin_can_create_revise_review_and_approve_with_existing_styles(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $admin=User::factory()->create(['is_platform_admin'=>true,'is_active'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/general-documents')->assertOk()->assertSee('General AI Documents');
        $this->get('/admin/general-documents/create')->assertOk();
        $this->post('/admin/general-documents',[
            'company_id'=>$company->id,'type'=>'custom','title'=>'Admin Document',
            'locale'=>'en','document_text'=>"First paragraph\n\nSecond paragraph",
            'client_id'=>$client->id,'job_id'=>$job,
        ])->assertRedirect();
        $id=(int)DB::table('general_documents')->value('id');
        $this->get('/admin/general-documents/'.$id.'/edit')
            ->assertOk()->assertSee('Second paragraph');
        $this->post('/admin/general-documents/'.$id.'/review',[
            'confirm'=>1,'lock_version'=>1])->assertRedirect();
        $this->post('/admin/general-documents/'.$id.'/approve',[
            'confirm'=>1,'lock_version'=>2])->assertRedirect();
        $this->assertDatabaseHas('general_documents',[
            'id'=>$id,'status'=>'approved','approved_revision'=>1]);
    }
}
