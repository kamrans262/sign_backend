<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign2Digital | General Document Signature Receipt</title>
<style>body{font:14px Inter,system-ui,Arial,sans-serif;background:#fff;color:#122536;margin:0;padding:24px}
main{max-width:700px;margin:35px auto;border:1px solid #d9e2ea;border-radius:10px;padding:24px}h1{font-size:24px;color:#166282}
p{line-height:1.55}.meta{color:#667085;font-size:13px;overflow-wrap:anywhere}
a{display:inline-block;background:#166282;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px}</style>
</head><body><main>
<h1>Document Signed</h1>
<p>{{ $receipt['title'] }} — {{ $receipt['number'] }}</p>
<p>Signed by: {{ $receipt['signer_name'] }}</p>
<p>Signed at: {{ $receipt['signed_at'] }} UTC</p>
<p class="meta">Original approved SHA-256: {{ $receipt['source_pdf_sha256'] }}</p>
<p class="meta">Signed document SHA-256: {{ $receipt['signed_pdf_sha256'] }}</p>
<a href="{{ route('general-document.sign.signedPdf',['token'=>$token]) }}">Download Signed PDF</a>
<p class="meta">This is an evidenced electronic signature. It does not itself establish qualified electronic-signature status.</p>
</main></body></html>
