<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M13WorkAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $label): array
    {
        $user=User::factory()->create([
            'is_active'=>true,'email_verified_at'=>now(),
        ]);
        $company=Company::create(['owner_user_id'=>$user->id,
            'name'=>$label,'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Jean Customer','email'=>'jean@example.test','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Renovation completion',
            'start_date'=>'2026-11-10','due_date'=>'2026-11-20',
            'team_member_ids'=>[],
        ])->assertCreated()->json('data.job.id');
        return [$user,$company,$client,(int)$job];
    }

    private function createAcceptance(int $job): int
    {
        return (int)$this->postJson('/api/v1/work-acceptances',[
            'job_id'=>$job,'completed_work'=>'Renovated and cleaned entrance.',
            'completion_date'=>'2026-11-14',
            'cleaning_status'=>'cleaned',
            'outstanding_issues'=>'Loose door handle',
            'remedial_work'=>'Replace loose handle',
        ])->assertCreated()->assertJsonPath('data.acceptance.status','draft')
            ->json('data.acceptance.id');
    }

    private function approve(int $id,int $version=1): array
    {
        return $this->postJson('/api/v1/work-acceptances/'.$id.'/approve',[
            'confirm'=>true,'revision'=>$version,
        ])->assertOk()->assertJsonPath('data.acceptance.status','approved')
            ->json('data.acceptance');
    }

    private function signingToken(object $report,int $companyId): array
    {
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $requestId=(string)Str::uuid();
        $docId=(string)Str::uuid();
        $consent=hash('sha256',$report->approved_pdf_sha256."\n".$requestId);
        $id=DB::table('work_acceptance_requests')->insertGetId([
            'company_id'=>$companyId,'work_acceptance_id'=>$report->id,
            'request_uuid'=>$requestId,'document_uuid'=>$docId,
            'token_sha256'=>hash('sha256',$token),'consent_reference'=>$consent,
            'approved_revision'=>$report->revision,
            'approved_pdf_sha256'=>$report->approved_pdf_sha256,
            'signer_name'=>'Jean Customer','signer_email'=>'jean@example.test',
            'status'=>'sent','expires_at'=>now()->addDays(7),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$token,$id,$requestId,$docId,$consent];
    }

    private function drawnPng(): string
    {
        $image=imagecreatetruecolor(360,110);
        $white=imagecolorallocate($image,255,255,255);
        $ink=imagecolorallocate($image,5,30,55);
        imagefill($image,0,0,$white);
        imagesetthickness($image,5);
        imageline($image,30,65,105,27,$ink);
        imageline($image,105,27,200,77,$ink);
        imageline($image,200,77,300,21,$ink);
        ob_start();imagepng($image);
        $png=ob_get_clean();imagedestroy($image);
        return base64_encode($png);
    }

    public function test_revision_lock_approval_frozen_pdf_and_request_lifecycle(): void
    {
        Storage::fake('local');Mail::fake();
        [$actor,$company,$client,$job]=$this->setupTenant('Acceptance Company');
        $id=$this->createAcceptance($job);
        $this->getJson('/api/v1/work-acceptances?job_id='.$job)
            ->assertOk()->assertJsonCount(1,'data.records');
        $this->patchJson('/api/v1/work-acceptances/'.$id,[
            'revision'=>1,'completed_work'=>'Completed kitchen renovation.',
        ])->assertOk()->assertJsonPath('data.acceptance.revision',2);
        $this->patchJson('/api/v1/work-acceptances/'.$id,[
            'revision'=>1,'completed_work'=>'Stale replacement.',
        ])->assertStatus(409);
        $this->postJson('/api/v1/work-acceptances/'.$id.'/approve',[
            'revision'=>1,'confirm'=>true,
        ])->assertStatus(409);
        $this->approve($id,2);
        $this->get('/api/v1/work-acceptances/'.$id.'/pdf/approved')->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        $this->patchJson('/api/v1/work-acceptances/'.$id,[
            'revision'=>2,'completed_work'=>'Unauthorized changes.',
        ])->assertStatus(409);
        $this->postJson('/api/v1/work-acceptances/'.$id.'/send',[
            'confirm'=>true,
        ])->assertOk()->assertJsonPath('data.report.status','sent');
        $this->assertDatabaseHas('work_acceptance_requests',[
            'work_acceptance_id'=>$id,'status'=>'sent',
        ]);
        $this->postJson('/api/v1/work-acceptances/'.$id.'/send',[
            'confirm'=>true,
        ])->assertStatus(409);
        $request=DB::table('work_acceptance_requests')
            ->where('work_acceptance_id',$id)->first();
        $this->postJson('/api/v1/work-acceptances/'.$id.'/requests/'.$request->id.'/revoke')
            ->assertOk()->assertJsonPath('data.acceptance.status','approved');
        $this->assertDatabaseHas('work_acceptance_requests',[
            'id'=>$request->id,'status'=>'revoked',
        ]);
        $this->assertDatabaseCount('managed_documents',0);
        $this->assertDatabaseCount('work_acceptance_revisions',2);
    }

    public function test_customer_reservations_signature_evidence_and_m9_immutability(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->setupTenant('Customer Acceptance');
        $id=$this->createAcceptance($job);
        $this->approve($id);
        $row=DB::table('work_acceptances')->find($id);
        [$token,$requestId,$uuid,$document,$consent]=$this->signingToken($row,$company->id);
        $this->get('/accept-work/'.$token)->assertOk()->assertSee('Work Acceptance')
            ->assertSee('Renovated and cleaned entrance.');
        $this->get('/accept-work/'.$token.'/approved-pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $fields=[
            'request_uuid'=>$uuid,'document_uuid'=>$document,
            'consent_reference'=>$consent,'revision'=>1,
            'decision'=>'accepted_with_reservations',
            'customer_name'=>'Jean Customer','reservation'=>'Door handle needs adjustment.',
            'outstanding_issues'=>'Small paint chip.',
            'rating'=>4,'comment'=>'Good progress but follow-up required.',
            'signature_png'=>$this->drawnPng(),
            'consent'=>'1',
        ];
        $this->post('/accept-work/'.$token.'/submit',$fields,
            ['Accept'=>'application/json'])
            ->assertOk()->assertJsonPath('data.report.status','accepted_with_reservations');
        $this->get('/accept-work/'.$token.'/receipt')->assertOk()
            ->assertSee('Accepted with Reservations');
        $this->get('/accept-work/'.$token.'/signed-pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        $this->assertDatabaseCount('work_acceptance_artifacts',1);
        $this->assertDatabaseCount('managed_documents',1);
        $this->assertDatabaseHas('work_acceptances',[
            'id'=>$id,'acceptance_result'=>'accepted_with_reservations',
            'customer_rating'=>4,
        ]);
        $managed=DB::table('work_acceptances')->where('id',$id)
            ->value('managed_document_id');
        $signed=$this->get('/api/v1/work-acceptances/'.$id.'/pdf/signed')
            ->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-',$signed);
        $this->get('/api/v1/work-acceptances/'.$id.'/signature')
            ->assertOk()->assertHeader('Content-Type','image/png');
        $replacement=UploadedFile::fake()->createWithContent('replacement.pdf',$signed);
        $this->post('/api/v1/documents/managed-'.$managed.'/versions',[
            'file'=>$replacement,
        ])->assertStatus(409);
        $this->assertDatabaseCount('managed_document_versions',1);
        // Test the one-use state even after clearing the request throttle
        // used to block repeated public submissions.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/accept-work/'.$token.'/submit',$fields,
            ['Accept'=>'application/json'])->assertStatus(410);
    }

    public function test_customer_photo_evidence_is_embedded_and_reservations_are_not_silent(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->setupTenant('Photos and Reservations');
        $id=$this->createAcceptance($job);
        $this->approve($id);
        $this->getJson('/api/v1/work-acceptances/'.$id.'/customer-photos')
            ->assertOk()->assertJsonCount(0,'data.photos');
        $record=DB::table('work_acceptances')->find($id);
        [$token,$signing,$requestUuid,$documentUuid,$consent]=$this->signingToken(
            $record,$company->id);
        $payload=[
            'request_uuid'=>$requestUuid,'document_uuid'=>$documentUuid,
            'consent_reference'=>$consent,'revision'=>1,
            'decision'=>'accepted_with_reservations',
            'customer_name'=>'Jean Customer','signature_png'=>$this->drawnPng(),
            'consent'=>'1',
        ];
        $this->post('/accept-work/'.$token.'/submit',$payload,
            ['Accept'=>'application/json'])->assertUnprocessable();
        $payload['photos']=[UploadedFile::fake()->image('evidence.png',48,48)];
        $this->post('/accept-work/'.$token.'/submit',$payload,
            ['Accept'=>'application/json'])->assertOk()
            ->assertJsonPath('data.report.acceptance_result','accepted_with_reservations');
        $this->assertDatabaseCount('work_acceptance_customer_photos',1);
        $photo=DB::table('work_acceptance_customer_photos')->first();
        $this->assertTrue(Storage::disk('local')->exists($photo->private_path));
        $this->assertSame($photo->sha256,hash('sha256',
            Storage::disk('local')->get($photo->private_path)));
        $this->get('/api/v1/work-acceptances/'.$id.'/pdf/signed')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->getJson('/api/v1/work-acceptances/'.$id.'/customer-photos')
            ->assertOk()->assertJsonCount(1,'data.photos')
            ->assertJsonPath('data.photos.0.id',$photo->id)
            ->assertJsonPath('data.photos.0.sha256',$photo->sha256);
        $this->get('/api/v1/work-acceptances/'.$id.'/customer-photos/'.$photo->id)
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->get('/api/v1/work-acceptances/'.$id.'/customer-photos/99999')
            ->assertNotFound();
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/work-acceptances/'.$id.'/edit')->assertOk()
            ->assertSee('Original Customer Photo Evidence')
            ->assertSee('Download Verified Original');
        $this->get('/admin/work-acceptances/'.$id.'/customer-photos/'.$photo->id)
            ->assertOk()->assertHeader('Content-Type','image/png');
        Sanctum::actingAs($user);
        Storage::disk('local')->put($photo->private_path,'tampered evidence');
        $this->get('/api/v1/work-acceptances/'.$id.'/customer-photos/'.$photo->id)
            ->assertStatus(409);
        // Photo tampering never rewrites or invalidates the already frozen PDF.
        $this->get('/api/v1/work-acceptances/'.$id.'/pdf/signed')
            ->assertOk()->assertHeader('Content-Type','application/pdf');
        [$otherUser,$otherCompany]=$this->setupTenant('Other Acceptance Tenant');
        $this->getJson('/api/v1/work-acceptances/'.$id.'/customer-photos')
            ->assertNotFound();
        $this->get('/api/v1/work-acceptances/'.$id.'/customer-photos/'.$photo->id)
            ->assertNotFound();
        $this->assertDatabaseCount('managed_document_versions',1);
    }

    public function test_invalid_customer_identity_consent_and_cross_company_access_are_blocked(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->setupTenant('Company A');
        $id=$this->createAcceptance($job);$this->approve($id);
        $row=DB::table('work_acceptances')->find($id);
        [$token,$requestId,$uuid,$document,$consent]=$this->signingToken($row,$company->id);
        $invalid=[
            'request_uuid'=>$uuid,'document_uuid'=>$document,
            'consent_reference'=>$consent,'revision'=>1,'decision'=>'accepted',
            'customer_name'=>'Wrong Customer','signature_png'=>$this->drawnPng(),
            'consent'=>'1',
        ];
        $this->post('/accept-work/'.$token.'/submit',$invalid,
            ['Accept'=>'application/json'])->assertUnprocessable();
        $invalid['customer_name']='Jean Customer';
        $invalid['consent_reference']=str_repeat('0',64);
        $this->post('/accept-work/'.$token.'/submit',$invalid,
            ['Accept'=>'application/json'])->assertForbidden();
        $this->assertDatabaseCount('work_acceptance_artifacts',0);
        [$other,$otherCompany,$otherClient,$otherJob]=$this->setupTenant('Company B');
        $this->getJson('/api/v1/work-acceptances/'.$id)->assertNotFound();
        $this->postJson('/api/v1/work-acceptances/'.$id.'/approve',[
            'revision'=>1,'confirm'=>true,
        ])->assertNotFound();
        $this->postJson('/api/v1/work-acceptances',[
            'job_id'=>$job,'completed_work'=>'Cross company',
            'completion_date'=>'2026-11-14','cleaning_status'=>'cleaned',
        ])->assertNotFound();
        $this->get('/api/v1/work-acceptances/'.$id.'/pdf/approved')
            ->assertNotFound();
    }

    public function test_admin_management_is_guarded_and_signed_reports_are_read_only(): void
    {
        Storage::fake('local');
        [$actor,$company,$client,$job]=$this->setupTenant('Platform Management');
        $id=$this->createAcceptance($job);
        $this->actingAs($actor);
        $this->get('/admin/work-acceptances')->assertForbidden();
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/work-acceptances')->assertOk()
            ->assertSee('Work Acceptance Management');
        $this->get('/admin/work-acceptances/'.$id.'/edit')->assertOk()
            ->assertSee('Renovated and cleaned entrance.');
        $this->post('/admin/work-acceptances/'.$id.'/approve',[
            'confirm'=>'1','revision'=>1,
        ])->assertRedirect();
        $this->get('/admin/work-acceptances/'.$id.'/edit')->assertOk()
            ->assertSee('Approved PDF');
    }
}
