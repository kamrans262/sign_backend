<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Minimal typed Stripe Billing HTTP adapter.
 *
 * No Stripe credentials, card numbers or webhook payloads are persisted.
 * Laravel HTTP fakes fully exercise it in CI. It cannot perform outbound
 * traffic until two explicit server-side opt-in switches and a secret exist.
 */
final class M23StripeGateway
{
    public function enabled(): bool
    {
        $key=(string)config('m23_billing.secret_key','');
        $prefix=config('m23_billing.live_mode')?'sk_live_':'sk_test_';
        return config('m23_billing.enabled')===true
            && str_starts_with($key,$prefix)
            && strlen($key)>strlen($prefix)
            && (bool)(DB::table('m23_billing_settings')->where('id',1)
                ->value('stripe_enabled'))===true;
    }

    public function guard(): void
    {
        abort_unless($this->enabled(),503,
            'Stripe billing has not been activated. No charge was created.');
    }

    public function request(string $method,string $path,array $params=[],?string $idempotency=null): array
    {
        $this->guard();
        abort_unless(in_array($method,['GET','POST'],true)
            && preg_match('#^/[a-zA-Z0-9_./-]+$#',$path)
            && !str_contains($path,'..'),400,'Invalid billing operation.');
        $req=Http::asForm()->acceptJson()->withToken(
            (string)config('m23_billing.secret_key'))->timeout(15);
        if($idempotency!==null){
            $req=$req->withHeaders(['Idempotency-Key'=>hash('sha256',$idempotency)]);
        }
        try {
            $url=(string)config('m23_billing.api').$path;
            $res=$method==='GET'?$req->get($url,$params):$req->post($url,$params);
            if(!$res->successful() || !is_array($res->json())){
                // Never include the provider response, token, request body or
                // payment details in the HTTP error surfaced to Flutter.
                abort(503,'Stripe billing is temporarily unavailable. No success confirmed.');
            }
            return $res->json();
        } catch(ConnectionException $e) {
            abort(503,'Stripe billing connection unavailable. Retry or verify status.');
        }
    }

    public function price(string $priceId): array
    {
        abort_unless(preg_match('/^price_[A-Za-z0-9]+$/',$priceId),422,
            'Invalid Stripe price configuration.');
        return $this->request('GET','/prices/'.$priceId);
    }

    public function subscription(string $id): array
    {
        abort_unless(preg_match('/^sub_[A-Za-z0-9]+$/',$id),422,
            'Invalid Stripe subscription reference.');
        return $this->request('GET','/subscriptions/'.$id,
            ['expand'=>['items.data.price']]);
    }

    public function checkout(string $id): array
    {
        abort_unless(preg_match('/^cs_[A-Za-z0-9_]+$/',$id),422,
            'Invalid Stripe checkout reference.');
        return $this->request('GET','/checkout/sessions/'.$id);
    }

    public function invoice(string $id): array
    {
        abort_unless(preg_match('/^in_[A-Za-z0-9]+$/',$id),422,
            'Invalid Stripe invoice reference.');
        return $this->request('GET','/invoices/'.$id);
    }

    public function safeHostedUrl(?string $url,string $kind='checkout'): string
    {
        $parts=is_string($url)?parse_url($url):false;
        $host=is_array($parts)?($parts['host']??''):'';
        $allowed=$kind==='portal'?'billing.stripe.com':'checkout.stripe.com';
        abort_unless(is_array($parts) && ($parts['scheme']??'')==='https'
            && $host===$allowed && empty($parts['user'])
            && empty($parts['pass']) && empty($parts['port']),503,
            'Stripe did not return a trusted checkout destination.');
        return $url;
    }

    public function verifiedWebhook(string $raw,?string $signature): array
    {
        $this->guard();
        $secret=(string)config('m23_billing.webhook_secret','');
        abort_unless(str_starts_with($secret,'whsec_') && strlen($secret)>6,
            503,'Stripe webhook signing is not configured.');
        abort_unless(strlen($raw)>0 && strlen($raw)<=256*1024
            && is_string($signature) && strlen($signature)<=2000,400,
            'Invalid Stripe webhook payload.');
        $parts=[];
        foreach(explode(',',$signature) as $fragment){
            $pair=explode('=',trim($fragment),2);
            if(count($pair)===2)$parts[$pair[0]][]=$pair[1];
        }
        $timestamp=$parts['t'][0]??null;
        abort_unless(is_string($timestamp) && preg_match('/^[0-9]{10,12}$/',$timestamp),
            400,'Invalid webhook timestamp.');
        abort_unless(abs(time()-(int)$timestamp)<=(int)config(
            'm23_billing.webhook_tolerance_seconds',300),400,
            'Expired webhook signature.');
        $expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
        $valid=false;
        foreach($parts['v1']??[] as $provided){
            if(is_string($provided) && strlen($provided)===64 &&
                hash_equals($expected,$provided))$valid=true;
        }
        abort_unless($valid,400,'Webhook signature verification failed.');
        try {
            $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            abort(400,'Stripe event must contain valid JSON.');
        }
        abort_unless(is_array($decoded) && empty($decoded['account'])
            && is_string($decoded['id']??null)
            && preg_match('/^evt_[A-Za-z0-9]+$/',$decoded['id'])
            && is_string($decoded['type']??null)
            && is_bool($decoded['livemode']??null)
            && $decoded['livemode']===(bool)config('m23_billing.live_mode')
            && is_array($decoded['data']['object']??null),400,
            'Unexpected Stripe event account, mode or format.');
        return $decoded;
    }
}
