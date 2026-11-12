<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Support\AdminCompanyNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class AdminCompanyDisplayNamesTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceContext(): array
    {
        $owner=User::factory()->create([
            'is_active'=>true,'email_verified_at'=>now(),
        ]);
        $company=Company::create([
            'name'=>'Alpine & Sons Renovations',
            'owner_user_id'=>$owner->id,'country_iso_code'=>'CH',
            'status'=>'active',
        ]);
        $company->users()->attach($owner->id,[
            'role'=>'owner','status'=>'active',
        ]);
        $client=Client::create([
            'company_id'=>$company->id,'full_name'=>'Customer A',
            'source'=>'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Existing renovation project',
            'start_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'team_member_ids'=>[],
        ])->assertCreated()->json('data.job.id');
        DB::table('service_invoices')->insert([
            'company_id'=>$company->id,'service_job_id'=>$job,
            'client_id'=>$client->id,'invoice_number'=>'INV-COMPANY-LABEL-1',
            'invoice_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'client_name_snapshot'=>'Customer A',
            'status'=>'sent','currency'=>'CHF',
            'subtotal_minor'=>235400,'vat_minor'=>0,
            'total_minor'=>235400,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        return [$company,$job];
    }

    public function test_admin_invoice_table_displays_real_company_name_and_escapes_html(): void
    {
        [$company,$job]=$this->invoiceContext();
        $this->get('/admin/invoices')->assertOk()
            ->assertSee('INV-COMPANY-LABEL-1')
            ->assertSee('#'.$job.' · Alpine &amp; Sons Renovations',false)
            ->assertDontSee('Company #'.$company->id);
    }

    public function test_historical_inactive_company_name_still_resolves_and_rename_is_not_stale(): void
    {
        [$company,$job]=$this->invoiceContext();
        $company->update(['status'=>'inactive']);
        $this->get('/admin/invoices')->assertOk()
            ->assertSee('#'.$job.' · Alpine &amp; Sons Renovations',false);
        $company->update(['name'=>'Renamed Historical Builder']);
        $this->get('/admin/invoices')->assertOk()
            ->assertSee('#'.$job.' · Renamed Historical Builder',false)
            ->assertDontSee('Company #'.$company->id);
    }

    public function test_company_label_helper_handles_missing_ids_without_exposing_numbers(): void
    {
        $this->assertSame('—',AdminCompanyNames::label(null));
        $this->assertSame('—',AdminCompanyNames::label(''));
        $this->assertSame('Unknown company',AdminCompanyNames::label(999999));
    }

    public function test_no_admin_blade_view_displays_company_id_as_the_company_name(): void
    {
        $files=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views/admin'))
        );
        foreach($files as $file){
            if(!$file->isFile() || !str_ends_with($file->getFilename(),'.blade.php'))continue;
            $view=file_get_contents($file->getPathname());
            $this->assertIsString($view);
            // Numeric IDs remain permitted in inputs, URLs and audit data,
            // but must never be presented as the company's display label.
            $this->assertDoesNotMatchRegularExpression(
                '/company\s*#\s*\{\{/i',$view,
                $file->getPathname()
            );
        }
        $this->assertStringContainsString(
            'AdminCompanyNames::label',
            file_get_contents(resource_path('views/admin/invoices/index.blade.php'))
        );
    }
}
