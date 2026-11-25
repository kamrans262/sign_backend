<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiVoiceFormsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function recording(): UploadedFile
    {
        // Minimal MP4/m4a signature. No real audio leaves the test process.
        return UploadedFile::fake()->createWithContent(
            'voice.m4a', "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom"
        );
    }

    public function test_first_company_ai_is_available_before_company_creation_but_never_saves_data(): void
    {
        config(['services.openai.key' => 'test-api-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'My company is Alpine Construction AG'], 200),
            'https://api.openai.com/v1/chat/completions' =>
                Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'companyName' => 'Alpine Construction AG',
                        'address' => null, 'countryIsoCode' => 'CH',
                        'dialingCode' => null, 'phone' => null,
                        'email' => 'office@alpine.example', 'swissCompanyId' => null,
                        'vatNumber' => null, 'iban' => null,
                        'qrIban' => null, 'documentNumbering' => null,
                    ]),
                ]]]], 200),
        ]);
        Sanctum::actingAs($this->account());
        \Illuminate\Support\Facades\DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>auth()->id()], ['version'=>\App\Http\Middleware\M25AiConsent::VERSION,'allowed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->postJson('/api/v1/ai/companies/voice', [
            'file' => $this->recording(),
        ])->assertOk()
            ->assertJsonPath('data.suggestions.companyName', 'Alpine Construction AG')
            ->assertJsonPath('data.suggestions.countryIsoCode', 'CH')
            ->assertJsonPath('data.requires_user_review', true);
        $this->assertDatabaseCount('companies', 0);
        Http::assertSentCount(2);
    }

    public function test_job_ai_requires_current_company_write_permission_and_extracts_without_saving(): void
    {
        config(['services.openai.key' => 'test-api-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'Build a terrace, budget 5000 CHF'], 200),
            'https://api.openai.com/v1/chat/completions' =>
                Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'clientName' => null, 'address' => null,
                        'title' => 'Terrace Construction',
                        'description' => 'Build a terrace',
                        'startDate' => null, 'endDate' => null,
                        'budgetChf' => 5000, 'teamMemberNames' => [],
                    ]),
                ]]]], 200),
        ]);
        $owner = $this->account();
        $company = Company::create(['owner_user_id' => $owner->id,
            'name' => 'Workshop', 'country_iso_code' => 'CH', 'status' => 'active']);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner);
        \Illuminate\Support\Facades\DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>auth()->id()], ['version'=>\App\Http\Middleware\M25AiConsent::VERSION,'allowed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->postJson('/api/v1/ai/jobs/voice', [
            'file' => $this->recording(),
        ])->assertOk()
            ->assertJsonPath('data.suggestions.title', 'Terrace Construction')
            ->assertJsonPath('data.suggestions.description', 'Build a terrace')
            ->assertJsonPath('data.suggestions.budgetChf', 5000);
        $this->assertDatabaseCount('service_jobs', 0);
        Http::assertSentCount(2);

        Sanctum::actingAs($this->account());
        \Illuminate\Support\Facades\DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>auth()->id()], ['version'=>\App\Http\Middleware\M25AiConsent::VERSION,'allowed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->postJson('/api/v1/ai/jobs/voice', [
            'file' => $this->recording(),
        ])->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_short_natural_job_prompt_produces_a_title_scope_and_budget_without_mandatory_extras(): void
    {
        config(['services.openai.key' => 'test-api-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'Create a bathroom renovation job with plumbing and tiling, budget CHF 5,000.'], 200),
            'https://api.openai.com/v1/chat/completions' => function ($request) {
                $prompt = (string) data_get($request->data(), 'messages.0.content');
                $this->assertStringContainsString('keys title', $prompt);
                $this->assertStringContainsString('short', $prompt);
                return Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'title' => 'Bathroom Renovation',
                        'description' => 'Bathroom renovation including plumbing and tiling work.',
                        'budgetChf' => 5000,
                        'clientName' => null, 'address' => null,
                        'startDate' => null, 'endDate' => null,
                        'teamMemberNames' => [],
                    ]),
                ]]]], 200);
            },
        ]);
        $owner = $this->account();
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => 'Workshop',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($owner);
        \Illuminate\Support\Facades\DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>auth()->id()], ['version'=>\App\Http\Middleware\M25AiConsent::VERSION,'allowed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->postJson('/api/v1/ai/jobs/voice', [
            'file' => $this->recording(),
        ])->assertOk()
            ->assertJsonPath('data.suggestions.title', 'Bathroom Renovation')
            ->assertJsonPath('data.suggestions.description',
                'Bathroom renovation including plumbing and tiling work.')
            ->assertJsonPath('data.suggestions.budgetChf', 5000)
            ->assertJsonPath('data.suggestions.clientName', null);
        $this->assertDatabaseCount('service_jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_company_ai_requires_login_and_rejects_missing_configuration(): void
    {
        $this->postJson('/api/v1/ai/companies/voice', [
            'file' => $this->recording(),
        ])->assertUnauthorized();
        config(['services.openai.key' => '']);
        Sanctum::actingAs($this->account());
        \Illuminate\Support\Facades\DB::table('m25_ai_consents')->updateOrInsert(['user_id'=>auth()->id()], ['version'=>\App\Http\Middleware\M25AiConsent::VERSION,'allowed'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->postJson('/api/v1/ai/companies/voice', [
            'file' => $this->recording(),
        ])->assertStatus(503);
    }
}
