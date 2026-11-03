<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M8SignatureRequestFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name = 'Signing Business'): array
    {
        $owner = User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company = Company::create([
            'name'=>$name,'owner_user_id'=>$owner->id,
            'status'=>'active','country_iso_code'=>'CH',
        ]);
        $company->users()->attach($owner->id, ['role'=>'owner','status'=>'active']);
        $client = Client::create([
            'company_id'=>$company->id,
            'full_name'=>'Signing Customer','source'=>'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return [$owner, $company, $client];
    }

    private function approvedQuote(Client $client, bool $verifiedPdf = true): ServiceQuote
    {
        $created = $this->postJson('/api/v1/quotes', [
            'client_id'=>$client->id,
            'client_name'=>'Signing Customer',
            'client_email'=>'customer@example.test',
            'quote_date'=>'2026-11-04',
            'currency'=>'CHF',
            'vat_basis_points'=>810,
            'deposit_basis_points'=>3000,
            'terms'=>'Review and accept these terms before signing.',
            'items'=>[['title'=>'Approved repair','quantity'=>2,
                'unit_price_minor'=>12000]],
        ])->assertCreated();
        $id = $created->json('data.quote.id');
        $post = ['confirmed'=>'1','expected_revision'=>'1'];
        if ($verifiedPdf) {
            $post['file'] = UploadedFile::fake()->createWithContent('approved.pdf',
                "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF\n");
        }
        $this->post('/api/v1/quotes/'.$id.'/approve', $post, [
            'Accept'=>'application/json',
        ])->assertOk();
        return ServiceQuote::findOrFail($id);
    }

    private function createRequest(ServiceQuote $quote): array
    {
        $result = $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests', [
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test',
            'expires_in_days'=>3,
            'delivery'=>'link',
        ])->assertCreated()->assertJsonPath('data.delivered',false);
        return [$result->json('data.request.id'), $result->json('data.secure_link')];
    }

    private static function token(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    public function test_requires_frozen_pdf_and_explicit_authorized_quote(): void
    {
        Storage::fake('local');
        [$owner, $company, $client] = $this->tenant();
        $legacy = $this->approvedQuote($client, false);
        $this->postJson('/api/v1/quotes/'.$legacy->id.'/signature-requests', [
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test',
            'delivery'=>'link',
        ])->assertStatus(409);
        $this->assertDatabaseCount('signature_requests', 0);
    }

    public function test_token_is_hashed_and_public_customer_views_only_verified_document(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        [$id,$link] = $this->createRequest($quote);
        $token = self::token($link);
        $this->assertSame(43, strlen($token));
        $this->assertDatabaseHas('signature_requests', [
            'id'=>$id,'company_id'=>$company->id,'service_quote_id'=>$quote->id,
            'status'=>'ready','token_sha256'=>hash('sha256',$token),
            'approved_pdf_sha256'=>$quote->approved_pdf_sha256,
        ]);
        $this->assertDatabaseMissing('signature_requests',['token_sha256'=>$token]);
        $this->getJson('/api/public/signing/'.$token)->assertOk()
            ->assertJsonPath('quote.clientName','Signing Customer')
            ->assertJsonPath('request.requestId',
                \App\Models\SignatureRequest::findOrFail($id)->request_uuid)
            ->assertJsonPath('quote.items.0.unitPrice','120.00');
        $this->assertDatabaseHas('signature_requests', ['id'=>$id,'status'=>'viewed']);
        $this->assertDatabaseCount('signature_events', 2);
        $this->getJson('/api/public/signing/'.$token)->assertOk();
        $this->assertDatabaseCount('signature_events', 2);
        $this->get('/api/public/signing/'.$token.'/pdf')
            ->assertOk()->assertHeader('Content-Type','application/pdf')
            ->assertContent(Storage::disk('local')->get($quote->approved_pdf_path));
        $this->get('/sign/'.$token)->assertOk()->assertSee('Approved repair');
        $this->assertDatabaseCount('signed_document_artifacts', 0);
    }

    public function test_denies_cross_company_requests_and_mismatched_signer(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        [$id,$link] = $this->createRequest($quote);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Attacker',
            'signer_email'=>'attacker@example.test','delivery'=>'link',
        ])->assertStatus(422);

        [$other,$anotherCompany] = $this->tenant('Unrelated business');
        $this->getJson('/api/v1/signature-requests/'.$id)->assertNotFound();
        $this->postJson('/api/v1/signature-requests/'.$id.'/revoke', [])
            ->assertNotFound();
        $this->getJson('/api/v1/quotes/'.$quote->id.'/signature-requests')
            ->assertNotFound();
    }

    public function test_revoke_and_archive_invalidate_customer_access(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        [$id,$link] = $this->createRequest($quote);
        $token = self::token($link);
        $this->postJson('/api/v1/signature-requests/'.$id.'/revoke',[])
            ->assertOk()->assertJsonPath('data.request.status','revoked');
        $this->getJson('/api/public/signing/'.$token)->assertStatus(410);
        $this->assertDatabaseCount('signed_document_artifacts',0);

        [$id2,$link2] = $this->createRequest($quote);
        $this->deleteJson('/api/v1/quotes/'.$quote->id)->assertOk();
        $this->getJson('/api/public/signing/'.self::token($link2))->assertStatus(410);
    }

    public function test_expires_and_rejects_bad_tokens_without_creating_a_signature(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        [$id,$link] = $this->createRequest($quote);
        $this->getJson('/api/public/signing/123')->assertNotFound();
        $this->getJson('/api/public/signing/'.str_repeat('x',43))->assertNotFound();
        $future = now()->addDays(4);
        $this->travelTo($future);
        try {
            $this->getJson('/api/public/signing/'.self::token($link))
                ->assertStatus(410);
        } finally {
            $this->travelBack();
        }
        $this->assertDatabaseCount('signed_document_artifacts',0);
    }

    public function test_existing_live_request_blocks_duplicate_and_email_requires_transport(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        $this->createRequest($quote);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test','delivery'=>'link',
        ])->assertStatus(409);
        config(['mail.default'=>'log']);
        $this->postJson('/api/v1/quotes/'.$quote->id.'/signature-requests',[
            'signer_name'=>'Signing Customer',
            'signer_email'=>'customer@example.test','delivery'=>'email',
        ])->assertStatus(503);
        $this->assertDatabaseCount('signature_requests',1);
    }

    public function test_reopening_request_can_rotate_copy_link_without_faking_delivery(): void
    {
        Storage::fake('local');
        [$owner,$company,$client]=$this->tenant();
        $quote=$this->approvedQuote($client);
        [$id,$old]=$this->createRequest($quote);
        $response=$this->postJson('/api/v1/signature-requests/'.$id.'/rotate-link',[])
            ->assertOk()->assertJsonPath('data.delivered',false);
        $new=$response->json('data.secure_link');
        $this->assertNotSame($old,$new);
        $this->getJson('/api/public/signing/'.self::token($old))->assertNotFound();
        $this->getJson('/api/public/signing/'.self::token($new))->assertOk();
        $this->assertDatabaseHas('signature_requests',[
            'id'=>$id,'token_sha256'=>hash('sha256',self::token($new)),
            'status'=>'viewed',
        ]);
        $this->assertDatabaseCount('signed_document_artifacts',0);
    }

    public function test_platform_admin_can_inspect_and_revoke_but_not_forge_completion(): void
    {
        Storage::fake('local');
        [$owner,$company,$client] = $this->tenant();
        $quote = $this->approvedQuote($client);
        [$id,$link] = $this->createRequest($quote);
        $admin = User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/signatures')->assertOk()
            ->assertSee('Digital Signatures')->assertSee('Signing Customer');
        $this->get('/admin/signatures/'.$id)->assertOk()
            ->assertSee('Approved PDF SHA-256');
        $this->post('/admin/signatures/'.$id.'/revoke')->assertRedirect();
        $this->assertDatabaseHas('signature_requests',['id'=>$id,'status'=>'revoked']);
        $this->assertDatabaseCount('signed_document_artifacts',0);
    }
}
