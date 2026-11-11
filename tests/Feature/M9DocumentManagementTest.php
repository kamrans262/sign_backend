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

class M9DocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PDF_A = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF\n";
    private const PDF_B = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n2 0 obj\nendobj\n%%EOF\n";

    private function tenant(string $name = 'Company A'): array
    {
        $user=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['name'=>$name,'owner_user_id'=>$user->id,
            'status'=>'active','country_iso_code'=>'CH']);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Actual Customer','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$user,$company,$client];
    }

    private function upload(string $bytes=self::PDF_A): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('report.pdf',$bytes);
    }

    private function create(): string
    {
        return $this->post('/api/v1/documents',[
            'title'=>'Site report','category'=>'reports','file'=>$this->upload(),
        ],['Accept'=>'application/json'])->assertCreated()->json('data.document.id');
    }

    public function test_tenant_isolation_and_genuine_history(): void
    {
        Storage::fake('local');
        [$owner,$company]=$this->tenant();
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonPath('data.total',0);
        $id=$this->create();
        $this->getJson('/api/v1/documents')->assertOk()
            ->assertJsonPath('data.total',1)
            ->assertJsonPath('data.documents.0.id',$id);
        $this->getJson('/api/v1/documents/'.$id)->assertOk()
            ->assertJsonPath('data.history.0.type','created');
        $this->get('/api/v1/documents/'.$id.'/pdf')->assertOk()
            ->assertContent(self::PDF_A);
        $this->assertDatabaseHas('document_events',[
            'company_id'=>$company->id,'event_type'=>'downloaded',
        ]);
        $this->tenant('Company B');
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonPath('data.total',0);
        $this->getJson('/api/v1/documents/'.$id)->assertNotFound();
        $this->get('/api/v1/documents/'.$id.'/pdf')->assertNotFound();
        $this->post('/api/v1/documents/'.$id.'/attachments',[
            'file'=>$this->upload(),
        ],['Accept'=>'application/json'])->assertNotFound();
    }

    public function test_versions_append_without_overwriting_and_attachments_remain_separate(): void
    {
        Storage::fake('local');
        $this->tenant();
        $id=$this->create();
        $numericId=(int)substr($id,strlen('managed-'));
        $this->assertDatabaseHas('m23_document_usage',[
            'source_type'=>'managed_upload','source_id'=>$numericId,
            'issue_event'=>'first_creation',
        ]);
        $old=DB::table('managed_document_versions')->first();
        $this->post('/api/v1/documents/'.$id.'/versions',[
            'file'=>$this->upload(self::PDF_B),
        ],['Accept'=>'application/json'])->assertOk()
            ->assertJsonPath('data.document.version',2);
        $this->get('/api/v1/documents/'.$id.'/pdf')->assertOk()
            ->assertContent(self::PDF_B);
        $this->assertSame(self::PDF_A,Storage::disk('local')->get($old->private_path));
        $this->getJson('/api/v1/documents/'.$id.'/versions')
            ->assertOk()->assertJsonCount(2,'data.versions');
        $this->post('/api/v1/documents/'.$id.'/attachments',[
            'file'=>$this->upload(),
        ],['Accept'=>'application/json'])->assertCreated();
        $this->getJson('/api/v1/documents/'.$id.'/attachments')
            ->assertOk()->assertJsonCount(1,'data.attachments');
        $this->get('/api/v1/documents/'.$id.'/pdf')->assertOk()
            ->assertContent(self::PDF_B);
        // Versions and attachments must not consume another free creation.
        $this->assertDatabaseCount('m23_document_usage',1);
    }

    public function test_modified_private_pdf_is_rejected(): void
    {
        Storage::fake('local');
        $this->tenant();
        $id=$this->create();
        $version=DB::table('managed_document_versions')->first();
        Storage::disk('local')->put($version->private_path,self::PDF_B);
        $this->get('/api/v1/documents/'.$id.'/pdf')->assertStatus(503);
        $this->assertDatabaseMissing('document_events',['event_type'=>'downloaded']);
    }

    public function test_quote_sources_cannot_be_overwritten_as_uploaded_versions(): void
    {
        Storage::fake('local');
        [$user,$company,$client]=$this->tenant();
        $quoteId=DB::table('service_quotes')->insertGetId([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'reference_number'=>'Q-M9-001','client_name'=>'Actual Customer',
            'quote_date'=>'2026-11-04','currency'=>'CHF',
            'status'=>'draft','revision'=>1,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->post('/api/v1/documents/quote-'.$quoteId.'/versions',[
            'file'=>$this->upload(),
        ],['Accept'=>'application/json'])->assertStatus(409);
        $this->assertDatabaseCount('managed_document_versions',0);
        $this->get('/api/v1/documents/quote-'.$quoteId.'/pdf')->assertStatus(409);
    }
}
