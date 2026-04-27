<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote {{ $quote->number }}</title>
    @include('pdf._styles')
</head>
<body>
@php
    $team = $quote->team;
    $client = $quote->client;
    $settings = method_exists($team, 'mergedCompanySettings') ? $team->mergedCompanySettings() : [];

    $companyName = $settings['trading_name'] ?? ($team?->name ?? config('app.name'));
    $companyVat = $settings['vat_number'] ?? null;
    $companyReg = $settings['registration_number'] ?? null;
    $companyEmail = $settings['company_email'] ?? null;
    $companyPhone = $settings['company_phone'] ?? null;
    $companyWebsite = $settings['company_website'] ?? null;

    $physical = trim(collect([
        $settings['physical_street'] ?? null,
        $settings['physical_city'] ?? null,
        $settings['physical_province'] ?? null,
        $settings['physical_postal_code'] ?? null,
        $settings['physical_country'] ?? null,
    ])->filter()->implode(', '));

    $clientAddress = is_array($client?->address)
        ? trim(collect([
            $client->address['street'] ?? null,
            $client->address['city'] ?? null,
            $client->address['province'] ?? null,
            $client->address['postal_code'] ?? null,
            $client->address['country'] ?? null,
        ])->filter()->implode(', '))
        : '';

    $logoUrl = ($team && method_exists($team, 'getFirstMediaUrl')) ? $team->getFirstMediaUrl('logo') : null;

    $statusValue = $quote->status?->value ?? 'draft';
    $statusLabel = strtoupper(str_replace('_', ' ', $statusValue));
    $statusClass = match ($statusValue) {
        'accepted', 'converted' => '',
        'sent' => 'warn',
        'declined', 'expired' => 'danger',
        default => 'warn',
    };

    $subtotal = (int) ($quote->getRawOriginal('subtotal_cents') ?? 0);
    $vatTotal = (int) ($quote->getRawOriginal('vat_amount_cents') ?? 0);
    $total = (int) ($quote->getRawOriginal('total_cents') ?? 0);

    $lines = collect((array) $quote->line_items)->map(function ($line) {
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
@endphp

<table class="brand">
    <tr>
        <td class="logo-cell">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="" style="max-width: 200px; max-height: 70px; margin-bottom: 6px;">
            @endif
            <div class="company-name">{{ $companyName }}</div>
            @if($physical)<div class="company-line">{{ $physical }}</div>@endif
            <div class="company-line">
                @if($companyEmail){{ $companyEmail }}@endif
                @if($companyPhone) &middot; {{ $companyPhone }}@endif
                @if($companyWebsite) &middot; {{ $companyWebsite }}@endif
            </div>
            <div class="company-line small">
                @if($companyReg)Reg: {{ $companyReg }}@endif
                @if($companyVat) &middot; VAT: {{ $companyVat }}@endif
            </div>
        </td>
        <td class="doc-cell">
            <h1>Quote</h1>
            <div class="pill {{ $statusClass }}">{{ $statusLabel }}</div>
            <div class="doc-meta">
                <div><span class="label">Quote #</span> &nbsp; <span class="b">{{ $quote->number }}</span></div>
                <div><span class="label">Issued</span> &nbsp; {{ optional($quote->issue_date)->format('d M Y') }}</div>
                @if($quote->expiry_date)
                    <div><span class="label">Valid until</span> &nbsp; {{ optional($quote->expiry_date)->format('d M Y') }}</div>
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
            <div class="name accent" style="font-size: 22px;">R {{ number_format($total / 100, 2) }}</div>
            <p class="small muted">{{ count($lines) }} item{{ count($lines) === 1 ? '' : 's' }}, VAT incl.</p>
            @if($quote->expiry_date)
                <p class="small muted pad-top-12">This quote is valid until {{ optional($quote->expiry_date)->format('d M Y') }}.</p>
            @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 48%;">Description</th>
            <th class="num" style="width: 8%;">Qty</th>
            <th class="num" style="width: 14%;">Unit</th>
            <th class="num" style="width: 10%;">VAT %</th>
            <th class="num" style="width: 10%;">VAT</th>
            <th class="num" style="width: 14%;">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $i => $line)
            <tr @if($i % 2 === 1) class="zebra" @endif>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="num">R {{ number_format($line['unit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($line['rate'] * 100, 0) }}%</td>
                <td class="num">R {{ number_format($line['vat'] / 100, 2) }}</td>
                <td class="num b">R {{ number_format($line['total'] / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label">Subtotal (excl. VAT)</td>
        <td class="value">R {{ number_format($subtotal / 100, 2) }}</td>
    </tr>
    <tr>
        <td class="label">VAT</td>
        <td class="value">R {{ number_format($vatTotal / 100, 2) }}</td>
    </tr>
    <tr class="grand">
        <td class="label">Quote total</td>
        <td class="value">R {{ number_format($total / 100, 2) }}</td>
    </tr>
</table>

@if($quote->notes)
    <div class="section">
        <h3>Notes</h3>
        <p>{!! nl2br(e($quote->notes)) !!}</p>
    </div>
@endif

@if($quote->terms)
    <div class="section">
        <h3>Terms &amp; conditions</h3>
        <p>{!! nl2br(e($quote->terms)) !!}</p>
    </div>
@endif

<div class="footer">
    {{ $companyName }} &middot; Quote {{ $quote->number }} &middot; Generated {{ now()->format('d M Y') }}
</div>
</body>
</html>
