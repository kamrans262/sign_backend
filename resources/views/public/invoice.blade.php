<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer"><title>Sign2Digital Invoice</title>
<style>body{font-family:Inter,-apple-system,BlinkMacSystemFont,Arial,sans-serif;color:#122536;background:#fff;margin:0}
main{max-width:700px;margin:50px auto;padding:20px}h1{font-size:28px;color:#166282}
section{border:1px solid #D9E2EA;border-radius:10px;padding:20px}
dl{display:grid;grid-template-columns:1fr 1fr;gap:12px}dt{color:#667085;font-size:13px}
dd{margin:4px 0 0;font-weight:600}a{display:inline-block;background:#166282;color:#fff;
text-decoration:none;padding:12px 22px;border-radius:10px;margin-top:20px}
small{color:#667085}@media(max-width:480px){dl{grid-template-columns:1fr}main{margin:20px auto}}</style>
</head><body><main><h1>Invoice</h1><section>
<h2>{{ $details['number'] }}</h2><dl>
<div><dt>From</dt><dd>{{ $details['company_name'] }}</dd></div>
<div><dt>To</dt><dd>{{ $details['client_name'] }}</dd></div>
<div><dt>Invoice date</dt><dd>{{ $details['invoice_date'] }}</dd></div>
<div><dt>Due date</dt><dd>{{ $details['due_date'] }}</dd></div>
<div><dt>Total</dt><dd>CHF {{ number_format($details['total_minor'] / 100, 2) }}</dd></div>
<div><dt>At issue</dt><dd>CHF {{ number_format($details['amount_due_at_issue_minor'] / 100, 2) }}</dd></div>
</dl><a href="{{ route('invoices.customer.pdf',['token'=>$token]) }}">View official PDF</a>
<p><small>This is the original issued invoice. Later payments may change the outstanding balance without changing this PDF.</small></p>
</section></main></body></html>
