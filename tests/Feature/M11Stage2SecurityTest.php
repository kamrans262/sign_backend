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

/** M11 Stage 2: permissions, malicious inputs, immutability and integrity. */
final class M11Stage2SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $company = Company::create([
            'name' => $name, 'owner_user_id' => $owner->id,
            'status' => 'active', 'country_iso_code' => 'CH',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => 'Private Client', 'source' => 'manual',
        ]);
        $this->actingCompany($owner, $company);
        $job = $this->postJson('/api/v1/jobs', [
            'client_id' => $client->id, 'description' => 'Private repair project',
            'start_date' => '2026-12-01', 'due_date' => '2026-12-12',
            'team_member_ids' => [],
        ])->assertCreated()->json('data.job.id');
        return [$owner, $company, (int) $job];
    }

    private function actingCompany(User $user, Company $company): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
    }

    private function createNote(int $job): int
    {
        return (int) $this->postJson('/api/v1/jobs/'.$job.'/notes', [
            'title' => 'Private work summary',
            'body' => 'Two panes need replacement', 'type' => 'text',
            'visibility' => 'private',
        ])->assertCreated()->json('data.note.id');
    }

    private function createAudio(int $job): int
    {
        $bytes = "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00";
        return (int) $this->post('/api/v1/jobs/'.$job.'/notes', [
            'title' => 'Site recording', 'body' => '', 'type' => 'voice',
            'visibility' => 'private',
            'file' => UploadedFile::fake()->createWithContent('recording.wav', $bytes),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.note.id');
    }

    public function test_media_read_and_write_permissions_are_independent_and_revocable(): void
    {
        Storage::fake('local');
        [$owner, $company, $job] = $this->fixture('Permissions');
        $note = $this->createNote($job);
        $reader = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $company->users()->attach($reader->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['jobs.read']),
        ]);
        $this->actingCompany($reader, $company);
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertOk()
            ->assertJsonPath('data.notes.0.id', $note);
        $this->getJson('/api/v1/jobs/'.$job.'/photos')->assertOk();
        $this->postJson('/api/v1/jobs/'.$job.'/notes', [
            'title' => 'Blocked', 'body' => 'Blocked',
            'type' => 'text', 'visibility' => 'private',
        ])->assertForbidden();
        $this->patchJson('/api/v1/jobs/'.$job.'/notes/'.$note,
            ['body' => 'Overwritten'])->assertForbidden();
        $this->deleteJson('/api/v1/jobs/'.$job.'/notes/'.$note)->assertForbidden();
        $this->postJson('/api/v1/jobs/'.$job.'/notes/'.$note.'/documents/prepare',
            ['document_type' => 'report'])->assertForbidden();
        $company->users()->updateExistingPivot($reader->id, ['status' => 'inactive']);
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertForbidden();
        $this->actingCompany($owner, $company);
        $this->getJson('/api/v1/jobs/'.$job.'/notes')->assertOk();
    }

    public function test_foreign_note_identifiers_are_inaccessible_even_with_a_valid_local_job(): void
    {
        Storage::fake('local');
        [$ownerA, $a, $jobA] = $this->fixture('A');
        $noteA = $this->createNote($jobA);
        [$ownerB, $b, $jobB] = $this->fixture('B');
        $noteB = $this->createNote($jobB);
        $this->actingCompany($ownerA, $a);
        $this->getJson('/api/v1/jobs/'.$jobA.'/notes/'.$noteB)->assertNotFound();
        $this->postJson('/api/v1/jobs/'.$jobA.'/notes/'.$noteB.'/transcribe')
            ->assertNotFound();
        $this->postJson('/api/v1/jobs/'.$jobA.'/notes/'.$noteB.'/documents/prepare',
            ['document_type' => 'report'])->assertNotFound();
        $this->patchJson('/api/v1/jobs/'.$jobA.'/notes/'.$noteB,
            ['visibility' => 'customer_facing'])->assertNotFound();
        $this->actingCompany($ownerB, $b);
        $this->getJson('/api/v1/jobs/'.$jobA.'/notes/'.$noteA)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$jobB.'/notes/'.$noteB)->assertOk();
    }

    public function test_audio_integrity_and_invalid_photo_types_are_enforced(): void
    {
        Storage::fake('local');
        [$owner, $company, $job] = $this->fixture('Files');
        $audioId = $this->createAudio($job);
        $this->get('/api/v1/jobs/'.$job.'/notes/'.$audioId.'/audio')
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $stored = DB::table('job_notes')->find($audioId);
        Storage::disk('local')->put($stored->audio_private_path, 'corrupted bytes');
        $this->get('/api/v1/jobs/'.$job.'/notes/'.$audioId.'/audio')
            ->assertStatus(409);
        $this->post('/api/v1/jobs/'.$job.'/photos', [
            'file' => UploadedFile::fake()->createWithContent('malicious.png', '<script>alert(1)</script>'),
            'category' => 'before', 'caption' => 'Invalid',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('job_photos', 0);
    }

    public function test_approved_note_document_cannot_be_rewritten_or_approved_twice(): void
    {
        Storage::fake('local');
        [$owner, $company, $job] = $this->fixture('Immutable');
        $note = $this->createNote($job);
        config()->set('services.openai.key', 'm11-stage2-test-only');
        Http::fake(['*api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'title' => 'Site Report', 'content' => 'Two panes observed.',
            ])]]],
        ], 200)]);
        $base = '/api/v1/jobs/'.$job.'/notes/'.$note.'/documents';
        $draft = (int) $this->postJson($base.'/prepare', ['document_type' => 'report'])
            ->assertCreated()->json('data.document.id');
        $this->assertDatabaseCount('managed_documents', 0);
        $this->postJson($base.'/'.$draft.'/approve', ['confirm' => true])
            ->assertOk()->assertJsonPath('data.document.status', 'approved');
        $this->patchJson($base.'/'.$draft, [
            'title' => 'Tampering', 'content' => 'Tampering',
        ])->assertStatus(409);
        $this->postJson($base.'/'.$draft.'/approve', ['confirm' => true])->assertStatus(409);
        $this->assertDatabaseHas('job_note_documents', [
            'id' => $draft, 'title' => 'Site Report', 'status' => 'approved',
        ]);
        $this->assertDatabaseCount('managed_documents', 1);
    }

    public function test_non_admin_cannot_view_or_modify_private_records_in_platform_admin(): void
    {
        Storage::fake('local');
        [$owner, $company, $job] = $this->fixture('Admin Boundary');
        $id = $this->createNote($job);
        $this->actingAs($owner);
        $this->get('/admin/job-media?kind=notes')->assertForbidden();
        $this->patch('/admin/job-media/notes/'.$id, [
            'title' => 'Unauthorized', 'body' => 'Stolen', 'visibility' => 'customer_facing',
        ])->assertForbidden();
        $this->get('/admin/job-media/notes/'.$id.'/download')->assertForbidden();
        $this->assertDatabaseHas('job_notes', [
            'id' => $id, 'title' => 'Private work summary', 'visibility' => 'private',
        ]);
    }
}
