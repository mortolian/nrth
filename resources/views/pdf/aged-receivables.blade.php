@php
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $clients = $report['clients'] ?? [];
    $totals = $report['totals'] ?? [];
    $asOf = (string) ($report['as_of'] ?? '');
    $invoiceCount = (int) ($report['invoice_count'] ?? 0);
    $bucketLabel = [
        'current' => 'Current',
        'days_1_30' => '1–30',
        'days_31_60' => '31–60',
        'days_61_90' => '61–90',
        'over_90' => '90+',
    ];
    $meta = [
        ['As of', $asOf],
        ['Currency', $currency],
        ['Open invoices', number_format($invoiceCount)],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Aged receivables — {{ $businessName }}</title>
    @include('pdf._report-styles')
</head>
<body>
@include('pdf._report-header', [
    'title' => 'Aged receivables',
    'subtitle' => 'Who owes the business, by due date',
    'meta' => $meta,
])

@if(count($clients) === 0)
    <div class="empty-note">No open invoices at this date.</div>
@else
    <h2 class="section-heading">By client</h2>
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
            @foreach($clients as $client)
                <tr class="group">
                    <td colspan="6">{{ $client['name'] }}</td>
                </tr>
                @foreach($client['invoices'] as $index => $invoice)
                    <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                        <td>{{ $invoice['number'] }}</td>
                        <td>{{ $invoice['issue_date'] }}</td>
                        <td>{{ $invoice['due_date'] ?: '—' }}</td>
                        <td>{{ $bucketLabel[$invoice['bucket']] ?? $invoice['bucket'] }}</td>
                        <td class="num">{{ (int) $invoice['days_overdue'] }}</td>
                        <td class="num">{{ $fmt((int) $invoice['amount_cents']) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

<p class="disclaimer">
    Outstanding amounts are issued invoices less payments dated on or before {{ $asOf }}, in {{ $currency }}.
    Drafts and void invoices are excluded. Payments after this date are not deducted. This is a management report, not a tax return.
</p>
</body>
</html>
