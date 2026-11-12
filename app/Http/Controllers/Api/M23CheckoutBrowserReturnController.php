<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public browser handoff after Stripe-hosted Checkout. This endpoint does not
 * read payment state, issue tokens or activate subscriptions. Only the
 * authenticated, company-scoped checkout-status API verifies payment.
 */
final class M23CheckoutBrowserReturnController extends Controller
{
    public function success(Request $request): Response
    {
        return $this->page('success', $request);
    }

    public function cancel(Request $request): Response
    {
        return $this->page('cancel', $request);
    }

    public function portal(Request $request): Response
    {
        return $this->page('portal', $request);
    }

    private function page(string $result, Request $request): Response
    {
        $intent=(string)$request->query('intent', '');
        // Ignore any other query data (including spoofed payment status).
        if ($intent!=='' && !preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $intent
        )) {
            abort(400, 'Invalid checkout reference.');
        }
        if ($result!=='portal' && $intent==='') {
            abort(400, 'Missing checkout reference.');
        }

        $deepLink='sign2digital://billing/'.$result
            .($intent!==''?'?intent='.rawurlencode($intent):'');
        $heading=match($result) {
            'success'=>'Returning to Sign2Digital',
            'cancel'=>'Checkout canceled',
            default=>'Returning to Sign2Digital',
        };
        $description=match($result) {
            'success'=>'We will verify your subscription securely in the app. Payment is not confirmed by this page.',
            'cancel'=>'No payment was confirmed. You can return to the app and try again.',
            default=>'Open Sign2Digital to see your latest subscription details.',
        };
        // JSON_HEX_* keeps user-controlled URL parameters out of JS/HTML syntax.
        $scriptLink=json_encode($deepLink, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $safeLink=e($deepLink);
        $html=<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer"><title>Sign2Digital · Checkout</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fff;font:500 15px Inter,system-ui,Arial,sans-serif;color:#122536}
main{max-width:440px;padding:32px 24px;text-align:center}
h1{font-size:22px;margin:0 0 16px}
p{line-height:1.6;color:#667085;margin:0 0 24px}
a{display:inline-block;padding:14px 24px;border-radius:10px;background:#166282;color:#fff;text-decoration:none;font-weight:600}
</style>
</head>
<body><main><h1>{$heading}</h1><p>{$description}</p>
<a id="open-app" href="{$safeLink}">Open Sign2Digital</a>
<p style="font-size:12px;margin-top:18px">If the app does not open automatically, tap the button above.</p>
</main>
<script>window.addEventListener('load',function(){window.location.href={$scriptLink};});</script>
</body></html>
HTML;
        return response($html,200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private',
            'Referrer-Policy'=>'no-referrer',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        ]);
    }
}
