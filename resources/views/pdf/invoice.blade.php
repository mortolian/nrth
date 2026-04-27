<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
@php
    $team = $invoice->team;
    $client = $invoice->client;
    $settings = $team ? $team->mergedCompanySettings() : \App\Models\Team::defaultCompanySettings();

    $issuer = $team ? $team->issuerForInvoicingDocuments() : [
        'name' => config('app.name'),
        'address' => null,
        'email' => null,
        'phone' => null,
        'website' => null,
        'registration_number' => null,
        'vat_number' => null,
    ];
    $companyName = $issuer['name'];
    $companyVat = $issuer['vat_number'];
    $companyReg = $issuer['registration_number'];
    $companyEmail = $issuer['email'];
    $companyPhone = $issuer['phone'];
    $companyWebsite = $issuer['website'];
    $physical = $issuer['address'] ?? '';

    $bank = [
        'name' => $settings['bank_name'] ?? null,
        'holder' => $settings['bank_account_holder'] ?? null,
        'account' => $settings['bank_account_number'] ?? null,
        'branch' => $settings['bank_branch_code'] ?? null,
        'type' => $settings['bank_account_type'] ?? null,
    ];
    $hasBankDetails = collect($bank)->filter()->isNotEmpty();

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

    $subtotal = (int) ($invoice->getRawOriginal('subtotal_cents') ?? 0);
    $vatTotal = (int) ($invoice->getRawOriginal('vat_amount_cents') ?? 0);
    $total = (int) ($invoice->getRawOriginal('total_cents') ?? 0);
    $paid = (int) ($invoice->getRawOriginal('amount_paid_cents') ?? 0);
    $due = max(0, $total - $paid);

    $chargesVat = $team && method_exists($team, 'chargesVat') ? $team->chargesVat() : false;
    $documentTitle = $chargesVat ? 'Tax Invoice' : 'Invoice';
@endphp
    <title>{{ $documentTitle }} {{ $invoice->number }}</title>
    @include('pdf._styles')
</head>
<body>

