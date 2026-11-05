<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title>Sign2Digital | General Document</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:20px;background:#fff;color:#122536;font:14px Inter,system-ui,-apple-system,Arial,sans-serif}
main{max-width:740px;margin:28px auto;border:1px solid #d9e2ea;border-radius:10px;padding:26px}
h1{margin:0 0 12px;color:#166282;font-size:25px}h2{font-size:19px;margin:10px 0}
p{line-height:1.6}.meta{color:#667085;font-size:13px}.button{display:inline-block;border-radius:10px;padding:12px 20px;background:#166282;color:#fff;text-decoration:none;font-weight:600}
@media(max-width:550px){body{padding:8px}main{padding:20px}}
</style>
</head><body><main>
<h1>General Document</h1>
<p class="meta">Sign2Digital — Approved and issued document</p>
<h2>{{ $document['title'] }}</h2>
<p>Reference: <strong>{{ $document['number'] }}</strong></p>
<p>Issued by: {{ $document['company_name'] }}</p>
@if($document['client_name'])<p>Prepared for: {{ $document['client_name'] }}</p>@endif
<p class="meta">Type: {{ ucwords(str_replace('_',' ',$document['type'])) }} · Revision {{ $document['revision'] }}</p>
<a class="button" rel="noreferrer" href="{{ route('general-document.public.pdf',['token'=>$token]) }}">View Official PDF</a>
<p class="meta">This private viewing link expires automatically. A viewing link does not authorize a signature. Sign2Digital does not represent an ordinary electronic signature as a qualified certificate.</p>
</main></body></html>
