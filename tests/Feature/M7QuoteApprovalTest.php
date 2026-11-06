<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class M7QuoteApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminDraft(): ServiceQuote
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'owner_user_id'=>$owner->id,
            'name'=>'M7 Admin Test Company',
            'country_iso_code'=>'CH',
            'status'=>'active',
        ]);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create([
            'company_id'=>$company->id,'full_name'=>'Test Customer','source'=>'manual',
        ]);
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->post('/admin/quotes',[
            'company_id'=>$company->id,'client_id'=>$client->id,
            'client_name'=>'Test Customer','quote_date'=>'2026-11-03',
            'currency'=>'CHF','vat_basis_points'=>810,'deposit_basis_points'=>3000,
            'terms'=>'Payment in 30 days',
            'items'=>[['title'=>'Measured repair','quantity'=>2,'unit_price'=>'80.00']],
        ])->assertRedirect();
        return ServiceQuote::firstOrFail();
    }

    public function test_admin_approval_rejects_stale_revision_and_uses_latest_review(): void
    {
        $quote=$this->createAdminDraft();
        $this->get('/admin/quotes/'.$quote->id.'/edit')->assertOk()
            ->assertSee('name="expected_revision"',false);
        $this->assertSame(1,(int)$quote->revision);
        // Simulate a real second edit via the administrator endpoint rather
        // than manipulating the revision counter directly in the database.
        $this->put('/admin/quotes/'.$quote->id, [
            'expected_revision'=>1,
            'company_id'=>$quote->company_id,
            'client_id'=>$quote->client_id,
            'client_name'=>'Test Customer',
            'quote_date'=>'2026-11-03',
            'currency'=>'CHF',
            'vat_basis_points'=>810,
            'deposit_basis_points'=>3000,
            'terms'=>'Updated after prior review',
            'items'=>[['title'=>'Measured repair','quantity'=>2,'unit_price'=>'90.00']],
        ])->assertRedirect();
        $this->assertDatabaseHas('service_quotes', [
            'id'=>$quote->id,'revision'=>2,'total_minor'=>19458,
        ]);
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>1,
        ])->assertStatus(409);
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'draft','revision'=>2,
        ]);
        $this->assertDatabaseCount('service_quote_revisions',2);
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>2,
        ])->assertRedirect();
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'approved','revision'=>3,
        ]);
        $this->assertDatabaseCount('service_quote_revisions',3);
        // A verified approval consumes one free-document allowance, not an edit.
        $this->assertDatabaseHas('m23_document_usage',[
            'company_id'=>$quote->company_id,'source_type'=>'quote',
            'source_id'=>$quote->id,'issue_event'=>'first_issuance',
        ]);
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>2,
        ])->assertStatus(409);
        $this->assertDatabaseCount('service_quote_revisions',3);
    }

    public function test_admin_does_not_approve_draft_with_no_items(): void
    {
        $quote=$this->createAdminDraft();
        $quote->items()->delete();
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>1,
        ])->assertSessionHasErrors('quote');
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'draft','revision'=>1,
        ]);
    }
    public function test_admin_archive_restore_preserves_approved_state_and_revokes_old_link_version(): void
    {
        $quote=$this->createAdminDraft();
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>1,
        ])->assertRedirect();
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'approved','revision'=>2,'link_version'=>1,
        ]);

        $this->delete('/admin/quotes/'.$quote->id)->assertRedirect();
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'archived','status_before_archive'=>'approved',
            'link_version'=>2,
        ]);
        $this->post('/admin/quotes/'.$quote->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'approved','link_version'=>2,
        ]);
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>2,
        ])->assertStatus(409);
    }

    public function test_admin_can_freeze_and_download_the_exact_approved_pdf(): void
    {
        Storage::fake('local');
        $quote=$this->createAdminDraft();
        $pdf="%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF\n";
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>1,
            'file'=>UploadedFile::fake()->createWithContent('approved.pdf',$pdf),
        ])->assertRedirect();
        $fresh=ServiceQuote::findOrFail($quote->id);
        $this->assertSame('approved',$fresh->status);
        $this->assertSame(2,(int)$fresh->approved_pdf_revision);
        $this->assertSame(hash('sha256',$pdf),$fresh->approved_pdf_sha256);
        $this->assertSame($pdf,Storage::disk('local')->get($fresh->approved_pdf_path));
        $this->get('/admin/quotes/'.$quote->id.'/approved-pdf')
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertContent($pdf);
        $this->get('/admin/quotes/'.$quote->id.'/edit')
            ->assertOk()->assertSee('Download Approved PDF');
    }

    public function test_admin_rejects_invalid_pdf_without_approving(): void
    {
        Storage::fake('local');
        $quote=$this->createAdminDraft();
        $this->post('/admin/quotes/'.$quote->id.'/approve',[
            'expected_revision'=>1,
            'file'=>UploadedFile::fake()->createWithContent('fake.pdf','not a PDF'),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$quote->id,'status'=>'draft','revision'=>1,
        ]);
        $this->get('/admin/quotes/'.$quote->id.'/approved-pdf')
            ->assertStatus(409);
    }

}
