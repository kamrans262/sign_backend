<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * One-time, explicitly approved operator setup for TEST-ONLY subscriptions.
 *
 * Default: read-only preview.
 * --apply: create missing Stripe TEST products/prices and enable checkout in
 * the M23 billing settings. Never enables document quota enforcement.
 */
final class M23SetupStripeTestCheckout extends Command
{
    protected $signature='billing:m23-setup-test {--apply : Provision prices in Stripe TEST mode and enable TEST checkout}';
    protected $description='Prepare or activate Stripe-hosted test subscription Checkout; never uses live keys.';

    private function request(string $method,string $path,array $data=[],string $idempotency=''): array
    {
        $secret=(string)config('m23_billing.secret_key','');
        $client=Http::asForm()->acceptJson()->withToken($secret)->timeout(18);
        if($idempotency!==''){
            $client=$client->withHeaders(['Idempotency-Key'=>hash('sha256',$idempotency)]);
        }
        $url='https://api.stripe.com/v1'.$path;
        $result=$method==='GET' ? $client->get($url) : $client->post($url,$data);
        if(!$result->successful() || !is_array($result->json())){
            throw new \RuntimeException(
                'Stripe test API could not verify this product/price. Checkout remains disabled.'
            );
        }
        return $result->json();
    }

    private function verifyPrice(array $price,object $plan): void
    {
        $interval=$plan->period==='yearly'?'year':'month';
        if(($price['object']??'price')!=='price'
            || !is_string($price['id']??null)
            || !preg_match('/^price_[A-Za-z0-9]+$/',$price['id'])
            || ($price['active']??false)!==true
            || ($price['type']??null)!=='recurring'
            || ($price['currency']??null)!=='chf'
            || (int)($price['unit_amount']??-1)!==(int)$plan->amount_minor
            || ($price['recurring']['interval']??null)!==$interval
            || (int)($price['recurring']['interval_count']??0)!==1){
            throw new \RuntimeException(
                'Stripe recurring price does not match the published CHF amount and period.'
            );
        }
    }

