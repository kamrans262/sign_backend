<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Quote {{ $quote->custom_quote_number ?: $quote->reference_number }} | Sign2Digital</title>
  <style>
    /* Mirrors the existing Flutter QuotePreviewScreen and its shared
       Sign2DigitalFinancialDocumentSections; no new visual system. */
    :root {
      color-scheme: light;
      --primary: #166282;
      --ink: #122536;
      --placeholder: #8A9AA8;
      --border: rgba(217,226,234,.3);
      --table-lines: #E5EBF0;
    }
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
      margin: 0;
      background: #fff;
      color: var(--ink);
      font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
      font-size: 14px;
      font-weight: 500;
      letter-spacing: -.3px;
    }
    .screen-header { height: 109px; display: flex; align-items: center; justify-content: center; }
    .screen-header h1 {
      margin: 0; color: var(--ink); font-size: 28px; line-height: 1.2;
      font-weight: 700; text-align: center;
    }
    main { width: 100%; max-width: 940px; margin: 0 auto; padding: 0 20px 30px; }
    .document-card, .payment-card {
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      overflow: hidden;
    }
    .document-top {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 8px; padding: 25px 20px 0;
    }
    .document-logo { display: block; width: 132px; height: 43px; object-fit: contain; object-position: left center; }
    .document-id { text-align: right; min-width: 0; max-width: 50%; overflow-wrap: anywhere; }
    .document-id .document-type { font-size: 18px; font-weight: 700; color: var(--ink); }
    .document-id .document-number { margin-top: 9px; font-size: 12px; color: var(--placeholder); font-weight: 500; }
    .billing-section {
      display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr);
      align-items: start; gap: 16px; padding: 20px 20px 40px;
    }
    .bill-to { min-width: 0; }
    .bill-to strong { display: block; font-size: 14px; font-weight: 600; margin-bottom: 9px; }
    .bill-to span {
      display: block; font-size: 12px; color: var(--placeholder);
      line-height: 1.5; margin-bottom: 5px; white-space: pre-line; overflow-wrap: anywhere;
    }
    .dates { min-width: 0; }
    .date-line { display: flex; gap: 7px; align-items: flex-start; font-size: 12px; }
    .date-line + .date-line { margin-top: 9px; }
    .date-line strong { flex: 0 0 76px; font-weight: 600; white-space: nowrap; }
    .date-line span { flex: 1; min-width: 0; color: var(--placeholder); line-height: 1.5; }
    .item-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .item-table col:nth-child(1) { width: 42.857%; }
    .item-table col:nth-child(2) { width: 9.524%; }
    .item-table col:nth-child(3), .item-table col:nth-child(4) { width: 23.810%; }
    .item-table th, .item-table td {
      padding: 13px 4px; overflow-wrap: anywhere; vertical-align: middle;
      font-size: 12px; font-weight: 500; line-height: 1.45;
    }
    .item-table th { background: var(--primary); color: #fff; padding-top: 10px; padding-bottom: 10px; }
    .item-table th:first-child, .item-table td:first-child { padding-left: 20px; text-align: left; }
    .item-table th:last-child, .item-table td:last-child { padding-right: 20px; text-align: right; }
    .item-table th:not(:first-child), .item-table td:not(:first-child) { text-align: center; }
    .item-table th:last-child, .item-table td:last-child { text-align: right; }
    .item-table td { color: var(--placeholder); }
    .item-table td:first-child { color: var(--ink); font-size: 10px; }
    .item-table td + td, .item-table th + th { border-left: 1px solid var(--table-lines); }
    .item-table tbody tr + tr td { border-top: 1px solid var(--table-lines); }
    .summary { width: 75%; margin: 10px 20px 0 auto; }
    .summary-row {
      display: flex; gap: 12px; align-items: flex-start; justify-content: space-between;
      padding: 8px 12px; font-size: 12px; line-height: 1.5;
    }
    .summary-row .summary-label { flex: 1 1 50%; color: var(--placeholder); }
    .summary-row .summary-amount { flex: 1 1 50%; color: var(--ink); text-align: right; }
    .summary-row.grand-total { background: var(--primary); }
    .summary-row.grand-total .summary-label, .summary-row.grand-total .summary-amount { color: #fff; }
    .signature-fields { display: flex; gap: 20px; padding: 80px 20px 25px; }
    .signature-field { flex: 1; min-width: 0; }
    .signature-line { height: 2px; background: var(--placeholder); }
    .signature-caption { margin-top: 7px; font-size: 10px; color: var(--placeholder); }
    .payment-card { margin-top: 20px; padding: 15px; }
    .section-title { margin: 0; font-size: 14px; font-weight: 600; line-height: 1.4; }
    .payment-status { margin: 5px 0 20px; font-size: 12px; }
    .payment-hint { margin: 0; color: var(--placeholder); font-size: 12px; line-height: 1.5; }
    .payment-fields { margin-top: 12px; display: grid; gap: 6px; }
    .payment-field span { display: block; font-size: 10px; line-height: 1.45; }
    .payment-field span + span { color: var(--placeholder); white-space: pre-wrap; overflow-wrap: anywhere; }
    .terms-section { margin-top: 20px; padding: 0; }
    .terms-section h2 { margin: 0 0 15px; font-size: 15px; font-weight: 600; line-height: 1.4; }
    .terms-section p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--placeholder); white-space: pre-wrap; overflow-wrap: anywhere; font-weight: 500; }
    @media (max-width: 380px) {
      .billing-section { grid-template-columns: minmax(0,1fr); gap: 15px; }
      .item-table th, .item-table td { font-size: 10px; }
      .item-table th:first-child, .item-table td:first-child { padding-left: 8px; }
      .item-table th:last-child, .item-table td:last-child { padding-right: 6px; }
    }
    @media print {
      @page { size: A4; margin: 12mm; }
      body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      main { max-width: none; }
      .screen-header { height: 72px; }
      .payment-card, .terms-section, .signature-fields { break-inside: avoid; }
      .item-table thead { display: table-header-group; }
      .item-table tr { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <header class="screen-header"><h1>Quote Preview</h1></header>
  <main>
    <section class="document-card" aria-label="Quote">
      <div class="document-top">
        <img class="document-logo" src="{{ route('admin.asset', ['file' => 'logo.png'], false) }}" width="132" height="43" alt="{{ $quote->company?->name ?: 'Sign2Digital' }}">
        <div class="document-id">
          <div class="document-type">Quote</div>
          <div class="document-number">{{ $quote->custom_quote_number ?: $quote->reference_number }}</div>
        </div>
      </div>
      <div class="billing-section">
        <div class="bill-to">
          <strong>Bill To:</strong>
          <span>{{ $quote->client_name }}</span>
          @if(trim((string) $quote->billing_address) !== '')<span>{{ $quote->billing_address }}</span>@endif
          @if($quote->client_email)<span>{{ $quote->client_email }}</span>@endif
          @if($quote->client_phone)<span>{{ $quote->client_phone }}</span>@endif
        </div>
        <div class="dates">
          <div class="date-line"><strong>Date:</strong><span>{{ $quote->quote_date?->format('j F Y') ?? 'Not specified' }}</span></div>
          <div class="date-line"><strong>Valid Until:</strong><span>{{ $quote->valid_until?->format('j F Y') ?? 'Not specified' }}</span></div>
        </div>
      </div>
      <table class="item-table" aria-label="Work items">
        <colgroup><col><col><col><col></colgroup>
        <thead><tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit Price</th><th scope="col">Total</th></tr></thead>
        <tbody>
          @foreach($quote->items as $item)
          <tr>
            <td>{{ $item->title }}</td>
            <td>{{ $item->quantity }}</td>
            <td>{{ $item->unit_price_minor===null?'—':$quote->currency.' '.number_format($item->unit_price_minor/100,2) }}</td>
            <td>{{ $item->unit_price_minor===null || $item->quantity===null?'—':$quote->currency.' '.number_format(($item->quantity*$item->unit_price_minor)/100,2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
      <div class="summary">
        <div class="summary-row"><span class="summary-label">Subtotal</span><span class="summary-amount">{{ $quote->currency }} {{ number_format($quote->subtotal_minor/100,2) }}</span></div>
        <div class="summary-row"><span class="summary-label">Tax ({{ rtrim(rtrim(number_format($quote->vat_basis_points/100,2,'.',''),'0'),'.') }}%)</span><span class="summary-amount">{{ $quote->currency }} {{ number_format($quote->vat_minor/100,2) }}</span></div>
        <div class="summary-row grand-total"><span class="summary-label">Total</span><span class="summary-amount">{{ $quote->currency }} {{ number_format($quote->total_minor/100,2) }}</span></div>
        @if($quote->deposit_basis_points > 0)
        <div class="summary-row"><span class="summary-label">Deposit due ({{ rtrim(rtrim(number_format($quote->deposit_basis_points/100,2,'.',''),'0'),'.') }}%)</span><span class="summary-amount">{{ $quote->currency }} {{ number_format($quote->deposit_minor/100,2) }}</span></div>
        <div class="summary-row"><span class="summary-label">Remaining after deposit</span><span class="summary-amount">{{ $quote->currency }} {{ number_format(($quote->total_minor-$quote->deposit_minor)/100,2) }}</span></div>
        @endif
      </div>
      <div class="signature-fields" aria-label="Signature lines">
        <div class="signature-field"><div class="signature-line"></div><div class="signature-caption">Customer Signature</div></div>
        <div class="signature-field"><div class="signature-line"></div><div class="signature-caption">Date</div></div>
      </div>
    </section>
    <section class="payment-card" aria-label="Payment Details">
      <h2 class="section-title">Payment Details</h2>
      <p class="payment-status">Payment QR unavailable</p>
      <p class="payment-hint">A valid Swiss payment account, payee address and payable amount are needed to display a payment QR.</p>
      @if(!empty($quote->payment_details))
      <div class="payment-fields">
        @if(!empty($quote->payment_details['iban']))
        <div class="payment-field"><span>Account / payable to</span><span>{{ $quote->payment_details['iban'] }}</span></div>
        @endif
        @if(!empty($quote->payment_details['accountHolder']))
        <div class="payment-field"><span>Company Name</span><span>{{ $quote->payment_details['accountHolder'] }}</span></div>
        @endif
        <div class="payment-field"><span>Payable amount</span><span>{{ $quote->currency }} {{ number_format($quote->total_minor/100,2) }}</span></div>
        <div class="payment-field"><span>Currency</span><span>{{ $quote->currency }}</span></div>
      </div>
      @endif
    </section>
    <section class="terms-section">
      <h2>General Terms &amp; Conditions</h2>
      <p>{{ trim((string) $quote->terms) === '' ? 'No terms and conditions have been provided.' : $quote->terms }}</p>
    </section>
  </main>
</body>
</html>
