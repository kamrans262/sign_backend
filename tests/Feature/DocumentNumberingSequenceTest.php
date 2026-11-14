<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyDocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentNumberingSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): array
    {
        $owner = User::factory()->create([
            'email_verified_at'=>now(),'is_active'=>true,
        ]);
        $company = Company::create([
            'owner_user_id'=>$owner->id,
            'name'=>$name,'country_iso_code'=>'CH','status'=>'active',
        ]);
        $company->users()->attach($owner->id, ['role'=>'owner','status'=>'active']);
        return [$owner,$company];
    }

    public function test_all_eight_sequences_are_company_scoped_independent_and_rollback_safe(): void
    {
        [, $companyA] = $this->company('A');
        [, $companyB] = $this->company('B');
        $kinds = [
            'quote'=>'Q','invoice'=>'INV','addendum'=>'ADD',
            'site_report'=>'SR','meeting_minutes'=>'MM',
            'work_acceptance'=>'WA','general_document'=>'GD','upload'=>'DOC',
        ];
        foreach ($kinds as $kind => $prefix) {
            $first = DB::transaction(fn () => app(CompanyDocumentNumberService::class)
                ->next((int)$companyA->id,$kind));
            $second = DB::transaction(fn () => app(CompanyDocumentNumberService::class)
                ->next((int)$companyA->id,$kind));
            $independent = DB::transaction(fn () => app(CompanyDocumentNumberService::class)
                ->next((int)$companyB->id,$kind));
            $this->assertSame($prefix.'-001',$first);
            $this->assertSame($prefix.'-002',$second);
            $this->assertSame($prefix.'-001',$independent);
        }

        try {
            DB::transaction(function () use ($companyA): void {
                app(CompanyDocumentNumberService::class)
                    ->next((int)$companyA->id,'quote');
                throw new \RuntimeException('Simulate an unsuccessful creation.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulate an unsuccessful creation.', $exception->getMessage());
        }
        $this->assertSame('Q-003',
            DB::transaction(fn () => app(CompanyDocumentNumberService::class)
                ->next((int)$companyA->id,'quote')));
    }

    public function test_legacy_number_is_preserved_and_new_quotes_are_immutable(): void
    {
        [$owner,$company] = $this->company('Legacy company');
        $client = Client::create([
            'company_id'=>$company->id,'full_name'=>'Client A','source'=>'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string)$company->id);
        $payload = [
            'client_id'=>$client->id,'client_name'=>'Client A',
            'quote_date'=>'2026-11-14','currency'=>'CHF',
            'vat_basis_points'=>0,'deposit_basis_points'=>0,
            'source_method'=>'manual','terms'=>'Payment on completion',
            'quote_number'=>'USER-OVERRIDE-MUST-BE-IGNORED',
            'items'=>[[
                'title'=>'Paint the wall','description'=>'',
                'quantity'=>1,'unit_price_minor'=>2500,
            ]],
        ];
        $first = $this->postJson('/api/v1/quotes',$payload)
            ->assertCreated()->assertJsonPath('data.quote.reference_number','Q-001')
            ->assertJsonPath('data.quote.quote_number','Q-001')
            ->json('data.quote');
        $payload['quote_number']='Q-999';
        $second = $this->postJson('/api/v1/quotes',$payload)
            ->assertCreated()->assertJsonPath('data.quote.reference_number','Q-002')
            ->json('data.quote');
        $this->patchJson('/api/v1/quotes/'.$first['id'], $payload)
            ->assertOk()->assertJsonPath('data.quote.reference_number','Q-001');
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$first['id'],'reference_number'=>'Q-001',
            'custom_quote_number'=>null,
        ]);
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$second['id'],'reference_number'=>'Q-002',
        ]);

        // Different companies can legally use identical visible references.
        [$otherOwner,$otherCompany]=$this->company('Other company');
        $otherClient=Client::create([
            'company_id'=>$otherCompany->id,'full_name'=>'Client B',
            'source'=>'manual',
        ]);
        Sanctum::actingAs($otherOwner);
        $this->withHeader('X-Company-Id',(string)$otherCompany->id);
        $payload['client_id']=$otherClient->id;
        $this->postJson('/api/v1/quotes',$payload)->assertCreated()
            ->assertJsonPath('data.quote.reference_number','Q-001');
    }
}