    public function handle(): int
    {
        // Never allow this operator utility to provision real payments.
        $secret=(string)config('m23_billing.secret_key','');
        if(config('m23_billing.live_mode') ||
            !config('m23_billing.enabled') ||
            !str_starts_with($secret,'sk_test_') ||
            strlen($secret)<=strlen('sk_test_')){
            $this->error('Requires M23_STRIPE_ENABLED=true, TEST mode and a valid private sk_test_ key.');
            return self::FAILURE;
        }

        $root=rtrim((string)config('app.url',''),'/');
        $parsed=parse_url($root);
        if(!is_array($parsed) || ($parsed['scheme']??'')!=='https'
            || empty($parsed['host']) || !empty($parsed['user'])
            || !empty($parsed['pass']) || !empty($parsed['fragment'])){
            $this->error('APP_URL must be the public Sign2Digital HTTPS domain before Stripe setup.');
            return self::FAILURE;
        }
        $this->line('Checkout return host: '.$parsed['host']);
        $this->line('App callback: sign2digital://billing/success');
        $this->line('Stripe mode: TEST (no live payments).');

        $plans=DB::table('m23_plans')
            ->where('code','business')->where('status','published')
            ->whereIn('period',['monthly','yearly'])
            ->orderBy('period')->get();
        if($plans->count()!==2 ||
            $plans->pluck('period')->unique()->count()!==2 ||
            $plans->contains(fn($p)=>$p->currency!=='CHF'
                || (int)$p->amount_minor<=0)){
            $this->error('Publish exactly one CHF Business monthly and one CHF Business yearly plan in Admin first.');
            return self::FAILURE;
        }
        foreach($plans as $plan){
            $this->line('Business '.$plan->period.': CHF '.
                number_format($plan->amount_minor/100,2,'.','').
                ' · Stripe Price '.($plan->stripe_price_id?'EXISTS':'MISSING'));
        }
        $settings=DB::table('m23_billing_settings')->where('id',1)->first();
        if(!$settings){
            $this->error('M23 billing settings migration has not been applied.');
            return self::FAILURE;
        }
        $this->line('Test checkout activation: '.($settings->stripe_enabled?'ON':'OFF'));
        if(!$this->option('apply')){
            $this->comment('Read-only preview. Run again with --apply to create TEST prices and enable checkout.');
            return self::SUCCESS;
        }

        try{
            $productId=null;
            // When interrupted after the first plan, reuse its Stripe product
            // instead of creating a new product during a subsequent run.
            foreach($plans as $plan){
                if(!$plan->stripe_price_id)continue;
                $price=$this->request('GET','/prices/'.$plan->stripe_price_id);
                $this->verifyPrice($price,$plan);
                $remoteProduct=$price['product']??null;
                if(is_string($remoteProduct) && preg_match('/^prod_[A-Za-z0-9]+$/',$remoteProduct)){
                    $productId=$remoteProduct;
                }
            }
            foreach($plans as $plan){
                if($plan->stripe_price_id)continue;
                if($productId===null){
                    $product=$this->request('POST','/products',[
                        'name'=>'Sign2Digital Business',
                        'description'=>'Sign2Digital company subscription',
                        'metadata[app]'=>'sign2digital',
                        'metadata[environment]'=>'test',
                    ],'m23-test-business-product');
                    if(!preg_match('/^prod_[A-Za-z0-9]+$/',
                        (string)($product['id']??''))){
                        throw new \RuntimeException('Stripe did not return a test Product ID.');
                    }
                    $productId=$product['id'];
                }
                $price=$this->request('POST','/prices',[
                    'product'=>$productId,
                    'currency'=>'chf',
                    'unit_amount'=>(int)$plan->amount_minor,
                    'recurring[interval]'=>$plan->period==='yearly'?'year':'month',
                    'recurring[interval_count]'=>1,
                    'nickname'=>'Business '.ucfirst($plan->period),
                    'metadata[app]'=>'sign2digital',
                    'metadata[plan_period]'=>$plan->period,
                ],'m23-test-price-'.$plan->id.'-'.$plan->period.'-'.$plan->amount_minor);
                $this->verifyPrice($price,$plan);
                DB::transaction(function()use($plan,$price):void{
                    $record=DB::table('m23_plans')->where('id',$plan->id)
                        ->lockForUpdate()->firstOrFail();
                    if($record->stripe_price_id){
                        if($record->stripe_price_id!==$price['id']){
                            throw new \RuntimeException('Plan price was changed by another operator.');
                        }
                        return;
                    }
                    if($record->status!=='published' ||
                        (int)$record->amount_minor!==(int)$plan->amount_minor){
                        throw new \RuntimeException('Plan changed during Stripe setup. No checkout enabled.');
                    }
                    DB::table('m23_plans')->where('id',$plan->id)->update([
                        'stripe_price_id'=>$price['id'],
                        'revision'=>(int)$record->revision+1,
                        'updated_at'=>now(),
                    ]);
                    $saved=DB::table('m23_plans')->where('id',$plan->id)->firstOrFail();
                    DB::table('m23_plan_revisions')->insert([
                        'plan_id'=>$plan->id,'revision'=>$saved->revision,
                        'status'=>$saved->status,
                        'snapshot'=>json_encode($saved,JSON_THROW_ON_ERROR),
                        'changed_by_user_id'=>null,'created_at'=>now(),
                    ]);
                });
                $this->info('Linked '.$plan->period.' Stripe TEST price.');
            }

            // Verify persisted prices against Stripe before enabling payments.
            foreach(DB::table('m23_plans')->where('code','business')
                ->where('status','published')->get() as $plan){
                if(empty($plan->stripe_price_id)){
                    throw new \RuntimeException('A published plan has no Stripe Price ID.');
                }
                $this->verifyPrice(
                    $this->request('GET','/prices/'.$plan->stripe_price_id),$plan);
            }
            DB::transaction(function():void{
                DB::table('m23_billing_settings')->where('id',1)
                    ->lockForUpdate()->firstOrFail();
                DB::table('m23_billing_settings')->where('id',1)->update([
                    'stripe_enabled'=>true,'updated_at'=>now(),
                    // Deliberately preserve enforcement_enabled=false.
                ]);
            });
            $this->info('Stripe TEST Checkout READY. Subscription access still requires provider verification.');
            $this->comment('No live payment, document quota enforcement or webhook was activated.');
            return self::SUCCESS;
        }catch(\Throwable $exception){
            // Do not print provider request bodies or credentials.
            $this->error('TEST setup stopped: '.$exception->getMessage());
            $this->error('Checkout activation was not completed. Inspect plan configuration before retrying.');
            return self::FAILURE;
        }
    }
}
