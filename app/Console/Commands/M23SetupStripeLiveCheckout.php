<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Explicit, operator-run switch from test Stripe Prices to LIVE Stripe Prices.
 * Safe default is read-only. No test subscriptions or payment data is erased.
 */
final class M23SetupStripeLiveCheckout extends Command
{
    protected $signature = 'billing:m23-setup-live {--apply : Create matching LIVE prices and enable actual recurring charges}';
    protected $description = 'Safely provision and activate Stripe LIVE Checkout with live prices, never test prices.';

    private function api(string $method, string $path, array $body = [], ?string $idempotency = null): array
    {
        $key = (string) config('m23_billing.secret_key', '');
        $client = Http::asForm()->acceptJson()->withToken($key)->timeout(20);
        if ($idempotency !== null) {
            $client = $client->withHeaders([
                'Idempotency-Key' => hash('sha256', 'sign2digital-live-'.$idempotency),
            ]);
        }
        $response = $method === 'GET'
            ? $client->get('https://api.stripe.com/v1'.$path)
            : $client->post('https://api.stripe.com/v1'.$path, $body);
        if (!$response->successful() || !is_array($response->json())) {
            throw new \RuntimeException('Stripe LIVE API check failed; inspect your live account and private key.');
        }
        return $response->json();
    }

    private function validatePrice(array $price, object $plan): void
    {
        $interval = $plan->period === 'yearly' ? 'year' : 'month';
        if (($price['object'] ?? '') !== 'price'
            || !preg_match('/^price_[A-Za-z0-9]+$/', (string) ($price['id'] ?? ''))
            || ($price['livemode'] ?? null) !== true
            || ($price['active'] ?? false) !== true
            || ($price['type'] ?? null) !== 'recurring'
            || ($price['currency'] ?? null) !== 'chf'
            || (int) ($price['unit_amount'] ?? -1) !== (int) $plan->amount_minor
            || ($price['recurring']['interval'] ?? null) !== $interval
            || (int) ($price['recurring']['interval_count'] ?? 0) !== 1) {
            throw new \RuntimeException(
                'LIVE Stripe price does not match the approved CHF plan and billing period.'
            );
        }
    }

    public function handle(): int
    {
        $key = (string) config('m23_billing.secret_key', '');
        if (config('m23_billing.enabled') !== true ||
            config('m23_billing.live_mode') !== true ||
            !str_starts_with($key, 'sk_live_') ||
            strlen($key) <= strlen('sk_live_')) {
            $this->error('LIVE checkout requires M23_STRIPE_ENABLED=true, M23_STRIPE_LIVE_MODE=true and a real sk_live_ secret.');
            $this->line('Your existing sk_test_ key can only operate in TEST mode. Do not paste any keys into chat.');
            return self::FAILURE;
        }

        $root = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($root);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' ||
            empty($parts['host']) || !empty($parts['user']) ||
            !empty($parts['pass']) || !empty($parts['fragment'])) {
            $this->error('APP_URL must be the existing Sign2Digital HTTPS backend.');
            return self::FAILURE;
        }

        $plans = DB::table('m23_plans')->where('code', 'business')
            ->where('status', 'published')->whereIn('period', ['monthly', 'yearly'])
            ->orderBy('period')->get();
        if ($plans->count() !== 2 || $plans->pluck('period')->unique()->count() !== 2 ||
            $plans->contains(fn ($p) => $p->currency !== 'CHF' ||
                (int) $p->amount_minor <= 0)) {
            $this->error('Publish exactly the Business monthly and yearly CHF plans first.');
            return self::FAILURE;
        }

        $settings = DB::table('m23_billing_settings')->where('id', 1)->first();
        if (!$settings) {
            $this->error('M23 billing database setup is incomplete.');
            return self::FAILURE;
        }

        $this->line('Stripe: LIVE (real recurring charges)');
        $this->line('Return host: '.$parts['host']);
        foreach ($plans as $plan) {
            $this->line('Business '.$plan->period.' CHF '.
                number_format((int) $plan->amount_minor / 100, 2, '.', '').
                ' · existing Price ID '.($plan->stripe_price_id ? 'SET (must verify LIVE)' : 'MISSING'));
        }
        if (!$this->option('apply')) {
            $this->comment('Read-only preview. --apply will create LIVE products/prices and enable real recurring payments.');
            return self::SUCCESS;
        }

        // Stale TEST subscriptions/customers cannot be carried into a LIVE account.
        // Never delete historical test data, orphan paid accounts or guess mode.
        $hasProviderData = DB::table('m23_company_subscriptions')
            ->where(fn ($q) => $q->whereNotNull('stripe_customer_id')
                ->orWhereNotNull('stripe_subscription_id'))->exists();
        $hasCheckout = DB::table('m23_checkout_intents')->exists();
        if ($hasProviderData || $hasCheckout) {
            $this->error('Existing Stripe customer/subscription or Checkout attempts were found.');
            $this->error('Reconcile/archive test billing state separately before switching to LIVE; nothing was modified.');
            return self::FAILURE;
        }

        // A published plan's TEST price cannot be reused with sk_live_.
        // Disable checkout at the START of migration, preventing a mixed
        // catalog from being used while live price IDs are linked.
        DB::table('m23_billing_settings')->where('id', 1)
            ->update(['stripe_enabled' => false, 'updated_at' => now()]);

