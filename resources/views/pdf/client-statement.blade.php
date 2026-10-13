<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
@php
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
    $physicalLines = array_values(array_filter(array_map(
        static fn ($line) => trim((string) $line),
        is_array($issuer['address_lines'] ?? null) ? $issuer['address_lines'] : [],
    )));
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $client = $statement['client'] ?? ['name' => '', 'email' => null, 'address_lines' => []];
    $period = $statement['period'] ?? ['from' => '', 'to' => ''];
    $lines = $statement['lines'] ?? [];
@endphp
    <title>Statement — {{ $client['name'] }}</title>
    @include('pdf._styles')
    @include('pdf._statement-styles')
</head>
<body>
<table class="brand">
    <tr>
        <td class="logo-cell">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="" style="max-width: 180px; max-height: 52px; object-fit: contain; margin-bottom: 6px;">
            @endif
            <div class="company-name">{{ $businessName }}</div>
            @foreach($physicalLines as $line)
                <div class="company-line">{{ $line }}</div>
            @endforeach
            @if(!empty($issuer['email']))
                <div class="company-line">{{ $issuer['email'] }}</div>
            @endif
        </td>
        <td class="doc-cell">
            <h1 class="report-title">Client statement</h1>
            <p class="report-subtitle">Invoices, payments, and balance</p>
            <table class="doc-meta">
                <tr><td>Client</td><td>{{ $client['name'] }}</td></tr>
                <tr><td>Period</td><td>{{ $period['from'] }} – {{ $period['to'] }}</td></tr>
                <tr><td>Currency</td><td>{{ $currency }}</td></tr>
                <tr><td>Prepared</td><td>{{ isset($generatedAt) ? $generatedAt->timezone(config('app.timezone'))->format('d M Y H:i') : '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if(!empty($client['email']) || count($client['address_lines'] ?? []) > 0)
    <p class="muted" style="margin: 0 0 12px;">
        {{ $client['name'] }}
        @if(!empty($client['email'])) · {{ $client['email'] }} @endif
        @foreach($client['address_lines'] ?? [] as $line)
            · {{ $line }}
        @endforeach
    </p>
@endif

<table class="summary">
    <tr>
        <td>
            <div class="label">Opening</div>
            <div class="value">{{ $fmt((int) ($statement['opening_cents'] ?? 0)) }}</div>
        </td>
        <td>
            <div class="label">Invoiced</div>
            <div class="value">{{ $fmt((int) ($statement['charges_cents'] ?? 0)) }}</div>
        </td>
        <td>
            <div class="label">Received</div>
            <div class="value">{{ $fmt((int) ($statement['credits_cents'] ?? 0)) }}</div>
        </td>
        <td>
            <div class="label">Closing</div>
            <div class="value">{{ $fmt((int) ($statement['closing_cents'] ?? 0)) }}</div>
        </td>
    </tr>
</table>

<h2 class="section-heading">Activity</h2>
@if(count($lines) === 0)
    <div class="empty-note">No invoices or payments in this period. Opening balance {{ $fmt((int) ($statement['opening_cents'] ?? 0)) }}.</div>
@else
    <table class="sheet">
        <thead>
            <tr>
                <th>Date</th>
                <th>Details</th>
                <th>Reference</th>
                <th class="num">Charge</th>
                <th class="num">Credit</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $period['from'] }}</td>
                <td>Opening balance</td>
                <td></td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num">{{ $fmt((int) ($statement['opening_cents'] ?? 0)) }}</td>
            </tr>
            @foreach($lines as $index => $line)
                <tr class="{{ $index % 2 === 0 ? 'zebra' : '' }}">
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['detail'] }}</td>
                    <td>{{ $line['reference'] }}</td>
                    <td class="num">{{ (int) $line['charge_cents'] > 0 ? $fmt((int) $line['charge_cents']) : '' }}</td>
                    <td class="num">{{ (int) $line['credit_cents'] > 0 ? $fmt((int) $line['credit_cents']) : '' }}</td>
                    <td class="num">{{ $fmt((int) $line['balance_cents']) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="3">Closing balance</td>
                <td class="num">{{ $fmt((int) ($statement['charges_cents'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($statement['credits_cents'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($statement['closing_cents'] ?? 0)) }}</td>
            </tr>
        </tbody>
    </table>
@endif

<p class="disclaimer">
    Charges are issued invoice totals. Credits are payments received in the period. Drafts and void invoices are excluded.
    Amounts are in {{ $currency }}. This statement is for the client named above.
</p>
</body>
</html>
