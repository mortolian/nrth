@php
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
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
    $cols = $hasComparison ? 5 : 3;
    $meta = [
        ['Period', trim(($period['from'] ?? '').' – '.($period['to'] ?? ''), ' –')],
    ];
    if ($hasComparison && ! empty($comparison_period)) {
        $meta[] = [$compareLabel, trim(($comparison_period['from'] ?? '').' – '.($comparison_period['to'] ?? ''), ' –')];
    }
    $meta[] = ['Currency', $currency];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profit and loss — {{ $businessName }}</title>
    @include('pdf._report-styles')
</head>
<body>
@include('pdf._report-header', [
    'title' => 'Profit and loss',
    'subtitle' => 'Statement of income and expenses',
    'meta' => $meta,
])

<table class="sheet">
    <thead>
        <tr>
            <th style="width: 14%">Code</th>
            <th>Account</th>
            <th class="num">Amount</th>
            @if($hasComparison)
                <th class="num">{{ $compareLabel }}</th>
                <th class="num">Change</th>
            @endif
        </tr>
    </thead>
    <tbody>
        <tr class="group">
            <td colspan="{{ $cols }}">Income</td>
        </tr>
        @forelse($income as $index => $line)
            <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
                @if($hasComparison)
                    <td class="num">{{ $fmt((int) $line['comparison']) }}</td>
                    <td class="num">{{ $fmt((int) $line['amount'] - (int) $line['comparison']) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $cols }}" class="muted">No income in this period.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Total income</td>
            <td class="num">{{ $fmt((int) $totals['income']) }}</td>
            @if($hasComparison)
                <td class="num">{{ $fmt((int) ($comparisonTotals['income'] ?? 0)) }}</td>
                <td class="num">{{ $fmt((int) $totals['income'] - (int) ($comparisonTotals['income'] ?? 0)) }}</td>
            @endif
        </tr>
        <tr class="group">
            <td colspan="{{ $cols }}">Expenses</td>
        </tr>
        @forelse($expenses as $index => $line)
            <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $fmt((int) $line['amount']) }}</td>
                @if($hasComparison)
                    <td class="num">{{ $fmt((int) $line['comparison']) }}</td>
                    <td class="num">{{ $fmt((int) $line['amount'] - (int) $line['comparison']) }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $cols }}" class="muted">No expenses in this period.</td></tr>
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
