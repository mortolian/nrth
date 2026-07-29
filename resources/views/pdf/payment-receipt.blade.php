<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
@php
    $team = $payment->team ?? $invoice->team;
    $client = $invoice->client;

    $issuer = $team ? $team->issuerForInvoicingDocuments() : [
        'name' => config('app.name'),
        'address' => null,
        'address_lines' => [],
        'email' => null,
        'phone' => null,
        'website' => null,
        'registration_number' => null,
        'vat_number' => null,
    ];
    $businessName = $issuer['name'];
    $companyVat = $issuer['vat_number'];
    $companyReg = $issuer['registration_number'];
    $companyEmail = $issuer['email'];
    $companyPhone = $issuer['phone'];
    $companyWebsite = $issuer['website'];
    $physicalLines = $issuer['address_lines'] ?? [];
    $physicalLines = array_values(array_filter(array_map(
        static fn ($line) => trim((string) $line),
        is_array($physicalLines) ? $physicalLines : [],
    )));

    $clientAddress = is_array($client?->address)
        ? trim(collect([
            $client->address['street'] ?? null,
            $client->address['city'] ?? null,
            $client->address['province'] ?? null,
            $client->address['postal_code'] ?? null,
            $client->address['country'] ?? null,
        ])->filter()->implode(', '))
        : '';

    $logoSrc = $team?->logoDataUriForPdf();
    $currency = (string) ($payment->currency ?: $invoice->currency ?: 'ZAR');
    $fmtMoney = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);

    $methodLabel = match ($payment->method?->value ?? null) {
        'cash' => 'Cash',
        'eft' => 'EFT / bank transfer',
        'card' => 'Card',
        'other' => 'Other',
        default => strtoupper((string) ($payment->method?->value ?? 'Payment')),
    };

    $invoiceTotal = (int) ($totals['invoice_total_cents'] ?? 0);
    $paymentCents = (int) ($totals['payment_cents'] ?? 0);
    $paidThrough = (int) ($totals['paid_through_cents'] ?? 0);
    $outstanding = (int) ($totals['outstanding_cents'] ?? 0);
@endphp
    <title>Payment receipt · {{ $invoice->number }}</title>
    @include('pdf._styles')
</head>
<body>

<table class="brand">
    <tr>
        <td class="logo-cell">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="" style="max-width: 200px; max-height: 70px; object-fit: contain; margin-bottom: 6px;">
            @endif
            <div class="company-name">{{ $businessName }}</div>
            @foreach($physicalLines as $line)
                <div class="company-line">{{ $line }}</div>
            @endforeach
            @if($companyEmail)<div class="company-line">{{ $companyEmail }}</div>@endif
            @if($companyPhone)<div class="company-line">{{ $companyPhone }}</div>@endif
            @if($companyWebsite)<div class="company-line">{{ $companyWebsite }}</div>@endif
            <div class="company-line small">
                @if($companyReg)Reg: {{ $companyReg }}@endif
                @if($companyVat) &middot; VAT: {{ $companyVat }}@endif
            </div>
        </td>
        <td class="doc-cell">
            <h1>Receipt</h1>
            <table class="doc-meta">
                <tr>
                    <td class="key">Receipt #:</td>
                    <td class="val strong">{{ $invoice->number }}-P{{ $payment->id }}</td>
                </tr>
                <tr>
                    <td class="key">Payment date:</td>
                    <td class="val">{{ optional($payment->payment_date)->format('d M Y') }}</td>
                </tr>
                <tr>
                    <td class="key">Invoice #:</td>
                    <td class="val">{{ $invoice->number }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">Received from</div>
            <div class="name">{{ $client?->name ?? 'Client' }}</div>
            @if($client?->contact_name)<p>{{ $client->contact_name }}</p>@endif
            @if($clientAddress)<p>{{ $clientAddress }}</p>@endif
            @if($client?->email)<p>{{ $client->email }}</p>@endif
            @if($client?->phone)<p>{{ $client->phone }}</p>@endif
        </td>
        <td class="spacer"></td>
        <td>
            <div class="label">Amount received</div>
            <div class="name accent" style="font-size: 22px;">{{ $fmtMoney($paymentCents) }}</div>
            <p class="small muted">Method: {{ $methodLabel }}</p>
            @if($payment->reference)
                <p class="small muted">Reference: {{ $payment->reference }}</p>
            @endif
        </td>
    </tr>
</table>

<table class="totals" style="width: 55%;">
    <tr>
        <td class="label">Invoice total</td>
        <td class="value">{{ $fmtMoney($invoiceTotal) }}</td>
    </tr>
    <tr>
        <td class="label">This payment</td>
        <td class="value">{{ $fmtMoney($paymentCents) }}</td>
    </tr>
    <tr>
        <td class="label">Paid to date</td>
        <td class="value">{{ $fmtMoney($paidThrough) }}</td>
    </tr>
    <tr class="grand">
        <td class="label">Outstanding</td>
        <td class="value">{{ $fmtMoney($outstanding) }}</td>
    </tr>
</table>

@if($payment->notes)
    <div class="section section-prose pad-top-20">
        <h3>Notes</h3>
        <p>{{ $payment->notes }}</p>
    </div>
@endif

<div class="footer">
    {{ $businessName }} &middot; Payment receipt for {{ $invoice->number }} &middot; Generated {{ now()->format('d M Y') }}
</div>

</body>
</html>
