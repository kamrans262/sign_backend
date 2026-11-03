<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Document Signed | Sign2Digital</title>
  <style>
    *{box-sizing:border-box}
    body{margin:0;background:#fff;color:#122536;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;font-size:14px;font-weight:500}
    main{max-width:720px;margin:0 auto;padding:50px 20px 30px;text-align:center}
    .success{font-size:48px;color:#166282;line-height:1.2}
    h1{font-size:24px;line-height:1.3;margin:20px 0 10px}
    p{font-size:14px;line-height:1.5;color:#667085;margin:0 0 20px}
    .details{border:1px solid rgba(217,226,234,.3);border-radius:10px;text-align:left;margin:20px 0 40px;overflow:hidden}
    .row{padding:20px;display:grid;grid-template-columns:130px 1fr;gap:8px;overflow-wrap:anywhere}
    .row+.row{border-top:1px solid #D9E2EA}
    .label{color:#8A9AA8}
    .button{display:block;min-height:48px;padding:13px;background:#166282;border-radius:10px;color:white;font-size:16px;text-decoration:none;text-align:center}
    @media(max-width:400px){.row{grid-template-columns:100px 1fr;}}
  </style>
</head>
<body>
  <main>
    <div class="success" aria-hidden="true">✓</div>
    <h1>Document Signed!</h1>
    <p>Thank you. Your signature has been added to the approved quote, and the completed signed PDF is available below.</p>
    <div class="details" aria-label="Signature receipt">
      <div class="row"><span class="label">Quote:</span><span>{{ $receipt['quoteNumber'] }}</span></div>
      <div class="row"><span class="label">Signed By:</span><span>{{ $receipt['signerName'] }}</span></div>
      <div class="row"><span class="label">Date and Time:</span><span>{{ \Illuminate\Support\Carbon::parse($receipt['signedAt'])->utc()->format('j F Y, H:i') }} UTC</span></div>
      <div class="row"><span class="label">IP address:</span><span>{{ $receipt['ipAddress'] ?? 'Not available' }}</span></div>
    </div>
    <a class="button" href="{{ route('signing.public.signedPdf.browser',['token'=>$token]) }}">Download Signed Document</a>
  </main>
</body>
</html>
