@php
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $assets = $report['assets'] ?? [];
    $liabilities = $report['liabilities'] ?? [];
    $equity = $report['equity'] ?? [];
    $totals = $report['totals'] ?? ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'liabilities_plus_equity' => 0];
    $balanced = (bool) ($report['is_balanced'] ?? false);
    $asOf = (string) ($as_of ?? '');
    $sections = [
        ['label' => 'Assets', 'lines' => $assets, 'total' => 'Total assets', 'amount' => (int) $totals['assets'], 'empty' => 'No asset balances.'],
        ['label' => 'Liabilities', 'lines' => $liabilities, 'total' => 'Total liabilities', 'amount' => (int) $totals['liabilities'], 'empty' => 'No liability balances.'],
        ['label' => 'Equity', 'lines' => $equity, 'total' => 'Total equity', 'amount' => (int) $totals['equity'], 'empty' => 'No equity balances.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance sheet — {{ $businessName }}</title>
    @include('pdf._report-styles')
</head>
<body>
@include('pdf._report-header', [
    'title' => 'Balance sheet',
    'subtitle' => 'Statement of financial position',
    'meta' => [
        ['As of', $asOf],
        ['Currency', $currency],
    ],
])

<table class="sheet">
    <thead>
        <tr>
            <th style="width: 16%">Code</th>
            <th>Account</th>
            <th class="num" style="width: 24%">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sections as $section)
            <tr class="group">
                <td colspan="3">{{ $section['label'] }}</td>
            </tr>
            @if(count($section['lines']) === 0)
                <tr><td colspan="3" class="muted">{{ $section['empty'] }}</td></tr>
            @else
                @foreach($section['lines'] as $index => $line)
                    <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                        <td>{{ $line['code'] }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td class="num">{{ $fmt((int) $line['amount']) }}</td>
                    </tr>
                @endforeach
            @endif
            <tr class="total">
                <td colspan="2">{{ $section['total'] }}</td>
                <td class="num">{{ $fmt($section['amount']) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td colspan="2">Liabilities + equity</td>
            <td class="num">{{ $fmt((int) $totals['liabilities_plus_equity']) }}</td>
        </tr>
    </tbody>
</table>

<p class="disclaimer">
    {{ $balanced ? 'Assets equal liabilities plus equity.' : 'Assets do not equal liabilities plus equity. Income and expense accounts are not closed into equity on this statement.' }}
    Balances are posted ledger amounts as of {{ $asOf !== '' ? $asOf : 'the selected date' }}, in {{ $currency }}.
</p>
</body>
</html>
