<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Addendum {{ $addendum->number }} | Sign2Digital</title>
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
    /* Keep the date group aligned to the right edge of the quote card. */
    .dates { min-width: 0; width: min(100%, 240px); justify-self: end; }
    .date-line { display: grid; grid-template-columns: 76px minmax(0,1fr); gap: 7px; align-items: start; font-size: 12px; }
    .date-line + .date-line { margin-top: 9px; }
    .date-line strong { font-weight: 600; white-space: nowrap; }
    .date-line span { min-width: 0; color: var(--placeholder); line-height: 1.5; text-align: right; }
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
    /* Table column 3 (Unit Price) + column 4 (Total): 2 / 4.2 of table width.
       Amounts end at the same 20px inset as the Total cells above. */
    .summary { width: 47.619%; margin: 10px 0 0 auto; }
    .summary-row {
      display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr);
      column-gap: 8px; align-items: start;
      padding: 8px 20px 8px 4px; font-size: 12px; line-height: 1.5;
    }
    .summary-row .summary-label { min-width: 0; color: var(--placeholder); text-align: right; }
    .summary-row .summary-amount { min-width: 0; color: var(--ink); text-align: right; white-space: nowrap; }
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
    /* Same responsive thresholds as Flutter's document sections: bill-to
       stacks when its inner card is below 340px; table narrows below 360px. */
    @media (max-width: 600px) {
      /* Readable percentages and totals on narrow customer devices. */
      .summary { width: 75%; margin-right: 0; }
      .summary-row { grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); }
      .summary-row .summary-label { text-align: left; }
    }
    @media (max-width: 421px) {
      .billing-section { grid-template-columns: minmax(0,1fr); gap: 15px; }
      .dates { width: min(100%, 240px); }
    }
    @media (max-width: 401px) {
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
  <header class="screen-header"><h1>Addendum Preview</h1></header>
  <main>
    <section class="document-card" aria-label="Approved addendum">
      <div class="document-top">
        <div><strong style="color:var(--primary);font-size:19px">Sign2Digital</strong></div>
        <div class="document-id"><div class="document-type">Addendum</div>
          <div class="document-number">{{ $addendum->number }}</div></div>
      </div>
      <div class="billing-section">
        <div class="bill-to">
          <strong>Bill To:</strong>
          <span>{{ $addendum->quote->client_name }}</span>
          <span>{{ $addendum->quote->billing_address }}</span>
          <span>{{ $addendum->quote->client_email }}</span>
        </div>
        <div class="dates"><div class="date-line"><strong>Date:</strong><span>{{ $addendum->effective_date?->format('j F Y') }}</span></div>
          <div class="date-line"><strong>Original:</strong><span>{{ $addendum->quote->custom_quote_number ?: $addendum->quote->reference_number }}</span></div></div>
      </div>
      <div style="padding:0 20px 20px">
        <h2 class="section-title" style="font-size:17px">{{ $addendum->title }}</h2>
        <p style="color:var(--placeholder);white-space:pre-wrap;line-height:1.55">{{ $addendum->change_description }}</p>
        <p class="payment-hint">This is a separate change document. The original signed quote remains unchanged.</p>
      </div>
      <table class="item-table" aria-label="Addendum cost">
        <colgroup><col><col><col><col></colgroup>
        <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
        <tbody><tr><td>{{ $addendum->additional_work ?: 'Changes without additional priced work' }}</td>
          <td>{{ $addendum->quantity ?? '—' }}</td>
          <td>{{ $addendum->unit_price_minor === null ? '—' : $addendum->currency.' '.number_format($addendum->unit_price_minor / 100,2) }}</td>
          <td>{{ $addendum->currency }} {{ number_format($addendum->subtotal_minor / 100,2) }}</td></tr></tbody>
      </table>
      <div class="summary">
        <div class="summary-row"><span class="summary-label">Subtotal</span><span class="summary-amount">{{ $addendum->currency }} {{ number_format($addendum->subtotal_minor / 100,2) }}</span></div>
        <div class="summary-row"><span class="summary-label">VAT ({{ number_format($addendum->vat_basis_points / 100,2) }}%)</span><span class="summary-amount">{{ $addendum->currency }} {{ number_format($addendum->vat_minor / 100,2) }}</span></div>
        <div class="summary-row grand-total"><span class="summary-label">Total</span><span class="summary-amount">{{ $addendum->currency }} {{ number_format($addendum->total_minor / 100,2) }}</span></div>
      </div>
      <div style="padding:20px">
        <a href="{{ route('addenda.public.pdf',['token'=>$signingToken]) }}" style="font-size:13px;color:var(--primary);text-decoration:underline">View exact approved addendum PDF</a>
        <p class="payment-hint" style="margin-top:10px">Review the document carefully before signing. Your signature applies only to this separate addendum.</p>
      </div>
    </section>
    @include('public.signing-form')
  </main>
</body>
</html>
