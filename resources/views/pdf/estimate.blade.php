<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Estimate {{ $estimate->number }}</title>
    @include('pdf._styles')
</head>
<body>
@php
    $team = $estimate->team;
    $client = $estimate->client;

    $issuer = $team ? $team->issuerForInvoicingDocuments('estimate') : [
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

    $statusValue = $estimate->status?->value ?? 'draft';
    $statusLabel = strtoupper(str_replace('_', ' ', $statusValue));
    $statusClass = match ($statusValue) {
        'accepted', 'converted' => '',
        'sent' => 'warn',
        'declined', 'expired' => 'danger',
        default => 'warn',
    };

    $subtotal = (int) ($estimate->getRawOriginal('subtotal_cents') ?? 0);
    $vatTotal = (int) ($estimate->getRawOriginal('vat_amount_cents') ?? 0);
    $total = (int) ($estimate->getRawOriginal('total_cents') ?? 0);

    $lines = collect((array) $estimate->line_items)->map(function ($line) {
        $qty = (float) ($line['quantity'] ?? 1);
        $unit = (int) ($line['unit_price_cents'] ?? 0);
        $rate = (float) ($line['vat_rate'] ?? 0);
        $sub = (int) round($qty * $unit);
        $vat = (int) round($sub * $rate);
        return [
            'description' => $line['description'] ?? '',
            'quantity' => $qty,
            'unit' => $unit,
            'rate' => $rate,
            'vat' => $vat,
            'total' => $sub + $vat,
        ];
    });

    $chargesVat = $team && method_exists($team, 'chargesVat') ? $team->chargesVat() : false;

    $fmtMoney = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, (string) ($estimate->currency ?? 'ZAR'));
@endphp

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
            <h1>Estimate</h1>
            <div class="pill {{ $statusClass }}">{{ $statusLabel }}</div>
            <div class="doc-meta">
                <div><span class="label">Estimate #</span> &nbsp; <span class="b">{{ $estimate->number }}</span></div>
                <div><span class="label">Issued</span> &nbsp; {{ optional($estimate->issue_date)->format('d M Y') }}</div>
                @if($estimate->expiry_date)
                    <div><span class="label">Valid until</span> &nbsp; {{ optional($estimate->expiry_date)->format('d M Y') }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">Prepared for</div>
            <div class="name">{{ $client?->name ?? 'Client' }}</div>
            @if($client?->contact_name)<p>{{ $client->contact_name }}</p>@endif
            @if($clientAddress)<p>{{ $clientAddress }}</p>@endif
            @if($client?->email)<p>{{ $client->email }}</p>@endif
            @if($client?->phone)<p>{{ $client->phone }}</p>@endif
        </td>
        <td class="spacer"></td>
        <td>
            <div class="label">Estimated total</div>
            <div class="name accent" style="font-size: 22px;">{{ $fmtMoney($total) }}</div>
            <p class="small muted">{{ count($lines) }} item{{ count($lines) === 1 ? '' : 's' }}@if($chargesVat), VAT incl.@endif</p>
            @if($estimate->expiry_date)
                <p class="small muted pad-top-12">This estimate is valid until {{ optional($estimate->expiry_date)->format('d M Y') }}.</p>
            @endif
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
        @foreach($lines as $i => $line)
            <tr @if($i % 2 === 1) class="zebra" @endif>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="num">{{ $fmtMoney($line['unit']) }}</td>
                @if($chargesVat)
                    <td class="num">{{ number_format($line['rate'] * 100, 0) }}%</td>
                    <td class="num">{{ $fmtMoney($line['vat']) }}</td>
                @endif
                <td class="num b">{{ $fmtMoney($line['total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label">{{ $chargesVat ? 'Subtotal (excl. VAT)' : 'Subtotal' }}</td>
        <td class="value">{{ $fmtMoney($subtotal) }}</td>
    </tr>
    @if($chargesVat)
    <tr>
        <td class="label">VAT</td>
        <td class="value">{{ $fmtMoney($vatTotal) }}</td>
    </tr>
    @endif
    <tr class="grand">
        <td class="label">Estimate total</td>
        <td class="value">{{ $fmtMoney($total) }}</td>
    </tr>
</table>

@if($estimate->notes)
    <div class="section">
        <h3>Notes</h3>
        <p>{!! nl2br(e($estimate->notes)) !!}</p>
    </div>
@endif

@if($estimate->terms)
    <div class="section">
        <h3>Terms &amp; conditions</h3>
        <p>{!! nl2br(e($estimate->terms)) !!}</p>
    </div>
@endif

<div class="footer">
    {{ $companyName }} &middot; Estimate {{ $estimate->number }} &middot; Generated {{ now()->format('d M Y') }}
</div>
</body>
</html>
