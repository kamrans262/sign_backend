<section class="payment-card" aria-label="Customer Signature" style="margin-top:20px;padding:20px">
  <h2 class="section-title" style="font-size:18px">Customer Signature</h2>
  <p class="payment-hint" style="margin-top:10px">Review the approved quote and its terms above. Sign as {{ $signing->signer_name }} using the same signature options as Sign2Digital.</p>

  <form id="sign2digital-signing" method="post" action="{{ route('signing.public.submit.browser', ['token'=>$signingToken]) }}" autocomplete="off">
    @csrf
    <input type="hidden" name="quoteId" value="{{ $signing->service_quote_id }}">
    <input type="hidden" name="documentId" value="{{ $signing->document_uuid }}">
    <input type="hidden" name="requestId" value="{{ $signing->request_uuid }}">
    <input type="hidden" name="signerId" value="{{ $signing->signer_uuid }}">
    <input type="hidden" name="documentVersion" value="{{ $signing->approved_revision }}:{{ $signing->approved_pdf_sha256 }}">
    <input type="hidden" name="consentReference" value="{{ $signing->consent_reference }}">
    <input type="hidden" name="method" id="s2d-method" value="draw">
    <input type="hidden" name="signaturePngBase64" id="s2d-signature-image" value="">
    <input type="hidden" name="capturedAt" id="s2d-captured-at" value="">

    <div class="s2d-tabs" aria-label="Signature method">
      <button type="button" data-sign-method="draw" class="s2d-tab is-active" aria-pressed="true">Draw</button>
      <button type="button" data-sign-method="type" class="s2d-tab" aria-pressed="false">Type</button>
      <button type="button" data-sign-method="upload" class="s2d-tab" aria-pressed="false">Upload</button>
    </div>
    <div id="s2d-typed" hidden>
      <label for="s2d-name" class="s2d-label">Type your full name</label>
      <input type="text" id="s2d-name" name="typedName" class="s2d-input" maxlength="255" placeholder="Full name">
    </div>
    <div id="s2d-upload" hidden>
      <label for="s2d-upload-file" class="s2d-label">Upload your signature (PNG)</label>
      <input type="file" id="s2d-upload-file" class="s2d-input" accept="image/png">
    </div>
    <div class="s2d-canvas-container">
      <canvas id="s2d-pad" width="900" height="250" aria-label="Draw or preview your signature">Signature canvas is not available.</canvas>
      <button type="button" id="s2d-clear" class="s2d-clear">Clear Signature</button>
    </div>

    <label class="s2d-consent">
      <input type="checkbox" name="consentAccepted" value="1" required>
      <span>I have reviewed this exact approved quote and its terms and agree to sign electronically.</span>
    </label>
    <p id="s2d-warning" role="alert" class="s2d-warning" hidden></p>
    <button type="submit" id="s2d-submit" class="s2d-submit">Confirm Signature</button>
  </form>
