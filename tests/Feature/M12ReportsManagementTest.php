<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M12ReportsManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): array
    {
        $user=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>$name,'owner_user_id'=>$user->id,
            'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,'full_name'=>'Jean Test',
            'email'=>'jean@example.test','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Window replacement',
            'start_date'=>'2026-11-10','due_date'=>'2026-11-20',
            'team_member_ids'=>[],
        ])->assertCreated()->json('data.job.id');
        return [$user,$company,$client,(int)$job];
    }

    private function make(int $job,string $kind='site_report'): int
    {
        return (int)$this->postJson('/api/v1/reports',[
            'job_id'=>$job,'kind'=>$kind,
            'title'=>$kind==='site_report'?'Site Report - Week 1':'Meeting Minutes',
            'report_date'=>'2026-11-12','prepared_by'=>'Site Inspector',
            'location'=>'Work site','weather'=>$kind==='site_report'?'Sunny':null,
            'content'=>$kind==='site_report'
                ? ['sections'=>['siteProgress'=>'Windows measured on site','notes'=>'Two broken panes found']]
                : ['sections'=>['summary'=>'Discussed repair schedule'],
                    'attendees'=>['Jean Test','Site Inspector'],'decisions'=>['Replace two panes'],
                    'outstanding_points'=>['Supplier final confirmation'],
                    'action_items'=>[['description'=>'Order glass','assignee'=>'Site Inspector','due_date'=>'2026-11-15']]],
        ])->assertCreated()->json('data.report.id');
    }

    public function test_site_report_creation_revisions_and_immutable_m9_pdf(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Company A');
        $id=$this->make($job);
        $this->getJson('/api/v1/reports')->assertOk()
            ->assertJsonCount(1,'data.reports');
        $this->getJson('/api/v1/reports/'.$id)->assertOk()
            ->assertJsonCount(1,'data.revisions')
            ->assertJsonPath('data.report.revision',1);
        $this->patchJson('/api/v1/reports/'.$id,[
            'revision'=>1,
            'content'=>['sections'=>['siteProgress'=>'Two windows replaced and inspected.']],
        ])->assertOk()->assertJsonPath('data.report.revision',2);
        $this->postJson('/api/v1/reports/'.$id.'/approve',[
            'confirm'=>true,'revision'=>1,
        ])->assertStatus(409);
        $this->assertDatabaseCount('managed_documents',0);
        $result=$this->postJson('/api/v1/reports/'.$id.'/approve',[
            'confirm'=>true,'revision'=>2,
        ])->assertOk()->assertJsonPath('data.report.status','approved');
        $document=$result->json('data.report.managed_document_id');
        $this->assertGreaterThan(0,(int)$document);
        $this->get('/api/v1/reports/'.$id.'/pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        $this->get('/api/v1/documents/managed-'.$document.'/pdf')->assertOk();
        $this->patchJson('/api/v1/reports/'.$id,[
            'revision'=>2,
            'title'=>'Tampered','content'=>['sections'=>['siteProgress'=>'Changed']],
        ])->assertStatus(409);
        $this->postJson('/api/v1/reports/'.$id.'/approve',[
            'confirm'=>true,'revision'=>2,
        ])->assertStatus(409);
        $this->assertDatabaseCount('managed_documents',1);
        $this->assertDatabaseCount('managed_document_versions',1);
        $this->assertDatabaseCount('site_report_revisions',2);
        $original=$this->get('/api/v1/reports/'.$id.'/pdf')->assertOk()->getContent();
        $this->assertDatabaseHas('site_report_events',[
            'site_report_id'=>$id,'event_type'=>'downloaded',
        ]);
        $replacement=UploadedFile::fake()->createWithContent('replacement.pdf',$original);
        $this->post('/api/v1/documents/managed-'.$document.'/versions',[
            'file'=>$replacement,
        ])->assertStatus(409);
        $this->assertDatabaseCount('managed_document_versions',1);
        $this->get('/api/v1/documents/managed-'.$document.'/pdf')->assertOk();
        $this->actingAs(User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]));
        $replacement=UploadedFile::fake()->createWithContent('replacement.pdf',$original);
        $this->post('/admin/documents/managed-'.$document.'/versions',[
            'file'=>$replacement,
        ])->assertStatus(409);
        $this->assertDatabaseCount('managed_document_versions',1);
        Sanctum::actingAs($actor);
        $version=DB::table('managed_document_versions')->where('managed_document_id',$document)->first();
        Storage::disk('local')->put($version->private_path,'tampered');
        $this->get('/api/v1/reports/'.$id.'/pdf')->assertStatus(409);
    }

    public function test_stale_editor_and_ai_revision_are_rejected(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Stale Editor');
        $id=$this->make($job);
        $this->patchJson('/api/v1/reports/'.$id,[
            'revision'=>1,'content'=>['sections'=>['siteProgress'=>'Updated by editor A']],
        ])->assertOk()->assertJsonPath('data.report.revision',2);
        $this->patchJson('/api/v1/reports/'.$id,[
            'revision'=>1,'content'=>['sections'=>['siteProgress'=>'Stale editor B']],
        ])->assertStatus(409);
        $this->patchJson('/api/v1/reports/'.$id,[
            'content'=>['sections'=>['siteProgress'=>'Missing revision']],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/reports/'.$id.'/prepare-ai',[
            'confirm'=>true,'revision'=>1,
        ])->assertStatus(409);
        $this->getJson('/api/v1/reports/'.$id)
            ->assertJsonPath('data.report.content.sections.siteProgress','Updated by editor A');
        $this->assertDatabaseCount('site_report_revisions',2);
    }

    public function test_site_report_photo_access_is_report_scoped_and_pdf_contains_image(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Photo Report');
        $id=$this->make($job);
        $file=UploadedFile::fake()->image('progress.jpg',40,40);
        $photo=(int)$this->post('/api/v1/jobs/'.$job.'/photos',[
            'file'=>$file,'category'=>'during','caption'=>'Real site image',
        ],['Accept'=>'application/json'])->assertCreated()->json('data.photo.id');
        $this->patchJson('/api/v1/reports/'.$id,[
            'revision'=>1,'photo_ids'=>[$photo],
        ])->assertOk()->assertJsonPath('data.report.revision',2);
        $this->getJson('/api/v1/reports/'.$id)
            ->assertJsonPath('data.photos.0.id',$photo)
            ->assertJsonPath('data.photos.0.mime_type','image/jpeg');
        $this->get('/api/v1/reports/'.$id.'/photos/'.$photo)
            ->assertOk()->assertHeader('Content-Type','image/jpeg');
        $this->get('/api/v1/reports/'.$id.'/photos/999')->assertNotFound();
        $this->postJson('/api/v1/reports/'.$id.'/approve',[
            'confirm'=>true,'revision'=>2,
        ])->assertOk();
        $this->get('/api/v1/reports/'.$id.'/pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->postJson('/api/v1/reports',[
            'job_id'=>$job,'kind'=>'site_report','title'=>'Too many photos',
            'report_date'=>'2026-11-12','prepared_by'=>'Inspector',
            'weather'=>'Sunny','location'=>'Client site',
            'content'=>['sections'=>['siteProgress'=>'All images checked']],
            'photo_ids'=>range(1,9),
        ])->assertUnprocessable();
        [$otherUser,$otherCompany,$otherClient,$otherJob]=$this->tenant('Other Photo Account');
        $this->get('/api/v1/reports/'.$id.'/photos/'.$photo)->assertNotFound();
    }

    public function test_voice_transcription_requires_selected_job_and_never_saves_report(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Voice Reports');
        $this->postJson('/api/v1/reports/voice/transcribe',[
            'job_id'=>$job,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('site_reports',0);
        config()->set('services.openai.key','m12-voice-test-key');
        Http::fake(['*api.openai.com/*'=>Http::response([
            'text'=>'Visited the site and checked the window frames.',
        ],200)]);
        $audio=str_repeat("\x00\x00",320);
        $wav='RIFF'.pack('V',36+strlen($audio)).'WAVEfmt '.
            pack('VvvVVvv',16,1,1,16000,32000,2,16).
            'data'.pack('V',strlen($audio)).$audio;
        $file=UploadedFile::fake()->createWithContent('site.wav',$wav);
        $this->post('/api/v1/reports/voice/transcribe',[
            'job_id'=>$job,'file'=>$file,
        ],['Accept'=>'application/json'])->assertOk()
            ->assertJsonPath('data.applied',false)
            ->assertJsonPath('data.approved',false)
            ->assertJsonPath('data.job_id',$job);
        $this->assertDatabaseCount('site_reports',0);
        $this->assertDatabaseCount('managed_documents',0);
    }

    public function test_meeting_minutes_are_structured_and_can_send_only_after_approval(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$actor,$company,$client,$job]=$this->tenant('Meeting Company');
        $id=$this->make($job,'meeting_minutes');
        $this->postJson('/api/v1/reports/'.$id.'/send',['confirm'=>true])
            ->assertStatus(409);
        $this->postJson('/api/v1/reports/'.$id.'/approve',[
            'confirm'=>true,'revision'=>1,
        ])->assertOk();
        $this->postJson('/api/v1/reports/'.$id.'/send',['confirm'=>true])
            ->assertOk()->assertJsonPath('data.report.status','sent');
        $this->getJson('/api/v1/reports/'.$id)->assertJsonPath(
            'data.report.content.attendees.0','Jean Test')
            ->assertJsonPath('data.report.content.outstanding_points.0','Supplier final confirmation')
            ->assertJsonPath('data.report.content.action_items.0.assignee','Site Inspector')
            ->assertJsonPath('data.report.content.action_items.0.due_date','2026-11-15');
        $this->assertDatabaseHas('site_reports',[
            'id'=>$id,'kind'=>'meeting_minutes','status'=>'sent',
        ]);
    }

    public function test_ai_is_review_only_and_does_not_publish(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('AI Reports');
        $id=$this->make($job);
        config()->set('services.openai.key','m12-test-only');
        Http::fake(['*api.openai.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'title'=>'Reviewed Site Report',
                'sections'=>['siteProgress'=>'Windows measured on site'],
            ])]]],
        ],200)]);
        $this->postJson('/api/v1/reports/'.$id.'/prepare-ai',['confirm'=>true,'revision'=>1])
            ->assertOk()->assertJsonPath('data.suggestion.applied',false)
            ->assertJsonPath('data.suggestion.approved',false);
        $this->getJson('/api/v1/reports/'.$id)->assertJsonPath('data.report.status','draft')
            ->assertJsonPath('data.report.title','Site Report - Week 1');
        $this->assertDatabaseCount('managed_documents',0);
    }

    public function test_rejects_foreign_jobs_reports_and_photos(): void
    {
        Storage::fake('local');
        [$aUser,$aCompany,$aClient,$aJob]=$this->tenant('Company A');
        $aReport=$this->make($aJob);
        [$bUser,$bCompany,$bClient,$bJob]=$this->tenant('Company B');
        $bReport=$this->make($bJob);
        $this->getJson('/api/v1/reports/'.$aReport)->assertNotFound();
        $this->patchJson('/api/v1/reports/'.$aReport,[
            'revision'=>1,
            'title'=>'Unauthorized','content'=>['sections'=>['notes'=>'Wrong account']],
        ])->assertNotFound();
        $this->postJson('/api/v1/reports',[
            'job_id'=>$aJob,'kind'=>'site_report','title'=>'Foreign Job',
            'report_date'=>'2026-11-12','prepared_by'=>'Test','weather'=>'Sunny',
            'content'=>['sections'=>['siteProgress'=>'Unauthorized']],
        ])->assertNotFound();
        $this->getJson('/api/v1/reports')->assertJsonCount(1,'data.reports');
        Sanctum::actingAs($aUser);
        $this->withHeader('X-Company-Id',(string)$aCompany->id);
        $this->getJson('/api/v1/reports/'.$bReport)->assertNotFound();
        $this->postJson('/api/v1/reports/'.$bReport.'/approve',[
            'confirm'=>true,'revision'=>1,
        ])->assertNotFound();
    }

    public function test_admin_report_management_is_protected(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Admin Reports');
        $report=$this->make($job);
        $this->actingAs($actor);
        $this->get('/admin/reports')->assertForbidden();
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/reports')->assertOk()->assertSee('Reports Management');
        $this->get('/admin/reports/'.$report.'/edit')->assertOk()->assertSee('Site Report - Week 1');
        $this->get('/admin/reports/create')->assertOk()->assertSee('Create Report');
        $this->post('/admin/reports/'.$report.'/approve',[
            'confirm'=>true,'revision'=>1,
        ])->assertRedirect();
    }

    public function test_report_validation_rejects_untrusted_content_and_missing_meeting_location(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->tenant('Validation');
        $this->postJson('/api/v1/reports',[
            'job_id'=>$job,'kind'=>'site_report','title'=>'Bad',
            'report_date'=>'2026-11-12','prepared_by'=>'Test','weather'=>'Sunny',
            'content'=>['some_hidden_field'=>'malicious'],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/reports',[
            'job_id'=>$job,'kind'=>'meeting_minutes','title'=>'Meeting',
            'report_date'=>'2026-11-12','prepared_by'=>'Test',
            'content'=>['attendees'=>['Client One']],
        ])->assertUnprocessable();
    }
}
