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

final class M19EquipmentLoanStage1Test extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        Storage::fake('local');
        $actor=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$actor->id,
            'name'=>'Equipment Test Company','country_iso_code'=>'CH',
            'status'=>'active']);
        $company->users()->attach($actor->id,['role'=>'owner','status'=>'active']);
        $client=Client::create([
            'company_id'=>$company->id,'full_name'=>'Alice Borrower',
            'email'=>'alice@example.test','source'=>'manual',
        ]);
        Sanctum::actingAs($actor);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$actor,$company,$client];
    }

    private function makeAsset(string $code='EX-100',string $status='available'): array
    {
        return $this->postJson('/api/v1/equipment',[
            'code'=>$code,'name'=>'Mini Excavator','category'=>'Excavation',
            'brand'=>'Kubota','model'=>'U27','purchase_date'=>'2026-02-01',
            'status'=>$status,'location'=>'Warehouse','initial_hours'=>245,
        ])->assertCreated()->json('data.asset');
    }

    private function lend(int $assetId,array $extra=[]): array
    {
        $data=array_merge([
            'equipment_asset_id'=>$assetId,'borrower_type'=>'external',
            'borrower_name'=>'External Borrower',
            'borrower_email'=>'borrower@example.test',
            'loan_date'=>'2026-10-01',
            'expected_return_date'=>'2026-10-04',
            'notes'=>'Out on site',
            'request_key'=>'loan-create-key-12345',
        ],$extra);
        return $this->postJson('/api/v1/equipment-loans',$data)
            ->assertCreated()->json('data.loan');
    }

    public function test_equipment_assets_persist_and_private_photo_checksums_are_checked(): void
    {
        [$actor,$company]=$this->setupTenant();
        $asset=$this->makeAsset();
        $this->assertSame('EX-100',$asset['code']);
        $this->getJson('/api/v1/equipment')->assertOk()->assertJsonPath(
            'data.records.0.code','EX-100');
        $this->postJson('/api/v1/equipment',[
            'code'=>'EX-100','name'=>'Duplicate','category'=>'Other',
            'brand'=>'Test','model'=>'Test','purchase_date'=>'2026-02-01',
            'status'=>'available',
        ])->assertStatus(409);
        $id=$asset['id'];
        $usage=$this->postJson('/api/v1/equipment/'.$id.'/usage',[
            'date'=>'2026-10-01','hours'=>3.50,'notes'=>'Site use',
            'lock_version'=>1,
        ])->assertCreated()->json('data.asset');
        $this->assertEqualsWithDelta(248.5,$usage['hours'],0.001);
        $maint=$this->postJson('/api/v1/equipment/'.$id.'/maintenance',[
            'date'=>'2026-10-03','description'=>'Hydraulic check',
            'lock_version'=>2,
        ])->assertCreated()->json('data.asset');
        $this->assertCount(1,$maint['maintenance']);
        $this->postJson('/api/v1/equipment/'.$id.'/photos',[
            'file'=>UploadedFile::fake()->image('excavator.jpg',500,200),
            'lock_version'=>3,
        ])->assertCreated();
        $photo=DB::table('equipment_asset_photos')->firstOrFail();
        $res=$this->get('/api/v1/equipment/'.$id.'/photos/'.$photo->id)
            ->assertOk()->getContent();
        $this->assertSame(hash('sha256',$res),$photo->sha256);
        Storage::disk('local')->put($photo->private_path,'broken');
        $this->get('/api/v1/equipment/'.$id.'/photos/'.$photo->id)->assertStatus(409);
        $this->assertDatabaseCount('equipment_asset_events',4);
    }

    public function test_equipment_loan_active_exclusivity_and_idempotent_retry(): void
    {
        $this->setupTenant();
        $asset=$this->makeAsset();
        $loan=$this->lend($asset['id']);
        $again=$this->lend($asset['id']);
        $this->assertSame($loan['id'],$again['id']);
        $this->assertDatabaseCount('equipment_loans',1);
        $this->postJson('/api/v1/equipment-loans',[
            'equipment_asset_id'=>$asset['id'],
            'borrower_type'=>'external','borrower_name'=>'Another Person',
            'loan_date'=>'2026-10-01','expected_return_date'=>'2026-10-04',
            'request_key'=>'loan-create-key-other',
        ])->assertStatus(409);
        $this->postJson('/api/v1/equipment-loans',[
            'equipment_asset_id'=>$asset['id'],
            'borrower_type'=>'external','borrower_name'=>'Different Retry',
            'borrower_email'=>'borrower@example.test',
            'loan_date'=>'2026-10-01','expected_return_date'=>'2026-10-04',
            'notes'=>'Out on site','request_key'=>'loan-create-key-12345',
        ])->assertStatus(409);
    }

    public function test_lending_status_is_derived_from_due_date_and_extension_history(): void
    {
        $this->setupTenant();
        $asset=$this->makeAsset();
        $loan=$this->lend($asset['id']);
        $this->assertSame('overdue',$loan['status']);
        $this->getJson('/api/v1/equipment-loans?status=overdue')->assertOk()
            ->assertJsonCount(1,'data.records');
        $this->postJson('/api/v1/equipment-loans/'.$loan['id'].'/extend',[
            'confirm'=>true,'lock_version'=>1,
            'expected_return_date'=>'2027-02-01',
        ])->assertOk()->assertJsonPath('data.loan.status','active');
        $this->postJson('/api/v1/equipment-loans/'.$loan['id'].'/extend',[
            'confirm'=>true,'lock_version'=>1,
            'expected_return_date'=>'2027-03-01',
        ])->assertStatus(409);
        $events=$this->getJson('/api/v1/equipment-loans/'.$loan['id'].'/events')
            ->assertOk()->json('data.events');
        $this->assertCount(2,$events);
        $this->assertSame('2026-10-04',$events[1]['metadata']['old_date']);
        $this->assertSame('2027-02-01',$events[1]['metadata']['new_date']);
    }

    public function test_return_is_confirmed_versioned_and_preserves_history(): void
    {
        $this->setupTenant();
        $asset=$this->makeAsset();
        $loan=$this->lend($asset['id']);
        $return=$this->postJson('/api/v1/equipment-loans/'.$loan['id'].'/return',[
            'confirm'=>true,'lock_version'=>1,'actual_return_date'=>'2026-10-05',
        ])->assertOk()->json('data.loan');
        $this->assertSame('returned',$return['status']);
        $this->assertNull(DB::table('equipment_loans')->first()->active_slot);
        $this->postJson('/api/v1/equipment-loans/'.$loan['id'].'/return',[
            'confirm'=>true,'lock_version'=>1,'actual_return_date'=>'2026-10-06',
        ])->assertStatus(409);
        $this->assertDatabaseCount('equipment_loan_events',2);
        $this->assertSame(0,$this->getJson(
            '/api/v1/equipment-loans?status=overdue')->json('data.total'));
        $this->lend($asset['id'],[
            'request_key'=>'loan-create-again-12345','loan_date'=>'2026-10-06',
            'expected_return_date'=>'2026-10-09',
        ]);
        $this->assertDatabaseCount('equipment_loans',2);
    }

    public function test_maintenance_and_archive_cannot_override_active_loan(): void
    {
        $this->setupTenant();
        $maintenance=$this->makeAsset('EQ-MAINT','maintenance');
        $this->postJson('/api/v1/equipment-loans',[
            'equipment_asset_id'=>$maintenance['id'],
            'borrower_type'=>'external','borrower_name'=>'Some Person',
            'loan_date'=>'2026-10-01','expected_return_date'=>'2026-10-05',
            'request_key'=>'maintenance-try-12345',
        ])->assertStatus(409);
        $asset=$this->makeAsset('EQ-ACTIVE');
        $loan=$this->lend($asset['id']);
        $this->postJson('/api/v1/equipment/'.$asset['id'].'/archive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertStatus(409);
        $this->patchJson('/api/v1/equipment/'.$asset['id'],[
            'code'=>'EQ-ACTIVE','name'=>'Mini Excavator',
            'category'=>'Excavation','brand'=>'Kubota','model'=>'U27',
            'purchase_date'=>'2026-02-01','status'=>'maintenance',
            'lock_version'=>1,
        ])->assertStatus(409);
    }

    public function test_other_company_assets_and_loans_are_not_accessible(): void
    {
        [$actor,$company,$client]=$this->setupTenant();
        $asset=$this->makeAsset();
        $loan=$this->lend($asset['id']);
        $other=Company::create(['name'=>'Other Tenant',
            'owner_user_id'=>$actor->id,'country_iso_code'=>'CH','status'=>'active']);
        $other->users()->attach($actor->id,['role'=>'owner','status'=>'active']);
        $this->withHeader('X-Company-Id',(string)$other->id);
        $this->getJson('/api/v1/equipment/'.$asset['id'])->assertNotFound();
        $this->getJson('/api/v1/equipment-loans/'.$loan['id'])->assertNotFound();
        $this->postJson('/api/v1/equipment-loans/'.$loan['id'].'/return',[
            'confirm'=>true,'lock_version'=>1,'actual_return_date'=>'2026-10-04',
        ])->assertNotFound();
        $this->postJson('/api/v1/equipment-loans',[
            'equipment_asset_id'=>$asset['id'],'borrower_type'=>'client',
            'client_id'=>$client->id,'borrower_name'=>'Alice Borrower',
            'loan_date'=>'2026-10-03','expected_return_date'=>'2026-10-05',
            'request_key'=>'another-tenant-12345',
        ])->assertStatus(422);
    }

    public function test_existing_clients_and_external_people_are_both_valid(): void
    {
        [$actor,$company,$client]=$this->setupTenant();
        $asset=$this->makeAsset();
        $loan=$this->lend($asset['id'],[
            'borrower_type'=>'client','client_id'=>$client->id,
            'borrower_name'=>'Alice Borrower',
        ]);
        $this->assertSame($client->id,$loan['client_id']);
        $this->assertSame('Alice Borrower',$loan['borrower_name']);
        $this->assertDatabaseHas('equipment_loans',[
            'borrower_type'=>'client','borrower_name'=>'Alice Borrower',
        ]);
    }

    public function test_admin_uses_same_service_and_lists_assets_and_loans(): void
    {
        [$actor,$company]=$this->setupTenant();
        $platform=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($platform);
        $this->post('/admin/equipment',[
            'company_id'=>$company->id,'code'=>'ADM-100',
            'name'=>'Loader','category'=>'Earthmoving','brand'=>'CAT',
            'model'=>'901','purchase_date'=>'2026-10-01',
            'status'=>'available','initial_hours'=>0,
        ])->assertRedirect();
        $this->get('/admin/equipment')->assertOk()->assertSee('ADM-100');
        $asset=DB::table('equipment_assets')->where('code','ADM-100')->firstOrFail();
        $this->post('/admin/equipment-loans',[
            'company_id'=>$company->id,'equipment_asset_id'=>$asset->id,
            'borrower_type'=>'external','borrower_name'=>'External Inspector',
            'loan_date'=>'2026-10-01','expected_return_date'=>'2026-10-05',
        ])->assertRedirect();
        $this->get('/admin/equipment-loans')->assertOk()
            ->assertSee('External Inspector');
        $loan=DB::table('equipment_loans')->firstOrFail();
        $this->get('/admin/equipment-loans/'.$loan->id.'/edit')
            ->assertOk()->assertSee('Mark Returned')->assertSee('Immutable Loan History');
    }
}
