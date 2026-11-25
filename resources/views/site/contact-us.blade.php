@extends('site.layout')
@section('title','Contact Us')
@section('content')
<div class="legal-head"><div class="wrap"><span class="eyebrow">Help &amp; Support</span><h1>Contact Us</h1><p>Have a question about Sign2Digital? Send us a message.</p></div></div>
<article class="legal">
<div class="form-panel">
@if(session('contact_reference'))
<div class="notice success" role="status"><strong>Thank you. Your message has been received.</strong><br>Reference: {{ session('contact_reference') }}<br>Our team can review your enquiry using the contact details you provided.</div>
@endif
<h3>Send us a message</h3>
<p class="small">All fields are required. You do not need a Sign2Digital account.</p>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach</div>@endif
<form method="post" action="{{ route('site.contact.store') }}">@csrf
<div class="field"><label for="name">Full name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120" required></div>
<div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="254" required></div>
<div class="field"><label for="subject">Subject</label><input id="subject" name="subject" value="{{ old('subject') }}" maxlength="180" required></div>
<div class="field"><label for="message">Message</label><textarea id="message" name="message" rows="6" minlength="10" maxlength="5000" required>{{ old('message') }}</textarea></div>
<div hidden aria-hidden="true"><label for="website">Leave this blank</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
<p class="small">We use the information you submit to handle your enquiry. Please do not include passwords, payment card details or confidential documents. Read our <a href="{{ route('site.privacy') }}">Privacy Policy</a>.</p>
<button type="submit" class="button">Send Message</button>
</form></div>
<h2>Account assistance</h2><p>For permanent account deletion, please use our <a href="{{ route('site.deletion') }}">verified account deletion form</a>. Sending a contact message does not delete an account.</p>
@if(config('site.privacy_email') || config('site.operator_address'))
<h2>Contact details</h2>@include('site.contact')
@endif
</article>
@endsection
