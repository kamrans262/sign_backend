@extends('site.layout')
@section('title','Your work. Digitally connected.')
@section('content')
<section class="hero"><div class="wrap hero-grid">
<div class="reveal"><span class="eyebrow"><span class="dot"></span> Built for trades &amp; service businesses</span>
<h1>Your work.<br><em>Digitally connected.</em></h1>
<p class="lead">Manage your clients, jobs, quotations, signatures and invoices in one organized workspace. Spend less time on paperwork and more time doing the work that matters.</p>
<div class="actions"><a class="button" href="#features">Explore the platform <span aria-hidden="true">↓</span></a><a class="button secondary" href="{{ route(auth()->user()?->is_active && auth()->user()?->is_platform_admin ? 'admin.dashboard' : 'admin.login') }}">{{ auth()->user()?->is_active && auth()->user()?->is_platform_admin ? 'Open dashboard' : 'Administrator access' }}</a></div>
<div class="hero-notes"><span>Job management</span><span>Digital documents</span><span>Secure workflows</span></div></div>
<div class="visual" aria-label="Illustration of the Sign2Digital workspace">
<div class="mock"><div class="mock-top"><img src="{{ route('admin.asset',['file'=>'logo.png'],false) }}" alt=""><span class="sample">Workspace preview</span></div>
<div class="mock-title">Everything in one place</div>
<div class="mock-grid">
<div class="mock-card"><span class="symbol" aria-hidden="true">▤</span><strong>Jobs</strong><small>Plan &amp; track</small></div>
<div class="mock-card"><span class="symbol" aria-hidden="true">€</span><strong>Quotations</strong><small>Create &amp; approve</small></div>
<div class="mock-card"><span class="symbol" aria-hidden="true">✎</span><strong>Documents</strong><small>Prepare &amp; sign</small></div>
<div class="mock-card"><span class="symbol" aria-hidden="true">◷</span><strong>Time &amp; labour</strong><small>Record &amp; review</small></div>
</div><div class="mock-row"><span class="symbol" aria-hidden="true">✓</span><div><strong>Work acceptance</strong><div class="small">Example document</div></div><span class="signed">Signed</span></div>
</div><div class="float"><span class="symbol" aria-hidden="true">✓</span> Digital workflow</div></div>
</div></section>
<section class="section" id="features"><div class="wrap"><div class="intro"><span class="eyebrow">Your digital toolkit</span><h2>Everything your business needs to stay organized.</h2><p>Connect daily operations with professional customer documents and a clear record of work.</p></div>
<div class="tiles">
@foreach([
['▤','Clients & jobs','Manage client details, job information, photos and notes from a single workspace.'],
['✎','Quotes & signatures','Prepare quotations, send approved documents and capture electronic signatures.'],
['▧','Reports & documents','Create site reports, meeting minutes, addenda and work acceptance documents.'],
['€','Invoices & expenses','Record expenses, deposits and invoices, and track payment status.'],
['◷','Time, labour & equipment','Record working time, labour entries and equipment activity.'],
['✦','AI assistance & reminders','Use AI-assisted drafting where available and receive reminders for important work.']
] as [$icon,$name,$description])
<article class="tile"><span class="symbol" aria-hidden="true">{{ $icon }}</span><h3>{{ $name }}</h3><p>{{ $description }}</p></article>
@endforeach
</div></div></section>
<section class="section band"><div class="wrap"><div class="intro"><span class="eyebrow">A connected workflow</span><h2>From enquiry to completed work.</h2></div>
<div class="steps"><article><span class="step-number">01 / PLAN</span><h3>Set up your job</h3><p class="small">Add the client, define the work and prepare a quotation.</p></article><article><span class="step-number">02 / DOCUMENT</span><h3>Manage the work</h3><p class="small">Record progress, time and expenses, and prepare customer documents.</p></article><article><span class="step-number">03 / COMPLETE</span><h3>Sign and invoice</h3><p class="small">Capture acceptance, issue documents and follow up on payments.</p></article></div></div></section>
<section class="section"><div class="wrap"><div class="cta"><div><h2>Manage Sign2Digital with confidence.</h2><p>Authorized administrators can access the platform management portal.</p></div><a class="button" href="{{ route(auth()->user()?->is_active && auth()->user()?->is_platform_admin ? 'admin.dashboard' : 'admin.login') }}">Open administration →</a></div></div></section>
@endsection
