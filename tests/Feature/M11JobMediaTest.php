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

class M11JobMediaTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): array
    {
        $user = User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company = Company::create([
            'name'=>$name, 'owner_user_id'=>$user->id, 'status'=>'active',
            'country_iso_code'=>'CH',
        ]);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client = Client::create(['company_id'=>$company->id,'full_name'=>'Test Customer','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $response = $this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,
            'description'=>'Replace window glazing',
            'start_date'=>'2026-12-01', 'due_date'=>'2026-12-02',
            'team_member_ids'=>[],
        ])->assertCreated();
        return [$user,$company,(int)$response->json('data.job.id')];
    }

    private function photo(): UploadedFile
    {
        // Real one-pixel PNG bytes; not a renamed text file.
        return UploadedFile::fake()->createWithContent('before.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzcAAAAASUVORK5CYII='));
    }

    private function upload(int $job): int
    {
        return (int)$this->post('/api/v1/jobs/'.$job.'/photos',[
            'file'=>$this->photo(),'category'=>'before','caption'=>'Entrance',
        ],['Accept'=>'application/json'])->assertCreated()->json('data.photo.id');
    }

    private function note(int $job, string $visibility='private'): int
    {
        return (int)$this->postJson('/api/v1/jobs/'.$job.'/notes',[
            'title'=>'Original observation',
            'body'=>'The glass is broken; client requested a quote.',
            'type'=>'text','visibility'=>$visibility,
        ])->assertCreated()->json('data.note.id');
    }

    public function test_photos_are_private_persistent_and_integrity_checked(): void
    {
        Storage::fake('local');
        [$user,$company,$job] = $this->tenant('One');
        $photo = $this->upload($job);
        $this->getJson('/api/v1/jobs/'.$job.'/photos')->assertOk()
            ->assertJsonCount(1,'data.photos')
            ->assertJsonPath('data.photos.0.category','before');
        $this->get('/api/v1/jobs/'.$job.'/photos/'.$photo.'/file')->assertOk()
            ->assertHeader('Cache-Control','private, no-store, max-age=0');
        $item = DB::table('job_photos')->find($photo);
        $this->assertSame($company->id,$item->company_id);
        Storage::disk('local')->put($item->private_path,'changed');
        $this->get('/api/v1/jobs/'.$job.'/photos/'.$photo.'/file')->assertStatus(409);
        $this->patchJson('/api/v1/jobs/'.$job.'/photos/'.$photo,[
            'category'=>'after','caption'=>'Finish',
        ])->assertOk()->assertJsonPath('data.photo.category','after');
        $this->deleteJson('/api/v1/jobs/'.$job.'/photos/'.$photo)->assertOk();
        $this->getJson('/api/v1/jobs/'.$job.'/photos')->assertJsonCount(0,'data.photos');
        $this->get('/api/v1/jobs/'.$job.'/photos/'.$photo.'/file')->assertNotFound();
    }

    public function test_private_notes_are_persisted_and_tenant_isolated(): void
    {
        Storage::fake('local');
        [$aUser,$a,$job] = $this->tenant('Company A');
        $photo = $this->upload($job);
        $note = $this->note($job);
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertOk()
            ->assertJsonPath('data.notes.0.visibility','private');
        $this->patchJson('/api/v1/jobs/'.$job.'/notes/'.$note,[
            'body'=>'Edited by professional',
        ])->assertOk()->assertJsonPath('data.note.body','Edited by professional');
        [$bUser,$b,$foreign] = $this->tenant('Company B');
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$job.'/photos')->assertNotFound();
        $this->get('/api/v1/jobs/'.$job.'/photos/'.$photo.'/file')->assertNotFound();
        $this->patchJson('/api/v1/jobs/'.$job.'/notes/'.$note,[
            'body'=>'Attempted theft',
        ])->assertNotFound();
        $this->postJson('/api/v1/jobs/'.$job.'/notes',[
            'title'=>'Foreign','body'=>'Foreign','type'=>'text','visibility'=>'private',
        ])->assertNotFound();
        $this->asTenant($aUser,$a);
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertJsonPath('data.notes.0.body','Edited by professional');
        $this->deleteJson('/api/v1/jobs/'.$job.'/notes/'.$note)->assertOk();
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertJsonCount(0,'data.notes');
    }

    private function asTenant(User $user, Company $company): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
    }

    public function test_only_explicit_approval_publishes_pdf_in_m9_documents(): void
    {
        Storage::fake('local');
        [$user,$company,$job] = $this->tenant('Conversion');
        $note = $this->note($job);
        Http::fake(['*api.openai.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'title'=>'Site visit report','content'=>'Glass was observed broken. Professional review required.',
            ])]]],
        ],200)]);
        $base = '/api/v1/jobs/'.$job.'/notes/'.$note.'/documents';
        $draft = $this->postJson($base.'/prepare',['document_type'=>'report'])
            ->assertCreated()->assertJsonPath('data.document.status','draft')
            ->json('data.document.id');
        $this->assertDatabaseCount('managed_documents',0);
        $this->postJson($base.'/'.$draft.'/approve',[])->assertUnprocessable();
        $this->patchJson($base.'/'.$draft,[
            'title'=>'Reviewed report','content'=>'Verified: glass cracked.',
        ])->assertOk()->assertJsonPath('data.document.title','Reviewed report');
        $result = $this->postJson($base.'/'.$draft.'/approve',['confirm'=>true])
            ->assertOk()->assertJsonPath('data.document.status','approved');
        $document = (int)$result->json('data.document.managed_document_id');
        $this->assertGreaterThan(0,$document);
        $this->get('/api/v1/documents/managed-'.$document.'/pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->postJson($base.'/'.$draft.'/approve',['confirm'=>true])->assertStatus(409);
        $this->assertDatabaseCount('managed_documents',1);
    }

    public function test_customer_facing_notes_cannot_be_secretly_converted(): void
    {
        Storage::fake('local');
        [$user,$company,$job] = $this->tenant('Privacy');
        $note = $this->note($job,'customer_facing');
        $this->postJson('/api/v1/jobs/'.$job.'/notes/'.$note.'/documents/prepare',[
            'document_type'=>'report',
        ])->assertForbidden();
        $this->assertDatabaseCount('job_note_documents',0);
    }

    public function test_admin_can_manage_job_media_using_existing_panel(): void
    {
        Storage::fake('local');
        [$owner,$company,$job] = $this->tenant('Admin');
        $this->upload($job);
        $this->note($job);
        $admin = User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/job-media')->assertOk()->assertSee('Photos & Notes Management');
        $this->get('/admin/job-media?kind=notes')->assertOk()->assertSee('Original observation');
        $this->post('/admin/job-media/notes',[
            'job_id'=>$job,'title'=>'Admin note','body'=>'Admin entry','visibility'=>'private',
        ])->assertRedirect();
        $photo = (int) DB::table('job_photos')->value('id');
        $this->patch('/admin/job-media/photos/'.$photo,[
            'category'=>'after','caption'=>'Admin edited',
        ])->assertRedirect();
        $this->assertDatabaseHas('job_photos',['id'=>$photo,'caption'=>'Admin edited']);
        $this->post('/admin/job-media/photos/'.$photo.'/archive')->assertRedirect();
        $this->assertNotNull(DB::table('job_photos')->where('id',$photo)->value('archived_at'));
    }
}
