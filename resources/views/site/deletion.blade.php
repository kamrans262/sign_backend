@extends('site.layout')
@section('title','Account Deletion')
@section('content')
<div class="legal-head"><div class="wrap"><span class="eyebrow">Account &amp; privacy</span><h1>Request account deletion</h1><p>Delete your Sign2Digital personal account and associated eligible personal data.</p></div></div>
<article class="legal">
<h2>Before you request deletion</h2><p>Account deletion is permanent. Export your personal data first if you need a copy, and review any active subscriptions. Company owners or platform administrators must arrange ownership or administrator transfer before their account can be erased. Company records are not automatically deleted when a team member leaves.</p>
<div class="form-panel">
@if(session('deletion_reference'))
<div class="notice success" role="status"><strong>Request received.</strong><br>Reference: {{ session('deletion_reference') }}<br>Your email has been verified. The request is now in our support queue for review. This is not yet confirmation that your account has been deleted.</div>
@else
<h3>{{ $awaiting ? 'Verify your email address' : 'Start your deletion request' }}</h3>
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach</div>@endif
@if($awaiting)
<p class="small">Enter the six-digit verification code. It expires after 10 minutes.</p>
<form method="post" action="{{ route('site.deletion.verify') }}">@csrf<div class="field"><label for="code">Verification code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><button class="button" type="submit">Verify and submit request</button></form>
<form method="post" action="{{ route('site.deletion.restart') }}" style="margin-top:12px">@csrf<button class="button secondary" type="submit">Start again</button></form>
@else
<p class="small">Use the email registered with Sign2Digital. For Apple Hide My Email, use the relay address associated with your Sign2Digital account.</p>
<form method="post" action="{{ route('site.deletion.start') }}">@csrf<div class="field"><label for="email">Registered email address</label><input type="email" name="email" id="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" placeholder="you@example.com" required></div>
<label class="check"><input type="checkbox" name="confirm" value="1" required> <span>I understand that account deletion is permanent and that eligible personal data will be erased or anonymized.</span></label><div class="actions"><button class="button" type="submit">Send verification code</button></div></form>
@endif
@endif
</div>
<h2>What happens next?</h2><ol><li>Verify access to your registered email address.</li><li>Submit your request to the Sign2Digital support team. Save the reference number.</li><li>Support reviews identity, company ownership and retention obligations. Additional verification may be needed.</li><li>Once processed, your account is disabled, sessions revoked, and eligible personal profile and support data erased or anonymized. A completion email is attempted to your registered address.</li></ol>
<h2>What information may remain?</h2><p>Company documents, signed records, issued invoices and necessary audit evidence may be retained for legal, security and business recordkeeping purposes. Backup retention is subject to operational rotation and legal requirements.</p>
<h2>Need assistance?</h2><p>If you cannot access the registered email or use Apple private relay and the verification email does not arrive, use Help &amp; Support from the app or the privacy contact below if configured. You can also initiate deletion in the app through Profile &amp; Settings.</p>
@include('site.contact')
<p>For further information, read our <a href="{{ route('site.privacy') }}">Privacy Policy</a>.</p>
</article>
@endsection
