<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sign2Digital | Work Acceptance</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,sans-serif;color:#122536;background:#f5f8fa}
*{box-sizing:border-box}body{margin:0;padding:20px}.sheet{max-width:760px;margin:10px auto;background:#fff;border:1px solid #d9e2ea;border-radius:10px;padding:24px}
h1{font-size:24px;color:#166282;margin:0 0 10px}h2{font-size:17px;margin:20px 0 10px}
.sub{color:#667085;font-size:13px}.row{margin:14px 0}.row strong{display:block;margin-bottom:4px}
.box{border:1px solid #d9e2ea;padding:12px;border-radius:10px;white-space:pre-wrap;overflow-wrap:anywhere}
label{display:block;font-weight:600;font-size:14px;margin:15px 0 7px}input[type=text],textarea,select{width:100%;padding:12px;border:1px solid #d9e2ea;border-radius:10px;font:inherit}
textarea{min-height:94px;resize:vertical}.choices{display:grid;gap:10px}.choice{border:1px solid #d9e2ea;border-radius:10px;padding:12px;font-weight:500}
#sign{width:100%;height:165px;touch-action:none;border:1px solid #d9e2ea;border-radius:10px;background:#fff}
button,.btn{display:inline-block;background:#166282;border:0;border-radius:10px;padding:12px 20px;color:#fff;font:600 14px Inter,system-ui;text-decoration:none;cursor:pointer}
.secondary{background:#e3ecf0;color:#122536}.consent{display:flex;gap:10px;align-items:start}.consent input{margin-top:4px}
@media(max-width:540px){body{padding:8px}.sheet{padding:20px 16px}}
</style>
</head>
<body>
<main class="sheet">
<h1>Work Acceptance</h1>
<p class="sub">Sign2Digital · {{ $item->number }} · Customer review</p>
<p class="sub">Review the exact approved work report before accepting or signing. You can add reservations or outstanding issues.</p>
<div class="row"><strong>Work completed</strong><div class="box">{{ $item->completed_work }}</div></div>
<div class="row"><strong>Completion date</strong>{{ $item->completion_date }}</div>
<div class="row"><strong>Cleaning / restoration status</strong>{{ ucfirst(str_replace('_',' ',$item->cleaning_status)) }}</div>
@if($item->outstanding_issues)<div class="row"><strong>Reported outstanding issues</strong><div class="box">{{ $item->outstanding_issues }}</div></div>@endif
@if($item->remedial_work)<div class="row"><strong>Remedial work</strong><div class="box">{{ $item->remedial_work }}</div></div>@endif
<a class="btn secondary" href="{{ route('acceptance.public.approvedPdf',['token'=>$token]) }}" target="_blank" rel="noopener noreferrer">View Approved PDF</a>
<h2>Your acceptance decision</h2>
<form id="accept" method="post" enctype="multipart/form-data" action="{{ route('acceptance.public.submit',['token'=>$token]) }}">
@csrf
<input type="hidden" name="request_uuid" value="{{ $signing->request_uuid }}">
<input type="hidden" name="document_uuid" value="{{ $signing->document_uuid }}">
<input type="hidden" name="consent_reference" value="{{ $signing->consent_reference }}">
<input type="hidden" name="revision" value="{{ $signing->approved_revision }}">
<div class="choices">
<label class="choice"><input type="radio" name="decision" value="accepted" required> I accept the completed work</label>
<label class="choice"><input type="radio" name="decision" value="accepted_with_reservations" required> I accept the completed work with reservations</label>
</div>
<label for="name">Customer name</label>
<input id="name" type="text" name="customer_name" maxlength="255" required readonly value="{{ $signing->signer_name }}">
<label for="reservation">Reservations (when applicable)</label>
<textarea id="reservation" name="reservation" maxlength="12000" placeholder="Describe anything you accept with reservations"></textarea>
<label for="issues">Additional outstanding issues</label>
<textarea id="issues" name="outstanding_issues" maxlength="12000" placeholder="Describe any remaining work or issues"></textarea>
<label for="photos">Attach customer evidence photos (up to four, JPG/PNG, 3 MB each)</label>
<input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png" multiple>
<label for="rating">Optional rating</label>
<select id="rating" name="rating"><option value="">Not rated</option>@for($n=1;$n<=5;$n++)<option value="{{ $n }}">{{ $n }} / 5</option>@endfor</select>
<label for="comment">Optional comment</label>
<textarea id="comment" name="comment" maxlength="5000"></textarea>
<h2>Customer signature</h2>
<p class="sub">Draw your signature below. Your decision and any reservations will be included in the signed PDF.</p>
<canvas id="sign" width="720" height="240" aria-label="Draw signature here"></canvas>
<button id="clear" type="button" class="secondary">Clear Signature</button>
<input id="signature" type="hidden" name="signature_png" required>
<label class="consent"><input id="consent" type="checkbox" name="consent" value="1" required>
<span>I have reviewed this work report, confirm my selected acceptance decision and agree to sign electronically. I understand that the submitted signature and my reservations will be recorded in the signed document.</span></label>
<div class="row"><button type="submit" id="submit">Sign and Submit</button></div>
<p class="sub">This private signing link expires on {{ \Illuminate\Support\Carbon::parse($signing->expires_at)->format('Y-m-d H:i') }} UTC.</p>
</form>
</main>
<script>
(function(){
  const canvas=document.getElementById('sign'),ctx=canvas.getContext('2d');
  ctx.strokeStyle='#122536';ctx.lineWidth=3.5;ctx.lineCap='round';ctx.lineJoin='round';
  let drawing=false,marks=0;
  function xy(e){const r=canvas.getBoundingClientRect();return {x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height}}
  canvas.addEventListener('pointerdown',e=>{drawing=true;marks=0;const p=xy(e);ctx.beginPath();ctx.moveTo(p.x,p.y);canvas.setPointerCapture(e.pointerId)});
  canvas.addEventListener('pointermove',e=>{if(!drawing)return;const p=xy(e);ctx.lineTo(p.x,p.y);ctx.stroke();marks++});
  canvas.addEventListener('pointerup',()=>drawing=false);
  canvas.addEventListener('pointercancel',()=>drawing=false);
  document.getElementById('clear').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);marks=0;document.getElementById('signature').value=''});
  document.getElementById('accept').addEventListener('submit',e=>{
    const decision=document.querySelector('input[name=decision]:checked')?.value;
    const evidence=document.getElementById('reservation').value.trim()||document.getElementById('issues').value.trim()||document.getElementById('photos').files.length;
    if(!decision||(decision==='accepted_with_reservations'&&!evidence)||marks<4){e.preventDefault();alert('Choose a decision, describe reservations if applicable, and draw your signature.');return}
    if(document.getElementById('photos').files.length>4){e.preventDefault();alert('Select up to four photos.');return}
    document.getElementById('signature').value=canvas.toDataURL('image/png').split(',')[1];
    document.getElementById('submit').disabled=true;
  });
})();
</script>
</body>
</html>
