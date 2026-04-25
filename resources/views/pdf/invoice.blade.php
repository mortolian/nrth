<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; font-size: 12px; margin: 28px; }
        .header { display: table; width: 100%; margin-bottom: 20px; }
        .col { display: table-cell; vertical-align: top; width: 50%; }
        .right { text-align: right; }
        h1 { margin: 0 0 8px; font-size: 24px; color: #0f172a; }
        h2 { margin: 0 0 8px; font-size: 14px; text-transform: uppercase; color: #334155; }
        .meta p, .details p { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 8px 6px; text-align: left; }
        th { background: #f8fafc; color: #334155; font-size: 11px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 14px; width: 42%; margin-left: auto; }
        .totals td { border: none; padding: 5px 0; }
        .totals .label { color: #475569; }
        .totals .value { text-align: right; }
        .totals .grand td { border-top: 1px solid #cbd5e1; font-weight: bold; padding-top: 8px; }
        .block { margin-top: 20px; }
        .footer { margin-top: 26px; color: #64748b; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="col">
            @php($logo = ($invoice->team && method_exists($invoice->team, 'getFirstMediaUrl')) ? $invoice->team->getFirstMediaUrl('logo') : null)
            @if($logo)
                <img src="{{ $logo }}" alt="Company Logo" style="max-width: 180px; max-height: 60px; margin-bottom: 8px;">
            @endif
            <h2>From</h2>
            <div class="details">
                <p><strong>{{ $invoice->team?->name ?? 'Your Company' }}</strong></p>
                <p>Reg no: {{ $invoice->client?->registration_number ?? 'N/A' }}</p>
                <p>VAT no: {{ $invoice->client?->vat_number ?? 'N/A' }}</p>
            </div>
        </div>
        <div class="col right">
            <h1>Tax Invoice</h1>
            <div class="meta">
                <p><strong>Invoice #:</strong> {{ $invoice->number }}</p>
                <p><strong>Issue date:</strong> {{ optional($invoice->issue_date)->format('d M Y') }}</p>
                <p><strong>Due date:</strong> {{ optional($invoice->due_date)->format('d M Y') }}</p>
            </div>
            <div class="block">
                <h2>Bill To</h2>
                <p><strong>{{ $invoice->client?->name ?? 'Client' }}</strong></p>
                <p>{{ $invoice->client?->email }}</p>
                <p>{{ $invoice->client?->phone }}</p>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">VAT</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lineItems as $line)
                @php
                    $subtotal = (float) $line->quantity * (int) $line->unit_price_cents;
                    $lineVat = (int) $line->vat_amount_cents;
                @endphp
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="num">R {{ number_format(((int) $line->unit_price_cents) / 100, 2) }}</td>
                    <td class="num">R {{ number_format($lineVat / 100, 2) }}</td>
                    <td class="num">R {{ number_format(((int) $line->total_cents) / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">R {{ number_format(((int) $invoice->getRawOriginal('subtotal_cents')) / 100, 2) }}</td>
        </tr>
        <tr>
            <td class="label">VAT</td>
            <td class="value">R {{ number_format(((int) $invoice->getRawOriginal('vat_amount_cents')) / 100, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total Due</td>
            <td class="value">R {{ number_format(((int) $invoice->getRawOriginal('total_cents')) / 100, 2) }}</td>
        </tr>
    </table>

    <div class="block">
        <h2>Payment Terms</h2>
        <p>{{ $invoice->notes ?: 'Payment due by the due date shown above.' }}</p>
        <p><strong>Banking details:</strong> Please use invoice number {{ $invoice->number }} as payment reference.</p>
    </div>

    @if($invoice->footer)
        <div class="block">
            <h2>Additional Notes</h2>
            <p>{{ $invoice->footer }}</p>
        </div>
    @endif

    <div class="footer">
        Registration number: {{ $invoice->client?->registration_number ?? 'N/A' }} ·
        VAT number: {{ $invoice->client?->vat_number ?? 'N/A' }}
    </div>
</body>
</html>
