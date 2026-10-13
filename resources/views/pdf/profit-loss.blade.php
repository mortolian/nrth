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
    $period = $period ?? ['from' => '', 'to' => ''];
    $hasComparison = (bool) ($has_comparison ?? false);
    $compareLabel = match ($compare ?? 'none') {
        'previous_period' => 'Previous period',
        'same_period_last_year' => 'Same period last year',
        default => 'Comparison',
    };
    $income = $income ?? [];
    $expenses = $expenses ?? [];
    $totals = $totals ?? ['income' => 0, 'expenses' => 0, 'net_profit' => 0];
    $comparisonTotals = $comparison_totals ?? ['income' => 0, 'expenses' => 0, 'net_profit' => 0];
@endphp
    <title>Profit and loss — {{ $businessName }}</title>
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
            <h1 class="report-title">Profit and loss</h1>
            <p class="report-subtitle">Statement of income and expenses</p>
            <table class="doc-meta">
                <tr><td>Period</td><td>{{ $period['from'] }} – {{ $period['to'] }}</td></tr>
                @if($hasComparison && !empty($comparison_period))
                    <tr><td>{{ $compareLabel }}</td><td>{{ $comparison_period['from'] }} – {{ $comparison_period['to'] }}</td></tr>
                @endif
                <tr><td>Currency</td><td>{{ $currency }}</td></tr>
                <tr><td>Prepared</td><td>{{ isset($generatedAt) ? $generatedAt->timezone(config('app.timezone'))->format('d M Y H:i') : '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td>
            <div class="label">Income</div>
            <div class="value">{{ $fmt((int) $totals['income']) }}</div>
        </td>
        <td>
            <div class="label">Expenses</div>
            <div class="value">{{ $fmt((int) $totals['expenses']) }}</div>
        </td>
        <td>
            <div class="label">Net profit</div>
            <div class="value">{{ $fmt((int) $totals['net_profit']) }}</div>
        </td>
    </tr>
</table>

<h2 class="section-heading">Income</h2>
<table class="sheet">
    <thead>
        <tr>
            <th>Code</th>
            <th>Account</th>
            <th class="num">Amount</th>
            @if($hasComparison)
                <th class="num">{{ $compareLabel }}</th>
                <th class="num">Change</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse($income as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
                @if($hasComparison)
                    <td class="num">{{ $fmt((int) $line['comparison']) }}</td>
                    <td class="num">{{ $fmt((int) $line['amount'] - (int) $line['comparison']) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $hasComparison ? 5 : 3 }}" class="muted">No income in this period.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total income</td>
            <td class="num">{{ $fmt((int) $totals['income']) }}</td>
            @if($hasComparison)
                <td class="num">{{ $fmt((int) ($comparisonTotals['income'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) $totals['income'] - (int) ($comparisonTotals['income'] ?? 0)) }}</td>
            @endif
        </tr>
    </tbody>
</table>

<h2 class="section-heading">Expenses</h2>
<table class="sheet">
    <thead>
        <tr>
            <th>Code</th>
            <th>Account</th>
            <th class="num">Amount</th>
            @if($hasComparison)
                <th class="num">{{ $compareLabel }}</th>
                <th class="num">Change</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse($expenses as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
                @if($hasComparison)
                    <td class="num">{{ $fmt((int) $line['comparison']) }}</td>
                    <td class="num">{{ $fmt((int) $line['amount'] - (int) $line['comparison']) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $hasComparison ? 5 : 3 }}" class="muted">No expenses in this period.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total expenses</td>
            <td class="num">{{ $fmt((int) $totals['expenses']) }}</td>
            @if($hasComparison)
                <td class="num">{{ $fmt((int) ($comparisonTotals['expenses'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) $totals['expenses'] - (int) ($comparisonTotals['expenses'] ?? 0)) }}</td>
            @endif
        </tr>
        <tr class="grand">
            <td colspan="2">Net profit</td>
            <td class="num">{{ $fmt((int) $totals['net_profit']) }}</td>
            @if($hasComparison)
                <td class="num">{{ $fmt((int) ($comparisonTotals['net_profit'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) $totals['net_profit'] - (int) ($comparisonTotals['net_profit'] ?? 0)) }}</td>
            @endif
        </tr>
    </tbody>
</table>

<p class="disclaimer">
    Posted income and expense accounts for the period. Drafts and void reversals are excluded. This statement matches the profit and loss screen. It is not tax advice.
</p>
</body>
</html>