        try {
            $account = $this->api('GET', '/account');
            if (!preg_match('/^acct_[A-Za-z0-9]+$/', (string) ($account['id'] ?? '')) ||
                ($account['charges_enabled'] ?? false) !== true) {
                throw new \RuntimeException(
                    'Your Stripe LIVE account is not ready to accept payments. Complete Stripe account verification first.'
                );
            }

            $productId = null;
            foreach ($plans as $plan) {
                $existing = $plan->stripe_price_id;
                if (!is_string($existing) || !preg_match('/^price_[A-Za-z0-9]+$/', $existing)) {
                    continue;
                }
                // A test price returns 404 under the live key; that is expected.
                // Probe as a live price, retaining a verified live result on retries.
                $response = Http::acceptJson()->withToken(
                    (string) config('m23_billing.secret_key')
                )->timeout(20)->get('https://api.stripe.com/v1/prices/'.$existing);
                if ($response->status() === 404) continue;
                if (!$response->successful() || !is_array($response->json())) {
                    throw new \RuntimeException('Cannot verify existing LIVE price.');
                }
                $price = $response->json();
                $this->validatePrice($price, $plan);
                $candidate = $price['product'] ?? null;
                if (is_string($candidate) && preg_match('/^prod_[A-Za-z0-9]+$/', $candidate)) {
                    $productId = $candidate;
                }
            }

            foreach ($plans as $plan) {
                $currentId = $plan->stripe_price_id;
                $isLive = false;
                if (is_string($currentId) && $currentId !== '') {
                    $lookup = Http::acceptJson()->withToken(
                        (string) config('m23_billing.secret_key')
                    )->timeout(20)->get('https://api.stripe.com/v1/prices/'.$currentId);
                    if ($lookup->successful()) {
                        $price = $lookup->json();
                        if (!is_array($price)) throw new \RuntimeException('Invalid LIVE price response.');
                        $this->validatePrice($price, $plan);
                        $isLive = true;
                    } elseif ($lookup->status() !== 404) {
                        throw new \RuntimeException('Unable to verify existing plan price in LIVE mode.');
                    }
                }
                if ($isLive) continue;

                if ($productId === null) {
                    $product = $this->api('POST', '/products', [
                        'name' => 'Sign2Digital Business',
                        'description' => 'Sign2Digital recurring company subscription',
                        'metadata[app]' => 'sign2digital',
                        'metadata[environment]' => 'live',
                    ], 'business-product');
                    $productId = (string) ($product['id'] ?? '');
                    if (!preg_match('/^prod_[A-Za-z0-9]+$/', $productId) ||
                        ($product['livemode'] ?? null) !== true) {
                        throw new \RuntimeException('Stripe did not confirm a LIVE Product.');
                    }
                }
                $price = $this->api('POST', '/prices', [
                    'product' => $productId,
                    'currency' => 'chf',
                    'unit_amount' => (int) $plan->amount_minor,
                    'recurring[interval]' => $plan->period === 'yearly' ? 'year' : 'month',
                    'recurring[interval_count]' => 1,
                    'nickname' => 'Sign2Digital Business '.ucfirst($plan->period),
                    'metadata[app]' => 'sign2digital',
                    'metadata[environment]' => 'live',
                    'metadata[plan_period]' => $plan->period,
                ], 'business-'.$plan->period.'-'.$plan->amount_minor);
                $this->validatePrice($price, $plan);

                DB::transaction(function () use ($plan, $price): void {
                    $stored = DB::table('m23_plans')->where('id', $plan->id)
                        ->lockForUpdate()->firstOrFail();
                    if ($stored->status !== 'published' ||
                        $stored->currency !== 'CHF' ||
                        (int) $stored->amount_minor !== (int) $plan->amount_minor ||
                        !in_array($stored->stripe_price_id, [$plan->stripe_price_id, $price['id']], true)) {
                        throw new \RuntimeException('Plan changed during activation; checkout was not enabled.');
                    }
                    if ($stored->stripe_price_id === $price['id']) return;

                    DB::table('m23_plans')->where('id', $plan->id)->update([
                        'stripe_price_id' => $price['id'],
                        'revision' => (int) $stored->revision + 1,
                        'updated_at' => now(),
                    ]);
                    $saved = DB::table('m23_plans')->where('id', $plan->id)->firstOrFail();
                    DB::table('m23_plan_revisions')->insert([
                        'plan_id' => $plan->id,
                        'revision' => $saved->revision,
                        'status' => $saved->status,
                        'snapshot' => json_encode($saved, JSON_THROW_ON_ERROR),
                        'changed_by_user_id' => null,
                        'created_at' => now(),
                    ]);
                });
                $this->info('Linked validated LIVE '.$plan->period.' Price.');
            }

            foreach (DB::table('m23_plans')->where('code', 'business')
                ->where('status', 'published')->get() as $plan) {
                $price = $this->api('GET', '/prices/'.$plan->stripe_price_id);
                $this->validatePrice($price, $plan);
            }
            DB::table('m23_billing_settings')->where('id', 1)->update([
                'stripe_enabled' => true, 'updated_at' => now(),
            ]);
            $this->info('Stripe LIVE Checkout READY. Real recurring charges are enabled.');
            $this->comment('No document quota enforcement, webhook configuration or test billing records were altered.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Never expose credentials, provider error contents or PII to console.
            Log::warning('M23 LIVE Checkout activation refused', [
                'error_class' => get_class($e),
            ]);
            $this->error('LIVE setup stopped: '.$e->getMessage());
            $this->error('Checkout stays disabled. Correct the configuration and re-run --apply.');
            return self::FAILURE;
        }
    }
}
