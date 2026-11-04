<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stage 2: security regression checks around public routes and admin controls. */
final class M9DocumentReleaseSecurityTest extends TestCase
{
    use RefreshDatabase;
    private const PDF="%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF\n";

    private function fixture(): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>'M9 Company','owner_user_id'=>$owner->id,
            'status'=>'active','country_iso_code'=>'CH']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Client M9','source'=>'manual']);
        return [$owner,$company,$client];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('site.pdf',self::PDF);
    }

    public function test_company_member_can_read_but_cannot_create_or_replace_documents(): void
    {
        Storage::fake('local');
        [$owner,$company]=$this->fixture();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $created=$this->post('/api/v1/documents',[
            'title'=>'Internal report','category'=>'reports','file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertCreated();
        $id=$created->json('data.document.id');
        $member=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company->users()->attach($member->id,[
            'role'=>'member','status'=>'active',
            'permissions'=>json_encode(['documents.read']),
        ]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/documents')->assertOk();
        $this->getJson('/api/v1/documents/'.$id)->assertOk();
        $this->post('/api/v1/documents',[
            'title'=>'Blocked','category'=>'reports','file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertForbidden();
        $this->post('/api/v1/documents/'.$id.'/versions',[
            'file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertForbidden();
        $this->post('/api/v1/documents/'.$id.'/attachments',[
            'file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertForbidden();
        $this->assertDatabaseCount('managed_documents',1);
        $this->assertDatabaseCount('managed_document_versions',1);
        $this->assertDatabaseCount('document_attachments',0);
    }

    public function test_non_admins_cannot_open_platform_document_admin(): void
    {
        Storage::fake('local');
        [$owner,$company]=$this->fixture();
        $this->actingAs($owner);
        $this->get('/admin/documents')->assertForbidden();
        $this->post('/admin/documents',[
            'company_id'=>$company->id,'category'=>'reports','title'=>'Hidden',
            'file'=>$this->file(),
        ])->assertForbidden();
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/documents')->assertOk()
            ->assertSee('Document Management');
        $uploaded=$this->post('/admin/documents',[
            'company_id'=>$company->id,'category'=>'reports','title'=>'Admin report',
            'file'=>$this->file(),
        ])->assertRedirect();
        $key='managed-'.DB::table('managed_documents')->value('id');
        $uploaded->assertRedirect(route('admin.documents.show',$key));
        $this->get('/admin/documents/'.$key)->assertOk()->assertSee('Admin report');
        $this->get('/admin/documents/'.$key.'/pdf')->assertOk()
            ->assertContent(self::PDF);
    }

    public function test_unapproved_quote_cannot_be_downloaded_or_replaced(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->fixture();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $id=DB::table('service_quotes')->insertGetId([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'reference_number'=>'Q-Stage2-00001','client_name'=>'Client M9',
            'quote_date'=>'2026-11-04','currency'=>'CHF','status'=>'draft',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->getJson('/api/v1/documents/quote-'.$id)->assertOk()
            ->assertJsonPath('data.document.has_pdf',false);
        $this->get('/api/v1/documents/quote-'.$id.'/pdf')->assertStatus(409);
        $this->post('/api/v1/documents/quote-'.$id.'/versions',[
            'file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertStatus(409);
        $this->getJson('/api/v1/documents/quote-'.$id.'/history')
            ->assertOk()->assertJsonPath('data.events.0.type','created');
        $this->assertDatabaseCount('managed_document_versions',0);
    }

    public function test_wrong_company_client_cannot_be_linked_to_upload(): void
    {
        Storage::fake('local');
        [$owner,$company]=$this->fixture();
        [$otherOwner,$otherCompany,$otherClient]=$this->fixture();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->post('/api/v1/documents',[
            'title'=>'Forbidden client','category'=>'reports',
            'client_id'=>$otherClient->id,'file'=>$this->file(),
        ],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('managed_documents',0);
        $this->assertDatabaseCount('managed_document_versions',0);
    }
}
