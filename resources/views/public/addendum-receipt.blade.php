<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title>Addendum Signed Successfully | Sign2Digital</title>
<style>:root{--primary:#166282;--ink:#122536}body{margin:0;background:white;color:var(--ink);font:500 14px/1.5 Inter,-apple-system,BlinkMacSystemFont,Arial,sans-serif}
main{max-width:720px;margin:60px auto;padding:20px}.card{border:1px solid rgba(217,226,234,.3);border-radius:10px;padding:24px}h1{font-size:24px}
a{display:inline-block;background:var(--primary);color:white;text-decoration:none;padding:14px 22px;border-radius:10px;margin-top:15px}</style></head>
<body><main><div class="card"><h1>Document Signed Successfully</h1>
<p>Signed addendum: <strong>{{ $addendum->number }}</strong></p>
<p>Signed at (UTC): {{ $addendum->signed_at?->utc()->format('Y-m-d H:i:s') }}</p>
<p>The original signed quote remains unchanged.</p>
<a href="{{ route('addenda.public.signedPdf',['token'=>$token]) }}">Download Signed PDF</a>
</div></main></body></html>
