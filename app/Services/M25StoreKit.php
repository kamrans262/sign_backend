<?php
namespace App\Services;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
final class M25StoreKit {
    public function __construct(private M25StoreJws $jws){}
    public function ready():bool{
        return config('m25_apple.store_enabled')===true&&in_array(config('m25_apple.store_environment'),['Sandbox','Production'],true)
            &&config('m25_apple.store_issuer')!==''&&config('m25_apple.store_key_id')!==''
            &&is_readable((string)config('m25_apple.store_key_path'))&&is_readable((string)config('m25_apple.store_root_path'));
    }
    private function owner(Company $company,int $actor):void{
        abort_unless((int)$company->owner_user_id===$actor&&$company->status==='active',403,'Only the company owner can manage subscriptions.');
    }
    public function stripeConflict(int $id):bool{
        return DB::table('m23_company_subscriptions')->where('company_id',$id)->whereNotNull('stripe_subscription_id')->whereNotIn('status',['canceled','incomplete_expired'])->exists()
            || DB::table('m23_checkout_intents')->where('company_id',$id)->where('status','pending')
                ->where(fn($q)=>$q->where('expires_at','>',now())->orWhere(fn($x)=>$x->whereNull('expires_at')->where('created_at','>',now()->subMinutes(30))))->exists();
    }
    public function appleConflict(int $id):bool{
        return DB::table('m25_storekit_subscriptions')->where('company_id',$id)->whereIn('status',['active','past_due'])->exists()
            ||DB::table('m25_storekit_accounts')->where('company_id',$id)->where('pending_until','>',now())->exists();
    }
    public function catalog(Company $company,int $actor):array{
        $products=[];
        foreach(config('m25_apple.products') as $period=>$product){
            $p=DB::table('m23_plans')->where('code','business')->where('period',$period)->where('status','published')->first();
            if($p && is_string($product)&&$product!=='')$products[]=['id'=>$product,'period'=>$period,'plan_id'=>$p->id];
        }
        return ['enabled'=>$this->ready(),'can_purchase'=>$this->ready()&&(int)$company->owner_user_id===$actor&&!$this->stripeConflict($company->id),
            'products'=>$products,'provider'=>DB::table('m25_storekit_subscriptions')->where('company_id',$company->id)->exists()?'apple':null];
    }
    public function begin(Company $company,int $actor):array{
        $this->owner($company,$actor);abort_unless($this->ready(),503,'Apple billing is not configured.');
        return DB::transaction(function()use($company):array{
            $locked=Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->owner($locked,(int)$company->owner_user_id);
            abort_if($this->stripeConflict($company->id),409,'An existing Stripe subscription or checkout must end before purchasing.');
            $row=DB::table('m25_storekit_accounts')->where('company_id',$company->id)->first();
            $token=$row?->account_token??(string)Str::uuid();
            DB::table('m25_storekit_accounts')->updateOrInsert(['company_id'=>$company->id],['account_token'=>$token,'pending_until'=>now()->addHours(24),'created_at'=>now(),'updated_at'=>now()]);
            return ['account_token'=>$token];
        },3);
    }
    private function api(string $id):array{
        abort_unless($this->ready(),503,'Apple billing is not configured.');
        abort_unless(preg_match('/^[0-9]{1,64}$/D',$id),422,'Invalid transaction ID.');
        $jwt=M25AppleJwt::sign(config('m25_apple.store_key_id'),config('m25_apple.store_key_path'),[
            'iss'=>config('m25_apple.store_issuer'),'iat'=>time(),'exp'=>time()+300,'aud'=>'appstoreconnect-v1','bid'=>config('m25_apple.bundle'),
        ]);
        $host=config('m25_apple.store_environment')==='Sandbox'?'https://api.storekit-sandbox.apple.com':'https://api.storekit.apple.com';
        $response=Http::withToken($jwt)->acceptJson()->timeout(12)->withOptions(['allow_redirects'=>false])->get($host.'/inApps/v1/subscriptions/'.$id);
        abort_unless($response->successful(),503,'Apple transaction verification is temporarily unavailable.');
        $data=$response->json();
        abort_unless(is_array($data)&&($data['bundleId']??null)===config('m25_apple.bundle')&&($data['environment']??null)===config('m25_apple.store_environment'),422,'Apple app or environment mismatch.');
        return $data;
    }
    public function sync(Company $company,string $id,?int $actor=null):array{
        if($actor!==null)$this->owner($company,$actor);
        $account=DB::table('m25_storekit_accounts')->where('company_id',$company->id)->first();
        abort_unless($account,409,'No Apple purchase belongs to this company.');
        $existing=DB::table('m25_storekit_subscriptions')->where('company_id',$company->id)->first();
        $data=$this->api($id);$chosen=null;
        foreach($data['data']??[] as $group)foreach($group['lastTransactions']??[] as $last){
            $t=$this->jws->verify($last['signedTransactionInfo']??'');
            if(($t['bundleId']??null)!==config('m25_apple.bundle')||($t['environment']??null)!==config('m25_apple.store_environment')||
                strcasecmp((string)($t['appAccountToken']??''),$account->account_token)!==0||
                ($t['inAppOwnershipType']??null)!=='PURCHASED')continue;
            $original=(string)($t['originalTransactionId']??'');
            if(!preg_match('/^[0-9]{1,64}$/D',$original)||($existing&&$existing->original_transaction_id!==$original))continue;
            $period=array_search($t['productId']??null,config('m25_apple.products'),true);
            if($period===false)continue;
            $plan=DB::table('m23_plans')->where('code','business')->where('period',$period)->where('status','published')->first();
            if(!$plan)continue;
            $renewal=$this->jws->verify($last['signedRenewalInfo']??'');
            if((string)($renewal['originalTransactionId']??'')!==$original)continue;
            $status=(int)($last['status']??0);
            $start=$t['purchaseDate']??null;$end=$t['expiresDate']??null;
            if(!is_int($start)||!is_int($end)||$end<=$start)continue;
            if($status===4 && is_int($renewal['gracePeriodExpiresDate']??null))$end=max($end,$renewal['gracePeriodExpiresDate']);
            $active=in_array($status,[1,4],true)&&empty($t['revocationDate'])&&$end>time()*1000&&$start<=time()*1000;
            $row=['plan_id'=>$plan->id,'original_transaction_id'=>$original,'transaction_id'=>(string)$t['transactionId'],
                'environment'=>config('m25_apple.store_environment'),'status'=>$active?'active':($status===3?'past_due':'canceled'),
                'period_starts_at'=>Carbon::createFromTimestampUTC($start/1000),'period_ends_at'=>Carbon::createFromTimestampUTC($end/1000),
                'cancel_at_period_end'=>(int)($renewal['autoRenewStatus']??0)===0,'provider_synced_at'=>now(),'updated_at'=>now()];
            if($chosen===null||$row['period_starts_at']->greaterThan($chosen['period_starts_at']))$chosen=$row;
        }
        abort_unless($chosen,422,'Apple could not verify a purchase for this company.');
        return DB::transaction(function()use($company,$actor,$chosen):array{
            $locked=Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if($actor!==null)$this->owner($locked,$actor);
            $prior=DB::table('m25_storekit_subscriptions')->where('company_id',$company->id)->lockForUpdate()->first();
            abort_if($prior&&($prior->original_transaction_id!==$chosen['original_transaction_id']||$prior->environment!==$chosen['environment']),409,'Purchase identity conflict.');
            abort_if(DB::table('m25_storekit_subscriptions')->where('original_transaction_id',$chosen['original_transaction_id'])->where('company_id','!=',$company->id)->exists(),409,'Purchase belongs to another company.');
            if($prior && Carbon::parse($prior->period_starts_at)->greaterThan($chosen['period_starts_at']))return ['verified'=>true,'status'=>$prior->status];
            DB::table('m25_storekit_subscriptions')->updateOrInsert(['company_id'=>$company->id],$chosen+['created_at'=>$prior?->created_at??now()]);
            DB::table('m25_storekit_accounts')->where('company_id',$company->id)->update(['pending_until'=>null,'updated_at'=>now()]);
            return ['verified'=>true,'status'=>$chosen['status'],'provider_conflict'=>$this->stripeConflict($company->id)];
        },3);
    }
    public function notification(string $signed):void{
        $n=$this->jws->verify($signed);
        $d=$n['data']??[];
        if(($d['bundleId']??null)!==config('m25_apple.bundle')||($d['environment']??null)!==config('m25_apple.store_environment'))return;
        if(empty($d['signedTransactionInfo']))return;
        $t=$this->jws->verify($d['signedTransactionInfo']);
        $account=DB::table('m25_storekit_accounts')->where('account_token',strtolower((string)($t['appAccountToken']??'')))->first();
        if($account && ($company=Company::find($account->company_id)))$this->sync($company,(string)$t['originalTransactionId']);
    }
    public function reconcile():int{
        if(!$this->ready())return 0;
        $count=0;
        foreach(DB::table('m25_storekit_subscriptions')->orderBy('provider_synced_at')->limit(30)->get() as $row){
            try{if($company=Company::find($row->company_id)){$this->sync($company,$row->original_transaction_id);$count++;}}catch(\Throwable){/* provider expiry still applies */}
        }
        return $count;
    }
}
