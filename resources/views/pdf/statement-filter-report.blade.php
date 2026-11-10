<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportName }} — statement filters</title>
    @include('pdf._report-styles')
    <style>
        table.sheet.cols { table-layout: fixed; }
        table.sheet.cols td { word-wrap: break-word; }
    </style>
</head>
<body>
@php
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $period = $period ?? ['from' => '', 'to' => ''];
    $presentation = $presentation ?? ['view' => 'summary', 'breakdown' => 'period'];
    $filters = $filters ?? [];
    $detailed = ($presentation['view'] ?? 'summary') === 'detailed';
    $monthly = ($presentation['breakdown'] ?? 'period') === 'monthly';
@endphp
@include('pdf._report-header', [
    'title' => 'Statement filters',
    'subtitle' => $reportName,
    'meta' => [
        ['Period', trim(($period['from'] ?? '').' – '.($period['to'] ?? ''), ' –')],
        ['Report', $detailed ? 'With transactions' : 'Totals'],
        ['Breakdown', $monthly ? 'By month' : 'Whole period'],
        ['Currency', $currency],
    ],
])

@if($filters === [])
    <div class="empty-note">This report has no filters.</div>
@else
    <table class="sheet cols">
        <colgroup>
            <col style="width: 28%">
            <col style="width: 32%">
            <col style="width: 16%">
            <col style="width: 24%">
        </colgroup>
        <thead>
            <tr>
                <th>Alias</th>
                <th>Filter</th>
                <th class="num">Transactions</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($filters as $filter)
                <tr>
                    <td>{{ $filter['alias'] }}</td>
                    <td>{{ $filter['match'] }}</td>
                    <td class="num">{{ number_format((int) $filter['count']) }}</td>
                    <td class="num">{{ $fmt((int) $filter['total_cents']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @foreach($filters as $filter)
        @if($monthly && ($filter['months'] ?? []) !== [])
            <h2 class="section-heading">{{ $filter['alias'] }} · {{ $filter['match'] }}</h2>
            <table class="sheet cols">
                <colgroup>
                    <col style="width: 60%">
                    <col style="width: 16%">
                    <col style="width: 24%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="num">Transactions</th>
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($filter['months'] as $month)
                        <tr>
                            <td>{{ $month['label'] }}</td>
                            <td class="num">{{ number_format((int) $month['count']) }}</td>
                            <td class="num">{{ $fmt((int) $month['total_cents']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td>Total</td>
                        <td class="num">{{ number_format((int) $filter['count']) }}</td>
                        <td class="num">{{ $fmt((int) $filter['total_cents']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if($detailed && ($filter['transactions'] ?? []) !== [])
            <h2 class="section-heading">{{ $filter['alias'] }} transactions</h2>
            <table class="sheet cols">
                <colgroup>
                    <col style="width: 14%">
                    <col style="width: 28%">
                    <col style="width: 18%">
                    <col style="width: 16%">
                    <col style="width: 24%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Account</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($filter['transactions'] as $line)
                        <tr>
                            <td>{{ $line['date'] }}</td>
                            <td>{{ $line['description'] }}</td>
                            <td>{{ $line['reference'] ?: '—' }}</td>
                            <td>{{ $line['account'] ?: '—' }}</td>
                            <td class="num">{{ $fmt((int) $line['signed_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endif

<p class="disclaimer">
    Each total is imported bank statement lines whose description or reference contains the filter, ignoring case.
    Debits increase the total. Credits, such as refunds, reduce it. Lines marked excluded in Banking are still included, because they remain on the statement.
    A line can match more than one filter. Amounts are in {{ $currency }}. This is a statement summary, not a ledger or tax report.
</p>
</body>
</html>