<table class="brand">
    <tr>
        <td class="logo-cell">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="" style="max-width: 200px; max-height: 70px; object-fit: contain; margin-bottom: 6px;">
            @endif
            <div class="company-name">{{ $companyName }}</div>
            @if($physical)<div class="company-line">{{ $physical }}</div>@endif
            @if($companyEmail)<div class="company-line">{{ $companyEmail }}</div>@endif
            @if($companyPhone)<div class="company-line">{{ $companyPhone }}</div>@endif
            @if($companyWebsite)<div class="company-line">{{ $companyWebsite }}</div>@endif
            <div class="company-line small">
                @if($companyReg)Reg: {{ $companyReg }}@endif
                @if($companyVat) &middot; VAT: {{ $companyVat }}@endif
            </div>
        </td>
        <td class="doc-cell">
            <h1>{{ $documentTitle }}</h1>
            <div class="doc-meta">
                <div><span class="label">Invoice #</span> &nbsp; <span class="b">{{ $invoice->number }}</span></div>
                @if($invoice->reference)
                    <div><span class="label">Reference</span> &nbsp; {{ $invoice->reference }}</div>
                @endif
                <div><span class="label">Issued</span> &nbsp; {{ optional($invoice->issue_date)->format('d M Y') }}</div>
                <div><span class="label">Due</span> &nbsp; {{ optional($invoice->due_date)->format('d M Y') }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">Billed to</div>
            <div class="name">{{ $client?->name ?? 'Client' }}</div>
            @if($client?->contact_name)<p>{{ $client->contact_name }}</p>@endif
            @if($clientAddress)<p>{{ $clientAddress }}</p>@endif
            @if($client?->email)<p>{{ $client->email }}</p>@endif
            @if($client?->phone)<p>{{ $client->phone }}</p>@endif
            @if($client?->vat_number)<p class="small muted">VAT: {{ $client->vat_number }}</p>@endif
            @if($client?->registration_number)<p class="small muted">Reg: {{ $client->registration_number }}</p>@endif
        </td>
        <td class="spacer"></td>
        <td>
            <div class="label">Amount due</div>
            <div class="name accent" style="font-size: 22px;">R {{ number_format($due / 100, 2) }}</div>
            <p class="small muted">Total invoiced: R {{ number_format($total / 100, 2) }}</p>
            @if($paid > 0)
                <p class="small muted">Paid to date: R {{ number_format($paid / 100, 2) }}</p>
            @endif
            <p class="small muted pad-top-12">Please use <span class="b">{{ $invoice->number }}</span> as your payment reference.</p>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: {{ $chargesVat ? '48%' : '58%' }};">Description</th>
            <th class="num" style="width: 8%;">Qty</th>
            <th class="num" style="width: {{ $chargesVat ? '14%' : '22%' }};">Unit</th>
            @if($chargesVat)
                <th class="num" style="width: 10%;">VAT %</th>
                <th class="num" style="width: 10%;">VAT</th>
            @endif
            <th class="num" style="width: {{ $chargesVat ? '14%' : '12%' }};">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->lineItems as $i => $line)
            @php
                $unit = (int) $line->unit_price_cents;
                $qty = (float) $line->quantity;
                $rate = (float) $line->vat_rate;
                $lineVat = (int) $line->vat_amount_cents;
                $lineTotal = (int) $line->total_cents;
            @endphp
            <tr @if($i % 2 === 1) class="zebra" @endif>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="num">R {{ number_format($unit / 100, 2) }}</td>
                @if($chargesVat)
                    <td class="num">{{ number_format($rate * 100, 0) }}%</td>
                    <td class="num">R {{ number_format($lineVat / 100, 2) }}</td>
                @endif
                <td class="num b">R {{ number_format($lineTotal / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label">{{ $chargesVat ? 'Subtotal (excl. VAT)' : 'Subtotal' }}</td>
        <td class="value">R {{ number_format($subtotal / 100, 2) }}</td>
    </tr>
    @if($chargesVat)
    <tr>
        <td class="label">VAT</td>
        <td class="value">R {{ number_format($vatTotal / 100, 2) }}</td>
    </tr>
    @endif
    <tr class="grand">
        <td class="label">Total due</td>
        <td class="value">R {{ number_format($due / 100, 2) }}</td>
    </tr>
</table>

@if($hasBankDetails)
    <div class="section">
        <h3>Banking details</h3>
        <table style="width: 100%;">
            <tr>
                @if($bank['name'])<td style="padding-right: 14px;"><span class="muted small">Bank</span><br><span class="b">{{ $bank['name'] }}</span></td>@endif
                @if($bank['holder'])<td style="padding-right: 14px;"><span class="muted small">Account holder</span><br><span class="b">{{ $bank['holder'] }}</span></td>@endif
                @if($bank['account'])<td style="padding-right: 14px;"><span class="muted small">Account #</span><br><span class="b">{{ $bank['account'] }}</span></td>@endif
                @if($bank['branch'])<td style="padding-right: 14px;"><span class="muted small">Branch</span><br><span class="b">{{ $bank['branch'] }}</span></td>@endif
                @if($bank['type'])<td><span class="muted small">Type</span><br><span class="b">{{ ucfirst($bank['type']) }}</span></td>@endif
            </tr>
        </table>
    </div>
@endif

@if($invoice->notes)
    <div class="section">
        <h3>Notes</h3>
        <p>{!! nl2br(e($invoice->notes)) !!}</p>
    </div>
@endif

@if($invoice->footer)
    <div class="section">
        <h3>Terms &amp; conditions</h3>
        <p>{!! nl2br(e($invoice->footer)) !!}</p>
    </div>
@endif

<div class="footer">
    {{ $companyName }} &middot; {{ $documentTitle }} {{ $invoice->number }} &middot; Generated {{ now()->format('d M Y') }}
</div>
</body>
</html>
