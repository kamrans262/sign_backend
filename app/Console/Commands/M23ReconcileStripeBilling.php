<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\M23StripeBillingService;
use App\Services\M23StripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safe operator-triggered reconciliation for missing/out-of-order webhooks.
 * Dry-run is the default. No cron or provider traffic is activated by deploy.
 */
final class M23ReconcileStripeBilling extends Command
{
    protected $signature='billing:m23-reconcile
        {--apply : Fetch canonical Stripe state and update local provider records}
        {--company= : Restrict reconciliation to one existing company ID}';
    protected $description='Audit/optionally reconcile verified Stripe subscriptions without faking payments';

    public function handle(M23StripeGateway $stripe,M23StripeBillingService $billing): int
    {
        $id=$this->option('company');
        if($id!==null && (!ctype_digit((string)$id) || (int)$id<1)){
            $this->error('A valid numeric company ID is required.');
            return self::INVALID;
        }
        $query=DB::table('m23_company_subscriptions')
            ->whereNotNull('stripe_customer_id')
            ->whereNotNull('stripe_subscription_id');
        if($id!==null)$query->where('company_id',(int)$id);
        $count=$query->count();
        if(!$this->option('apply')){
            $this->line('Dry-run only. Eligible existing billing relationships: '.$count.
                '. No Stripe network traffic or database updates occurred.');
            return self::SUCCESS;
        }
        if(!$stripe->enabled()){
            $this->error('Stripe integration is disabled; reconciliation was not performed.');
            return self::FAILURE;
        }
        $success=0;$failed=0;
        $query->orderBy('id')->chunkById(50,function($rows)use($billing,&$success,&$failed):void {
            foreach($rows as $row){
                try {
                    $company=Company::query()->findOrFail($row->company_id);
                    $billing->reconcile($company,$row->stripe_subscription_id);
                    $success++;
                }catch(\Throwable $e){
                    $failed++;
                    // No Stripe IDs/secrets/webhook payloads in logs or stdout.
                    report(new \RuntimeException(
                        'M23 subscription reconciliation failed for company ID '.
                        (int)$row->company_id,0,$e));
                }
            }
        });
        $this->info('M23 reconciliation: '.$success.' verified, '.$failed.' require review.');
        return $failed===0 ? self::SUCCESS : self::FAILURE;
    }
}
