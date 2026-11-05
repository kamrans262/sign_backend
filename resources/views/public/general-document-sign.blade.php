<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title>Sign2Digital | Sign General Document</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:20px;background:#fff;color:#122536;font:14px Inter,system-ui,-apple-system,Arial,sans-serif}
main{max-width:750px;margin:20px auto;padding:24px;border:1px solid #d9e2ea;border-radius:10px}
h1{color:#166282;margin:0 0 14px;font-size:23px}p{line-height:1.5}.meta{color:#667085;font-size:13px}
label{display:block;font-weight:600;margin:15px 0 8px}
input[type=text]{display:block;width:100%;padding:12px;border:1px solid #d9e2ea;border-radius:10px;font:inherit}
canvas{border:1px solid #d9e2ea;border-radius:10px;touch-action:none;width:100%;height:160px;background:#fff}
.button,button{display:inline-block;background:#166282;color:#fff;text-decoration:none;border:0;padding:12px 20px;border-radius:10px;font:600 14px Inter,system-ui;cursor:pointer}
button.secondary{background:#e3ecf0;color:#122536}.consent{display:flex;gap:10px;align-items:start;font-weight:400}
@media(max-width:550px){body{padding:8px}main{padding:16px}}
</style></head><body><main>
<h1>Review and Sign General Document</h1>
<p class="meta">{{ $signing['company_name'] }} · {{ $signing['number'] }}</p>
<h2>{{ $signing['title'] }}</h2>
<p>Invited signer: <strong>{{ $signing['signer_name'] }}</strong></p>
<p class="meta">Please open the exact approved PDF before signing. This is an evidenced electronic signature, not a qualified cryptographic certificate. Some documents, including powers of attorney, may require additional formalities.</p>
<a class="button" target="_blank" rel="noopener noreferrer" href="{{ route('general-document.sign.pdf',['token'=>$token]) }}">Review Official PDF</a>
@if($signing['status']==='signed')
<p>This document was signed already.</p>
<a class="button" href="{{ route('general-document.sign.receipt',['token'=>$token]) }}">View Signing Receipt</a>
@else
<form id="general-sign" method="post" action="{{ route('general-document.sign.submit',['token'=>$token]) }}">
@csrf
<input type="hidden" name="request_uuid" value="{{ $signing['request_uuid'] }}">
<input type="hidden" name="document_uuid" value="{{ $signing['document_uuid'] }}">
<input type="hidden" name="consent_reference" value="{{ $signing['consent_reference'] }}">
<input type="hidden" name="revision" value="{{ $signing['revision'] }}">
<label for="name">Signer Name</label>
<input id="name" type="text" name="signer_name" value="{{ $signing['signer_name'] }}" required readonly>
<label>Draw Your Signature</label>
<canvas id="ink" width="720" height="240" aria-label="Draw your electronic signature"></canvas>
<button class="secondary" type="button" id="clear">Clear</button>
<input type="hidden" id="signature_png" name="signature_png" required>
<label class="consent"><input type="checkbox" name="consent" value="1" required>
<span>I have reviewed the exact approved document and voluntarily agree to sign it electronically. I understand the submitted signature and consent evidence will be recorded in the signed PDF.</span></label>
<p><button id="submit" type="submit">Sign Document</button></p>
</form>
<script>
(function(){
 const canvas=document.getElementById('ink'),ctx=canvas.getContext('2d');
 ctx.strokeStyle='#122536';ctx.lineWidth=3.8;ctx.lineCap='round';ctx.lineJoin='round';
 let active=false,marks=0;
 function coords(event){const r=canvas.getBoundingClientRect();return {x:(event.clientX-r.left)*canvas.width/r.width,y:(event.clientY-r.top)*canvas.height/r.height}}
 canvas.addEventListener('pointerdown',event=>{active=true;const p=coords(event);ctx.beginPath();ctx.moveTo(p.x,p.y);canvas.setPointerCapture(event.pointerId)});
 canvas.addEventListener('pointermove',event=>{if(!active)return;const p=coords(event);ctx.lineTo(p.x,p.y);ctx.stroke();marks++});
 canvas.addEventListener('pointerup',()=>active=false);
 canvas.addEventListener('pointercancel',()=>active=false);
 document.getElementById('clear').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);marks=0;document.getElementById('signature_png').value=''});
 document.getElementById('general-sign').addEventListener('submit',event=>{
   if(marks<4){event.preventDefault();alert('Please draw your signature before submitting.');return}
   document.getElementById('signature_png').value=canvas.toDataURL('image/png').split(',')[1];
   document.getElementById('submit').disabled=true;
 });
})();
</script>
@endif
<p class="meta">Signing link expires on {{ \Illuminate\Support\Carbon::parse($signing['expires_at'])->utc()->format('Y-m-d H:i') }} UTC.</p>
</main></body></html>
