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
    $assets = $report['assets'] ?? [];
    $liabilities = $report['liabilities'] ?? [];
    $equity = $report['equity'] ?? [];
    $totals = $report['totals'] ?? ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'liabilities_plus_equity' => 0];
    $balanced = (bool) ($report['is_balanced'] ?? false);
@endphp
    <title>Balance sheet — {{ $businessName }}</title>
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
        </td>
        <td class="doc-cell">
            <h1 class="report-title">Balance sheet</h1>
            <p class="report-subtitle">Statement of financial position</p>
            <table class="doc-meta">
                <tr><td>As of</td><td>{{ $as_of ?? '' }}</td></tr>
                <tr><td>Currency</td><td>{{ $currency }}</td></tr>
                <tr><td>Prepared</td><td>{{ isset($generatedAt) ? $generatedAt->timezone(config('app.timezone'))->format('d M Y H:i') : '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td>
            <div class="label">Assets</div>
            <div class="value">{{ $fmt((int) $totals['assets']) }}</div>
        </td>
        <td>
            <div class="label">Liabilities</div>
            <div class="value">{{ $fmt((int) $totals['liabilities']) }}</div>
        </td>
        <td>
            <div class="label">Equity</div>
            <div class="value">{{ $fmt((int) $totals['equity']) }}</div>
        </td>
    </tr>
</table>

<h2 class="section-heading">Assets</h2>
<table class="sheet">
    <thead>
        <tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @forelse($assets as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">No asset balances.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total assets</td>
            <td class="num">{{ $fmt((int) $totals['assets']) }}</td>
        </tr>
    </tbody>
</table>

<h2 class="section-heading">Liabilities</h2>
<table class="sheet">
    <thead>
        <tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @forelse($liabilities as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">No liability balances.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total liabilities</td>
            <td class="num">{{ $fmt((int) $totals['liabilities']) }}</td>
        </tr>
    </tbody>
</table>

<h2 class="section-heading">Equity</h2>
<table class="sheet">
    <thead>
        <tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @forelse($equity as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">No equity balances.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total equity</td>
            <td class="num">{{ $fmt((int) $totals['equity']) }}</td>
        </tr>
        <tr class="grand">
            <td colspan="2">Liabilities + equity</td>
            <td class="num">{{ $fmt((int) $totals['liabilities_plus_equity']) }}</td>
        </tr>
    </tbody>
</table>

<p class="disclaimer">
    {{ $balanced ? 'Assets equal liabilities plus equity.' : 'Assets do not equal liabilities plus equity. Income and expense accounts are not closed into equity on this statement.' }}
    Balances are posted ledger amounts as of {{ $as_of ?? 'the selected date' }}, in {{ $currency }}.
</p>
</body>
</html>