</section>
<style>
  .s2d-tabs { display:flex; gap:10px; margin:20px 0 15px; }
  .s2d-tab { flex:1; min-height:42px; border-radius:10px; border:1px solid rgba(217,226,234,.5); color:var(--ink); background:#fff; font:600 14px Inter,Arial,sans-serif; }
  .s2d-tab.is-active { background:var(--primary); color:#fff; border-color:var(--primary); }
  .s2d-label { display:block; font-size:13px; margin:0 0 8px; }
  .s2d-input { width:100%; min-height:44px; border:1px solid var(--table-lines); border-radius:10px; padding:10px; color:var(--ink); font:500 14px Inter,Arial,sans-serif; margin-bottom:14px; }
  .s2d-canvas-container { background:rgba(138,154,168,.08); border:1px solid var(--table-lines); border-radius:10px; overflow:hidden; }
  #s2d-pad { display:block; width:100%; height:auto; touch-action:none; cursor:crosshair; }
  .s2d-clear { border:0; background:transparent; color:var(--primary); font:600 13px Inter,Arial,sans-serif; padding:10px 14px; cursor:pointer; }
  .s2d-consent { display:flex; gap:12px; align-items:flex-start; margin:20px 0; font-size:14px; line-height:1.5; }
  .s2d-consent input { width:18px; height:18px; flex:0 0 18px; accent-color:var(--primary); margin-top:2px; }
  .s2d-submit { width:100%; min-height:48px; border:0; border-radius:10px; background:var(--primary); color:#fff; font:600 16px Inter,Arial,sans-serif; cursor:pointer; }
  .s2d-submit:disabled { opacity:.65; cursor:progress; }
  .s2d-warning { color:#a11; font-size:13px; margin:12px 0; }
  @media print { #sign2digital-signing,.s2d-tabs,.s2d-warning { display:none !important; } }
</style>
<script>
(() => {
  'use strict';
  const form = document.getElementById('sign2digital-signing');
  if (!form) return;
  const canvas = document.getElementById('s2d-pad');
  const context = canvas.getContext('2d');
  const method = document.getElementById('s2d-method');
  const typed = document.getElementById('s2d-typed');
  const upload = document.getElementById('s2d-upload');
  const name = document.getElementById('s2d-name');
  const uploadFile = document.getElementById('s2d-upload-file');
  const warning = document.getElementById('s2d-warning');
  const submit = document.getElementById('s2d-submit');
  let active = false, marked = false, uploaded = false;
  const clean = () => {
    context.clearRect(0,0,canvas.width,canvas.height);
    context.lineWidth = 5;
    context.lineJoin = 'round';
    context.lineCap = 'round';
    context.strokeStyle = '#122536';
    marked = false;
  };
  clean();
  function showError(message) { warning.textContent = message; warning.hidden = false; }
  function hideError() { warning.textContent = ''; warning.hidden = true; }
  const point = e => {
    const rect = canvas.getBoundingClientRect();
    return {
      x:(e.clientX-rect.left)*canvas.width/rect.width,
      y:(e.clientY-rect.top)*canvas.height/rect.height,
    };
  };
  canvas.addEventListener('pointerdown', e => {
    if (method.value !== 'draw') return;
    active = true; const p=point(e);
    context.beginPath(); context.moveTo(p.x,p.y); context.lineTo(p.x+.2,p.y+.2);
    context.stroke(); marked=true; canvas.setPointerCapture(e.pointerId); hideError();
  });
  canvas.addEventListener('pointermove', e => {
    if (!active || method.value !== 'draw') return;
    const p=point(e); context.lineTo(p.x,p.y); context.stroke();
  });
  const end=()=>{active=false;};
  canvas.addEventListener('pointerup',end); canvas.addEventListener('pointercancel',end);
  function renderTyped() {
    clean(); const value=name.value.trim();
    if (value) {
      context.font = 'italic 62px Georgia,serif';
      context.fillStyle = '#122536';
      context.fillText(value.substring(0,45),36,145,820);
      marked = true;
    }
  }
  name.addEventListener('input',()=>{if(method.value==='type')renderTyped();});
  function setMethod(next) {
    method.value=next; active=false; uploaded=false;clean();hideError();
    typed.hidden=next!=='type';upload.hidden=next!=='upload';
    document.querySelectorAll('[data-sign-method]').forEach(button=>{
      const yes=button.dataset.signMethod===next;
      button.classList.toggle('is-active',yes);
      button.setAttribute('aria-pressed',yes?'true':'false');
    });
    if(next==='type')renderTyped();
  }
  document.querySelectorAll('[data-sign-method]').forEach(button=>
    button.addEventListener('click',()=>setMethod(button.dataset.signMethod)));
  uploadFile.addEventListener('change', async () => {
    clean(); uploaded=false; const file=uploadFile.files && uploadFile.files[0];
    if(!file)return;
    if(file.type!=='image/png'||file.size>300*1024||file.size<200) {
      showError('Upload a PNG signature under 300 KB.');return;
    }
    const image=new Image();
    const url=URL.createObjectURL(file);
    try {
      await new Promise((resolve,reject)=>{
        image.onload=resolve;image.onerror=reject;image.src=url;
      });
      if(image.width<60||image.height<24||image.width>2400||image.height>1200) {
        showError('Invalid signature image dimensions.');return;
      }
      context.drawImage(image,20,15,860,220);marked=true;uploaded=true;hideError();
    } catch {showError('Unable to read this signature image.');}
    finally {URL.revokeObjectURL(url);}
  });
  document.getElementById('s2d-clear').addEventListener('click',()=>{
    clean();uploadFile.value='';uploaded=false;if(method.value==='type')name.value='';hideError();
  });
  form.addEventListener('submit',e=>{
    hideError();
    if(!marked || (method.value==='upload'&&!uploaded)) {
      e.preventDefault();showError('Please add a signature before confirming.');return;
    }
    if(method.value==='type'&&!name.value.trim()) {
      e.preventDefault();showError('Please type your full name.');return;
    }
    const data=canvas.toDataURL('image/png').split(',')[1];
    if(!data || data.length>410000) {
      e.preventDefault();showError('Your signature image is too large.');return;
    }
    document.getElementById('s2d-signature-image').value=data;
    document.getElementById('s2d-captured-at').value=new Date().toISOString();
    submit.disabled=true;submit.textContent='Confirming…';
  });
})();
</script>
