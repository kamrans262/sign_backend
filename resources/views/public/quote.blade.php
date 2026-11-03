<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Quote {{ $quote->reference_number }} | Sign2Digital</title>
  <style>
    :root { color-scheme:light; }
    * { box-sizing:border-box; }
    body { margin:0; background:#fff; color:#122536; font:15px/1.55 Inter,Arial,sans-serif; }
    main { max-width:920px; margin:24px auto; padding:20px; }
    .top { display:flex; justify-content:space-between; align-items:flex-start; gap:25px; border-bottom:1px solid #D9E2EA; padding-bottom:20px; }
    h1 { font-size:28px; margin:0 0 5px; color:#166282; }
    h2 { font-size:17px; margin:27px 0 12px; color:#122536; }
    small,.muted { color:#667085; }
    .tag { background:#E3ECF0; color:#166282; padding:5px 10px; border-radius:10px; font-weight:600; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:22px; }
    .cell { padding:15px; border:1px solid #D9E2EA; border-radius:10px; white-space:pre-wrap; overflow-wrap:anywhere; }
    table { width:100%; border-collapse:collapse; }
    th,td { padding:12px 8px; border-bottom:1px solid #D9E2EA; text-align:left; vertical-align:top; overflow-wrap:anywhere; }
    th { background:#E3ECF0; color:#122536; font-size:13px; }
    td.numeric,th.numeric { text-align:right; white-space:nowrap; }
    .totals { max-width:380px; margin:20px 0 0 auto; }
    .totals p { display:flex; justify-content:space-between; margin:8px 0; gap:15px; }
    .total { font-weight:bold; font-size:20px; border-top:1px solid #D9E2EA; padding-top:12px; }
    .terms { white-space:pre-wrap; background:#fff; border:1px solid #D9E2EA; border-radius:10px; padding:15px; }
    .notice { margin-top:28px; font-size:13px; color:#667085; }
    button { cursor:pointer; border:0; border-radius:10px; background:#166282; color:white; padding:10px 16px; font-weight:600; }
    @media(max-width:650px){.grid { grid-template-columns:1fr; }.top {display:block;}.scroll {overflow-x:auto;} }
    @media print { main {max-width:none; margin:0; padding:0;} button {display:none;} .cell {break-inside:avoid;} }
  </style>
</head>
<body>
<main>
  <div class="top">
    <div><h1>Quote</h1><strong>{{ $quote->company?->name ?? "Sign2Digital" }}</strong><div class="muted">{{ $quote->company?->address }}</div><div class="muted">{{ $quote->company?->email }}</div><div class="muted">{{ $quote->company?->phone_number }}</div><div class="muted">Prepared with Sign2Digital</div></div>
    <div><strong>{{ $quote->reference_number }}</strong>
      <div>{{ $quote->quote_date?->format('d M Y') }}</div>
      <div class="muted">Revision {{ $quote->revision }}</div>
      <div style="margin-top:10px"><span class="tag">{{ ucfirst($quote->status) }}</span></div>
    </div>
  </div>
  <div class="grid">
    <div class="cell"><strong>Prepared for</strong>
      <div>{{ $quote->client_name }}</div>
      <div>{{ $quote->billing_address }}</div>
      @if($quote->client_email)<div>{{ $quote->client_email }}</div>@endif
      @if($quote->client_phone)<div>{{ $quote->client_phone }}</div>@endif
    </div>
    <div class="cell"><strong>Quote details</strong>
      <div>Currency: {{ $quote->currency }}</div>
      <div>VAT rate: {{ number_format($quote->vat_basis_points/100,2) }}%</div>
      @if($quote->valid_until)<div>Valid until: {{ $quote->valid_until->format('d M Y') }}</div>@endif
    </div>
  </div>
  <h2>Work Items</h2>
  <div class="scroll"><table>
    <thead><tr><th>Work / Description</th><th>Dimensions</th><th class="numeric">Qty</th><th class="numeric">Unit Price</th><th class="numeric">Amount</th></tr></thead>
    <tbody>
    @foreach($quote->items as $item)
    <tr>
      <td><strong>{{ $item->title }}</strong><div class="muted">{{ $item->description }}</div></td>
      <td>{{ $item->dimensions ?? '—' }}</td>
      <td class="numeric">{{ $item->quantity }}</td>
      <td class="numeric">{{ $item->unit_price_minor===null?'—':number_format($item->unit_price_minor/100,2) }}</td>
      <td class="numeric">{{ $item->unit_price_minor===null||$item->quantity===null?'—':number_format($item->unit_price_minor*$item->quantity/100,2) }}</td>
    </tr>
    @endforeach
    </tbody>
  </table></div>
  <div class="totals">
    <p><span>Subtotal</span><strong>{{ $quote->currency }} {{ number_format($quote->subtotal_minor/100,2) }}</strong></p>
    <p><span>VAT</span><strong>{{ $quote->currency }} {{ number_format($quote->vat_minor/100,2) }}</strong></p>
    <p class="total"><span>Total</span><span>{{ $quote->currency }} {{ number_format($quote->total_minor/100,2) }}</span></p>
    <p><span>Deposit requested</span><strong>{{ $quote->currency }} {{ number_format($quote->deposit_minor/100,2) }}</strong></p>
  </div>
  <h2>Terms &amp; Conditions</h2>
  <div class="terms">{{ $quote->terms }}</div>
  <div class="notice">This secure quote link expires automatically. A valid signature or acceptance flow must be provided separately; viewing this document does not constitute acceptance.</div>
  <p><button type="button" onclick="window.print()">Print or Save as PDF</button></p>
</main>
</body>
</html>
