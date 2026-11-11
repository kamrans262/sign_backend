<?php

use App\Services\M23BillingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client-review starter catalog. Two billing periods for ONE Business
     * product; these are published offers but cannot charge until the admin
     * attaches verified Stripe Prices and billing is separately activated.
     * Never overwrite administrator-edited or already subscribed plans.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $features=json_encode(M23BillingService::FEATURES, JSON_THROW_ON_ERROR);
            foreach ([
                'monthly' => [
                    'amount'=>2900,
                    'description'=>'Everything your team needs to manage jobs, clients and documents. Billed monthly.',
                ],
                'yearly' => [
                    'amount'=>29000,
                    'description'=>'One yearly payment for complete Business access.',
                ],
            ] as $period=>$offer) {
                if (DB::table('m23_plans')->where('code','business')
                    ->where('period',$period)->exists()) {
                    continue;
                }
                $now=now();
                $id=DB::table('m23_plans')->insertGetId([
                    'code'=>'business',
                    'name'=>'Business',
                    'period'=>$period,
                    'currency'=>'CHF',
                    'amount_minor'=>$offer['amount'],
                    'description'=>$offer['description'],
                    'features'=>$features,
                    'document_limit'=>null,
                    'stripe_price_id'=>null,
                    'status'=>'published',
                    'revision'=>1,
                    'updated_by_user_id'=>null,
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);
                $snapshot=DB::table('m23_plans')->where('id',$id)->firstOrFail();
                DB::table('m23_plan_revisions')->insert([
                    'plan_id'=>$id,
                    'revision'=>1,
                    'status'=>'published',
                    'snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'changed_by_user_id'=>null,
                    'created_at'=>$now,
                ]);
            }
        },3);
    }

    public function down(): void
    {
        // Deliberately preserve published business configuration and
        // subscriber references. Rollback must never delete plan history.
    }
};
