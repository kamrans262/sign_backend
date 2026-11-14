<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M6ServiceQuoteManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email): User
    {
        return User::factory()->create(['email'=>$email,'email_verified_at'=>now(),'is_active'=>true]);
    }
    private function company(User $owner,string $name): Company
    {
        $company=Company::create(['owner_user_id'=>$owner->id,
            'name'=>$name,'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        return $company;
    }
    private function client(Company $company): Client
    {
        return Client::create(['company_id'=>$company->id,
            'full_name'=>'Marc Dupont','source'=>'manual']);
    }
    private function asTenant(User $user,Company $company): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
    }
    private function draft(?Client $client): array
    {
        return [
            'client_id'=>$client?->id,
            'client_name'=>'Marc Dupont',
            'client_email'=>'marc@example.test',
            'billing_address'=>'Rue du Lac',
            'quote_date'=>'2026-11-03',
            'currency'=>'CHF',
            'vat_basis_points'=>810,
            'deposit_basis_points'=>3000,
            'terms'=>'30-day payment terms',
            'source_method'=>'text',
            'items'=>[
                [
                    'title'=>'Install windows','description'=>'White PVC',
                    'quantity'=>3,'dimensions'=>'1.2m x 1.5m',
                    'unit_price_minor'=>12345,'other_fields'=>[],
                    'unresolved_fields'=>[],
                ],
            ],
        ];
    }

    public function test_quote_crud_uses_integer_money_revision_audit_approval_and_signed_share(): void
    {
        $owner=$this->owner('owner@example.test');
        $company=$this->company($owner,'C');
        $client=$this->client($company);
        $this->asTenant($owner,$company);
        $created=$this->postJson('/api/v1/quotes',$this->draft($client))
            ->assertCreated()->assertJsonPath('data.quote.status','draft')
            ->assertJsonPath('data.quote.subtotal_minor',37035);
        $id=$created->json('data.quote.id');
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$id,'company_id'=>$company->id,
            'reference_number'=>'Q-001',
            'vat_minor'=>3000,
        ]);
        $this->assertDatabaseCount('service_quote_items',1);
        $this->assertDatabaseCount('service_quote_revisions',1);
        $this->getJson('/api/v1/quotes')->assertOk()->assertJsonCount(1,'data.quotes');
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonCount(1,'data.quote.items');
        $change=$this->draft($client);
        $change['items'][0]['unit_price_minor']=10000;
        $this->patchJson('/api/v1/quotes/'.$id,$change)->assertOk()
            ->assertJsonPath('data.quote.subtotal_minor',30000)
            ->assertJsonPath('data.quote.revision',2);
        $this->assertDatabaseCount('service_quote_revisions',2);
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])
            ->assertOk()->assertJsonPath('data.quote.status','approved');
        $this->patchJson('/api/v1/quotes/'.$id,$change)->assertStatus(409);
        $this->getJson('/api/v1/quotes/'.$id.'/revisions')
            ->assertOk()->assertJsonCount(3,'data.revisions');
        $link=$this->postJson('/api/v1/quotes/'.$id.'/share',['method'=>'secure_link'])
            ->assertOk()->assertJsonPath('data.delivered',false)
            ->json('data.secure_link');
        $this->assertIsString($link);
        $this->getJson($link)->assertOk()->assertJsonPath('data.quote.status','approved');
        $this->get($link)->assertOk()->assertSee('Install windows')
            ->assertSee('Quote')->assertDontSee('Draft: fictional');
        $this->get('/quote-view/'.$id)->assertForbidden();
        Mail::fake();
        config(['mail.default'=>'smtp']);
        $this->postJson('/api/v1/quotes/'.$id.'/share',[
            'method'=>'email','recipient_email'=>'marc@example.test',
            'message'=>'Please review the details.',
        ])->assertOk()->assertJsonPath('data.delivered',true);
        // Mail::fake intercepts actual dispatch; no SMTP is reached in tests.
        $this->assertDatabaseHas('service_quotes',['id'=>$id,'status'=>'sent']);
        $this->deleteJson('/api/v1/quotes/'.$id)->assertOk();
        $this->assertDatabaseHas('service_quotes',['id'=>$id,'status'=>'archived']);
    }

    public function test_foreign_company_job_and_client_are_rejected_and_company_id_spoofing_does_not_work():void
    {
        $aUser=$this->owner('a@example.test');
        $bUser=$this->owner('b@example.test');
        $a=$this->company($aUser,'A');
        $b=$this->company($bUser,'B');
        $aClient=$this->client($a);
        $bClient=$this->client($b);
        $this->asTenant($aUser,$a);
        $id=$this->postJson('/api/v1/quotes',$this->draft($aClient))
            ->assertCreated()->json('data.quote.id');
        $this->asTenant($bUser,$b);
        $this->getJson('/api/v1/quotes/'.$id)->assertNotFound();
        $this->get('/api/v1/quotes/'.$id.'/approved-pdf')->assertNotFound();
        $this->patchJson('/api/v1/quotes/'.$id,$this->draft($bClient))->assertNotFound();
        $this->deleteJson('/api/v1/quotes/'.$id)->assertNotFound();
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])->assertNotFound();
        $this->postJson('/api/v1/quotes',$this->draft($aClient))->assertUnprocessable();
        $created=$this->postJson('/api/v1/quotes',array_merge($this->draft($bClient),[
            'company_id'=>$a->id,'reference_number'=>'FORGED',
            'status'=>'approved','approved_at'=>'2020-01-01',
        ]))->assertCreated();
        $created->assertJsonPath('data.quote.company_id',$b->id)
            ->assertJsonPath('data.quote.status','draft');
        $this->asTenant($aUser,$b);
        $this->getJson('/api/v1/quotes')->assertForbidden();
    }

    public function test_missing_prices_or_terms_can_save_draft_but_cannot_approve():void
    {
        $owner=$this->owner('owner@example.test');
        $company=$this->company($owner,'A');
        $client=$this->client($company);
        $this->asTenant($owner,$company);
        $payload=$this->draft($client);
        $payload['terms']='';
        $payload['items'][0]['unit_price_minor']=null;
        $id=$this->postJson('/api/v1/quotes',$payload)->assertCreated()
            ->assertJsonPath('data.quote.total_minor',null)->json('data.quote.id');
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])
            ->assertUnprocessable();
        $payload['terms']='Signed professional terms';
        $payload['items'][0]['unit_price_minor']=10000;
        $payload['items'][0]['unresolved_fields']=['dimensions'];
        $this->patchJson('/api/v1/quotes/'.$id,$payload)->assertOk();
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])
            ->assertUnprocessable();
        $payload['items'][0]['unresolved_fields']=[];
        $this->patchJson('/api/v1/quotes/'.$id,$payload)->assertOk();
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])->assertOk();
    }

    public function test_quote_permissions_revoke_and_ai_key_absent_returns_unavailable_without_faking_ai():void
    {
        $owner=$this->owner('owner@example.test');
        $member=$this->owner('member@example.test');
        $company=$this->company($owner,'A');
        $company->users()->attach($member->id,['role'=>'member','status'=>'active',
            'permissions'=>json_encode(['quotes.read','quotes.write'])]);
        $this->asTenant($member,$company);
        config(['services.openai.key'=>null]);
        $this->postJson('/api/v1/quotes/prepare/text',['text'=>'Install 3 windows'])
            ->assertStatus(503);
        $company->users()->updateExistingPivot($member->id,['permissions'=>json_encode(['quotes.read'])]);
        $this->postJson('/api/v1/quotes/prepare/text',['text'=>'Install 3 windows'])
            ->assertForbidden();
        $this->postJson('/api/v1/quotes',$this->draft(null))->assertForbidden();
        $this->getJson('/api/v1/quotes')->assertOk();
        $company->users()->updateExistingPivot($member->id,['status'=>'inactive']);
        $this->getJson('/api/v1/quotes')->assertForbidden();
    }

    public function test_ai_extracts_unpriced_items_without_exposing_key_and_ignores_fabricated_prices():void
    {
        $owner=$this->owner('owner@example.test');
        $company=$this->company($owner,'A');
        $this->asTenant($owner,$company);
        config(['services.openai.key'=>'test-key']);
        Http::fake(['https://api.openai.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'items'=>[['title'=>'Install windows','description'=>'3 standard windows',
                    'quantity'=>3,'dimensions'=>null,'unit_price'=>999999]],
                'location'=>null,'notes'=>null,'currency'=>null,
                'vat'=>50,'price'=>9000,
            ])]]],
        ],200)]);
        $result=$this->postJson('/api/v1/quotes/prepare/text',[
            'text'=>'Install 3 windows, unknown dimensions and no prices',
        ])->assertOk()->assertJsonCount(1,'data.snapshot.items');
        $result->assertJsonPath('data.snapshot.items.0.quantity',3);
        $this->assertArrayNotHasKey('unit_price',$result->json('data.snapshot.items.0'));
        $this->assertArrayNotHasKey('vat',$result->json('data.snapshot'));
    }


    public function test_short_clear_quote_prompt_is_accepted_without_inventing_missing_values(): void
    {
        $owner=$this->owner('short-quote@example.test');
        $company=$this->company($owner,'Short prompt');
        $this->asTenant($owner,$company);
        config(['services.openai.key'=>'test-key']);
        Http::fake(['https://api.openai.com/v1/chat/completions'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'items'=>[['title'=>'Paint bedroom','description'=>null,
                    'quantity'=>null,'dimensions'=>null]],
                'location'=>null,'notes'=>null,'currency'=>null,
                'clarification_question'=>null,
            ])]]],
        ],200)]);
        $this->postJson('/api/v1/quotes/prepare/text',['text'=>'Paint bedroom'])
            ->assertOk()
            ->assertJsonPath('data.snapshot.stage','completed')
            ->assertJsonPath('data.snapshot.items.0.title','Paint bedroom')
            ->assertJsonPath('data.snapshot.items.0.quantity',null)
            ->assertJsonPath('data.snapshot.items.0.dimensions',null);
        // One- and two-character inputs are not a validation failure either.
        $this->postJson('/api/v1/quotes/prepare/text',['text'=>'Hi'])->assertOk();
    }

    public function test_vague_quote_prompt_returns_clarification_instead_of_422(): void
    {
        $owner=$this->owner('unclear-quote@example.test');
        $company=$this->company($owner,'Clarify quote');
        $this->asTenant($owner,$company);
        config(['services.openai.key'=>'test-key']);
        Http::fake(['https://api.openai.com/v1/chat/completions'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'items'=>[], 'location'=>null, 'notes'=>null, 'currency'=>null,
                'clarification_question'=>'What work is needed in the bathroom?',
            ])]]],
        ],200)]);
        $this->postJson('/api/v1/quotes/prepare/text',[
            'text'=>'Bathroom quote',
        ])->assertOk()
            ->assertJsonPath('data.snapshot.stage','needs_clarification')
            ->assertJsonPath('data.snapshot.items',[])
            ->assertJsonPath('data.snapshot.clarification_question',
                'What work is needed in the bathroom?');
        $this->postJson('/api/v1/quotes/prepare/text',[
            'text'=>'Bathroom quote',
            'clarification'=>'Replace the bathroom sink',
        ])->assertOk()->assertJsonPath('data.snapshot.stage','needs_clarification');
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/chat/completions' &&
            str_contains($request['messages'][1]['content'][0]['text'],
                'Replace the bathroom sink'));
        // No fabricated line is inserted if extraction remains unclear.
        $this->assertDatabaseCount('service_quotes',0);
        $this->assertDatabaseCount('service_quote_items',0);
    }

    public function test_quote_voice_accepts_m4a_and_mp4_detected_by_audio_signatures(): void
    {
        $owner = $this->owner('quote-voice@example.test');
        $company = $this->company($owner, 'M6 Voice');
        $this->asTenant($owner, $company);
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Install 3 new windows',
            ], 200),
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'items' => [[
                        'title' => 'Install windows',
                        'description' => 'Three new windows',
                        'quantity' => 3,
                        'dimensions' => null,
                    ]],
                    'location' => null, 'notes' => null, 'currency' => 'GBP',
                ])]]],
            ], 200),
        ]);

        // Fileinfo recognizes these MP4 containers as audio/x-m4a or
        // video/mp4 depending on the ftyp brand and platform.
        foreach ([
            'm4a' => "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom",
            'mp4' => "\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isommp42",
        ] as $extension => $source) {
            $file = UploadedFile::fake()->createWithContent(
                'voice.'.$extension, $source
            );
            $this->post('/api/v1/quotes/prepare/audio', ['file' => $file], [
                'Accept' => 'application/json',
            ])->assertOk()
              ->assertJsonPath('data.snapshot.stage', 'completed')
              ->assertJsonPath('data.snapshot.items.0.title', 'Install windows')
              ->assertJsonPath('data.snapshot.items.0.quantity', 3);
        }
        Http::assertSentCount(4); // Both transcription and extraction for both files.
    }

    public function test_quote_voice_rejects_non_audio_bytes_before_openai(): void
    {
        $owner = $this->owner('invalid-quote-voice@example.test');
        $company = $this->company($owner, 'M6 Bad Voice');
        $this->asTenant($owner, $company);
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $file = UploadedFile::fake()->createWithContent(
            'voice.m4a', 'This is not an audio recording. The filename is misleading.'
        );
        $this->post('/api/v1/quotes/prepare/audio', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        Http::assertNothingSent();
    }

    public function test_admin_management_uses_real_records_and_protects_company_assignment():void
    {
        $owner=$this->owner('owner@example.test');
        $a=$this->company($owner,'A');
        $client=$this->client($a);
        $admin=$this->owner('admin@example.test');
        $admin->forceFill(['is_platform_admin'=>true])->save();
        $this->actingAs($admin);
        $this->get('/admin/quotes')->assertOk()->assertSee('Quote Management');
        $this->get('/admin/quotes/create')->assertOk()->assertSee('Work Items');
        $request=[
            'company_id'=>$a->id,'client_id'=>$client->id,
            'client_name'=>'Marc','quote_date'=>'2026-11-03','currency'=>'CHF',
            'vat_basis_points'=>0,'deposit_basis_points'=>0,'terms'=>'Invoice terms',
            'items'=>[['title'=>'Replace glass','quantity'=>1,'unit_price'=>'125.50']],
        ];
        $this->post('/admin/quotes',$request)->assertRedirect();
        $quote=ServiceQuote::firstOrFail();
        $this->assertEquals(12550,$quote->total_minor);
        $this->get('/admin/quotes/'.$quote->id.'/edit')->assertOk()->assertSee('Replace glass');
        $this->post('/admin/quotes/'.$quote->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('service_quotes',['id'=>$quote->id,'status'=>'approved']);
        $this->delete('/admin/quotes/'.$quote->id)->assertRedirect();
        $this->post('/admin/quotes/'.$quote->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('service_quotes',['id'=>$quote->id,'status'=>'approved']);
        $this->assertDatabaseCount('service_quote_revisions',2);
    }
    public function test_m7_approval_rejects_stale_reviewed_revision_and_preserves_draft(): void
    {
        $owner = $this->owner('m7-owner@example.test');
        $company = $this->company($owner, 'M7 Revision');
        $client = $this->client($company);
        $this->asTenant($owner, $company);
        $created = $this->postJson('/api/v1/quotes', $this->draft($client))
            ->assertCreated()->assertJsonPath('data.quote.revision', 1);
        $id = $created->json('data.quote.id');
        $changed = $this->draft($client);
        $changed['items'][0]['unit_price_minor'] = 23456;
        $changed['expected_revision'] = 1;
        $this->patchJson('/api/v1/quotes/'.$id, $changed)
            ->assertOk()->assertJsonPath('data.quote.revision', 2);

        $this->postJson('/api/v1/quotes/'.$id.'/approve', [
            'confirmed' => true, 'expected_revision' => 1,
        ])->assertStatus(409);
        $this->assertDatabaseHas('service_quotes', [
            'id' => $id, 'status' => 'draft', 'revision' => 2,
        ]);
        $this->assertDatabaseCount('service_quote_revisions', 2);

        $this->postJson('/api/v1/quotes/'.$id.'/approve', [
            'confirmed' => true, 'expected_revision' => 2,
        ])->assertOk()->assertJsonPath('data.quote.status', 'approved')
            ->assertJsonPath('data.quote.revision', 3);
        $this->assertDatabaseCount('service_quote_revisions', 3);
        $this->postJson('/api/v1/quotes/'.$id.'/approve', [
            'confirmed' => true, 'expected_revision' => 2,
        ])->assertStatus(409);
    }

}
