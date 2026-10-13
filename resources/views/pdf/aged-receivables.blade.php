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
    $clients = $report['clients'] ?? [];
    $totals = $report['totals'] ?? [];
    $asOf = $report['as_of'] ?? '';
    $bucketLabel = [
        'current' => 'Current',
        'days_1_30' => '1–30',
        'days_31_60' => '31–60',
        'days_61_90' => '61–90',
        'over_90' => '90+',
    ];
@endphp
    <title>Aged receivables — {{ $businessName }}</title>
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
            <h1 class="report-title">Aged receivables</h1>
            <p class="report-subtitle">Who owes the business, by due date</p>
            <table class="doc-meta">
                <tr><td>As of</td><td>{{ $asOf }}</td></tr>
                <tr><td>Currency</td><td>{{ $currency }}</td></tr>
                <tr><td>Open invoices</td><td>{{ number_format((int) ($report['invoice_count'] ?? 0)) }}</td></tr>
                <tr><td>Prepared</td><td>{{ isset($generatedAt) ? $generatedAt->timezone(config('app.timezone'))->format('d M Y H:i') : '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td>
            <div class="label">Total owing</div>
            <div class="value">{{ $fmt((int) ($totals['total'] ?? 0)) }}</div>
        </td>
        <td>
            <div class="label">Current</div>
            <div class="value">{{ $fmt((int) ($totals['current'] ?? 0)) }}</div>
        </td>
        <td>
            <div class="label">Overdue</div>
            <div class="value">{{ $fmt((int) (($totals['total'] ?? 0) - ($totals['current'] ?? 0))) }}</div>
        </td>
    </tr>
</table>

<h2 class="section-heading">By client</h2>
@if(count($clients) === 0)
    <div class="empty-note">No open invoices at this date.</div>
@else
    <table class="sheet">
        <thead>
            <tr>
                <th>Client</th>
                <th class="num">Current</th>
                <th class="num">1–30</th>
                <th class="num">31–60</th>
                <th class="num">61–90</th>
                <th class="num">90+</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($clients as $index => $client)
                <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                    <td>{{ $client['name'] }}</td>
                    <td class="num">{{ $fmt((int) $client['current']) }}</td>
                    <td class="num">{{ $fmt((int) $client['days_1_30']) }}</td>
                    <td class="num">{{ $fmt((int) $client['days_31_60']) }}</td>
                    <td class="num">{{ $fmt((int) $client['days_61_90']) }}</td>
                    <td class="num">{{ $fmt((int) $client['over_90']) }}</td>
                    <td class="num">{{ $fmt((int) $client['total']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td class="num">{{ $fmt((int) ($totals['current'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($totals['days_1_30'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($totals['days_31_60'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($totals['days_61_90'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($totals['over_90'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) ($totals['total'] ?? 0)) }}</td>
            </tr>
        </tbody>
    </table>

    <h2 class="section-heading">Invoices</h2>
    @foreach($clients as $client)
        <div class="client-block">
            <p class="client-name">{{ $client['name'] }}</p>
            <table class="sheet">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Bucket</th>
                        <th class="num">Days</th>
                        <th class="num">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($client['invoices'] as $invoice)
                        <tr>
                            <td>{{ $invoice['number'] }}</td>
                            <td>{{ $invoice['issue_date'] }}</td>
                            <td>{{ $invoice['due_date'] ?: '—' }}</td>
                            <td>{{ $bucketLabel[$invoice['bucket']] ?? $invoice['bucket'] }}</td>
                            <td class="num">{{ (int) $invoice['days_overdue'] }}</td>
                            <td class="num">{{ $fmt((int) $invoice['amount_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endif

<p class="disclaimer">
    Outstanding amounts are issued invoices less payments dated on or before {{ $asOf }}, in {{ $currency }}.
    Drafts and void invoices are excluded. Payments after this date are not deducted. This is a management report, not a tax return.
</p>
</body>
</html>
