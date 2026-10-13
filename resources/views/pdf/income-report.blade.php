<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
@php
    $issuer = $issuer ?? [
        'name' => config('app.name'),
        'address_lines' => [],
        'email' => null,
        'phone' => null,
        'website' => null,
        'registration_number' => null,
        'vat_number' => null,
    ];
    $businessName = $issuer['name'] ?? config('app.name');
    $physicalLines = array_values(array_filter(array_map(
        static fn ($line) => trim((string) $line),
        is_array($issuer['address_lines'] ?? null) ? $issuer['address_lines'] : [],
    )));
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $fy = $financial_year ?? ['label' => '', 'from' => '', 'to' => ''];
    $accounts = $report['accounts'] ?? [];
    $totalIncome = (int) ($report['totals']['income'] ?? 0);
    $transactions = $transactions ?? [];
    $months = $months ?? [];
    $presentation = $presentation ?? ['view' => 'detailed', 'breakdown' => 'year'];
    $view = ($presentation['view'] ?? 'detailed') === 'summary' ? 'summary' : 'detailed';
    $breakdown = ($presentation['breakdown'] ?? 'year') === 'monthly' ? 'monthly' : 'year';
    $transactionCount = (int) ($transaction_count ?? count($transactions));
    $period = trim(($fy['from'] ?? '').' – '.($fy['to'] ?? ''), ' –');
@endphp
    <title>Income report — {{ $businessName }} — FY {{ $fy['label'] ?? '' }}</title>
    @include('pdf._styles')
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body { font-size: 9.5px; }
        .report-title {
            margin: 0;
            font-size: 22px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 800;
            color: #0f172a;
        }
        .report-subtitle {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 10px;
        }
        table.summary {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin: 4px 0 16px;
        }
        table.summary td {
            width: 33.33%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 12px;
            vertical-align: top;
        }
        table.summary .label {
            color: #64748b;
            font-size: 8px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        table.summary .value {
            color: #0f172a;
            font-size: 16px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        table.summary .hint {
            margin-top: 2px;
            color: #64748b;
            font-size: 8.5px;
        }
        .section-heading {
            margin: 16px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #0f172a;
            font-size: 11px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 800;
            color: #0f172a;
            page-break-after: avoid;
        }
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        table.sheet th {
            background: #0f172a;
            color: #f8fafc;
            text-align: left;
            font-size: 8px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 7px 6px;
            font-weight: 600;
        }
        table.sheet th.num,
        table.sheet td.num {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        table.sheet td {
            padding: 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 9px;
            color: #0f172a;
        }
        table.sheet tr.zebra td { background: #f8fafc; }
        table.sheet tr.total td {
            border-top: 1.5px solid #0f172a;
            border-bottom: none;
            font-weight: 800;
            padding-top: 8px;
        }
        .txn {
            margin: 0 0 12px;
            page-break-inside: avoid;
            border: 1px solid #e2e8f0;
        }
        table.txn-head {
            width: 100%;
            border-collapse: collapse;
            background: #f8fafc;
        }
        table.txn-head td {
            padding: 8px 10px;
            vertical-align: top;
        }
        .txn-date {
            font-weight: 800;
            font-size: 11px;
            color: #0f172a;
        }
        .txn-meta {
            color: #475569;
            font-size: 9px;
            margin-top: 2px;
        }
        .txn-amount {
            text-align: right;
            font-weight: 800;
            font-size: 12px;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
        }
        .txn-amount.neg { color: #b91c1c; }
        .txn-kicker {
            color: #64748b;
            font-size: 8px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .txn-body { padding: 8px 10px 10px; }
        .txn-desc {
            margin: 0 0 8px;
            color: #334155;
            font-size: 9.5px;
        }
        table.lines { margin-bottom: 0; }
        table.lines td.income { font-weight: 700; }
        .muted { color: #64748b; }
        .empty-note {
            margin: 8px 0 16px;
            padding: 16px;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            text-align: center;
        }
        .disclaimer {
            margin-top: 18px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 8px;
            line-height: 1.45;
        }
    </style>
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
            @if(!empty($issuer['registration_number']))
                <div class="company-line">Reg: {{ $issuer['registration_number'] }}</div>
            @endif
            @if(!empty($issuer['vat_number']))
                <div class="company-line">VAT: {{ $issuer['vat_number'] }}</div>
            @endif
            @if(!empty($issuer['email']))
                <div class="company-line">{{ $issuer['email'] }}</div>
            @endif
            @if(!empty($issuer['phone']))
                <div class="company-line">{{ $issuer['phone'] }}</div>
            @endif
        </td>
        <td class="doc-cell">
            <h1 class="report-title">Income report</h1>
            <p class="report-subtitle">Financial year statement of recognised income</p>
            <table class="doc-meta">
                <tr>
                    <td class="key">Financial year</td>
                    <td class="val strong">{{ $fy['label'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="key">Period</td>
                    <td class="val">{{ $period !== '' ? $period : '—' }}</td>
                </tr>
                <tr>
                    <td class="key">Report</td>
                    <td class="val">{{ $view === 'summary' ? 'Summary' : 'Detailed' }}</td>
                </tr>
                <tr>
                    <td class="key">Breakdown</td>
                    <td class="val">{{ $breakdown === 'monthly' ? 'Monthly' : 'Full year' }}</td>
                </tr>
                <tr>
                    <td class="key">Currency</td>
                    <td class="val">{{ $currency }}</td>
                </tr>
                <tr>
                    <td class="key">Generated</td>
                    <td class="val">{{ $generatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="summary">
    <tr>
        <td>
            <div class="label">Total income</div>
            <div class="value">{{ $fmt($totalIncome) }}</div>
            <div class="hint">Net credits to income accounts</div>
        </td>
        <td>
            <div class="label">Transactions</div>
            <div class="value">{{ number_format($transactionCount) }}</div>
            <div class="hint">Posted entries that touch income</div>
        </td>
        <td>
            <div class="label">Income accounts</div>
            <div class="value">{{ number_format(count($accounts)) }}</div>
            <div class="hint">Accounts with a non-zero balance</div>
        </td>
    </tr>
</table>

@if($breakdown !== 'monthly')
<div class="section-heading">Income by account</div>
@if($accounts === [])
    <div class="empty-note">No income was recognised in this financial year.</div>
@else
    <table class="sheet">
        <thead>
            <tr>
                <th style="width: 18%">Code</th>
                <th>Account</th>
                <th class="num" style="width: 22%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($accounts as $index => $account)
                <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                    <td>{{ $account['code'] }}</td>
                    <td>{{ $account['name'] }}</td>
                    <td class="num">{{ $fmt((int) $account['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total income</td>
                <td class="num">{{ $fmt($totalIncome) }}</td>
            </tr>
        </tbody>
    </table>
@endif
@endif

@if($breakdown === 'monthly')
<div class="section-heading">Monthly breakdown</div>
@foreach($months as $month)
    <div class="section-heading" style="margin-top: 12px; font-size: 10px; border-bottom-width: 1px;">{{ $month['label'] }}</div>
    @if(($month['accounts'] ?? []) === [])
        <div class="empty-note">No income in {{ $month['label'] }}.</div>
    @else
        <table class="sheet">
            <thead>
                <tr>
                    <th style="width: 18%">Code</th>
                    <th>Account</th>
                    <th class="num" style="width: 22%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($month['accounts'] as $index => $account)
                    <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                        <td>{{ $account['code'] }}</td>
                        <td>{{ $account['name'] }}</td>
                        <td class="num">{{ $fmt((int) $account['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">{{ $month['label'] }}</td>
                    <td class="num">{{ $fmt((int) $month['total']) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
@endforeach
@endif

@if($view === 'detailed')
<div class="section-heading">Transaction register</div>
@if($transactions === [])
    <div class="empty-note">No income transactions were posted in this financial year.</div>
@else
    @foreach($transactions as $transaction)
        @php
            $income = (int) ($transaction['income_cents'] ?? 0);
            $meta = array_filter([
                $transaction['type_label'] ?? null,
                $transaction['reference'] ? 'Ref '.$transaction['reference'] : null,
                $transaction['counterparty'] ?? null,
                isset($transaction['invoice']['number']) ? 'Invoice '.$transaction['invoice']['number'] : null,
            ]);
        @endphp
        <div class="txn">
            <table class="txn-head">
                <tr>
                    <td>
                        <div class="txn-date">{{ $transaction['date'] ?? '—' }}</div>
                        <div class="txn-meta">{{ $meta !== [] ? implode(' · ', $meta) : 'Posted income' }}</div>
                    </td>
                    <td style="width: 28%">
                        <div class="txn-kicker">Income</div>
                        <div class="txn-amount {{ $income < 0 ? 'neg' : '' }}">{{ $fmt($income) }}</div>
                    </td>
                </tr>
            </table>
            <div class="txn-body">
                @if(!empty($transaction['description']))
                    <p class="txn-desc">{{ $transaction['description'] }}</p>
                @endif
                <table class="sheet lines">
                    <thead>
                        <tr>
                            <th style="width: 14%">Account</th>
                            <th style="width: 22%">Name</th>
                            <th>Detail</th>
                            <th class="num" style="width: 14%">Debit</th>
                            <th class="num" style="width: 14%">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transaction['lines'] as $line)
                            @php
                                $isDebit = ($line['entry_type'] ?? '') === 'debit';
                                $amount = (int) ($line['amount_cents'] ?? 0);
                            @endphp
                            <tr>
                                <td class="{{ !empty($line['is_income']) ? 'income' : '' }}">{{ $line['account_code'] ?: '—' }}</td>
                                <td class="{{ !empty($line['is_income']) ? 'income' : '' }}">{{ $line['account_name'] ?: '—' }}</td>
                                <td class="muted">{{ $line['description'] ?: '—' }}</td>
                                <td class="num">{{ $isDebit ? $fmt($amount) : '' }}</td>
                                <td class="num">{{ $isDebit ? '' : $fmt($amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(!empty($transaction['tax_lines']))
                    <table class="sheet lines" style="margin-top: 8px;">
                        <thead>
                            <tr>
                                <th>Tax</th>
                                <th>Rate</th>
                                <th class="num">Taxable</th>
                                <th class="num">Tax</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transaction['tax_lines'] as $tax)
                                <tr>
                                    <td>{{ $tax['type_label'] ?? 'Tax' }}</td>
                                    <td>
                                        {{ $tax['rate_name'] ?? 'Tax' }}
                                        @if(!empty($tax['rate_percent']))
                                            <span class="muted">({{ rtrim(rtrim(number_format((float) $tax['rate_percent'], 2, '.', ''), '0'), '.') }}%)</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ $fmt((int) ($tax['taxable_cents'] ?? 0)) }}</td>
                                    <td class="num">{{ $fmt((int) ($tax['tax_cents'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if(!empty($transaction['payment']) || !empty($transaction['recorded_by']))
                    <p class="txn-meta" style="margin-top: 8px;">
                        @if(!empty($transaction['payment']['method_label']))
                            Payment {{ $transaction['payment']['method_label'] }}
                            @if(!empty($transaction['payment']['date']))
                                on {{ $transaction['payment']['date'] }}
                            @endif
                            @if(!empty($transaction['payment']['reference']))
                                · {{ $transaction['payment']['reference'] }}
                            @endif
                            @if(!empty($transaction['payment']['notes']))
                                · {{ $transaction['payment']['notes'] }}
                            @endif
                        @endif
                        @if(!empty($transaction['recorded_by']))
                            @if(!empty($transaction['payment']['method_label']))
                                ·
                            @endif
                            Recorded by {{ $transaction['recorded_by'] }}
                        @endif
                    </p>
                @endif
            </div>
        </div>
    @endforeach
@endif
@endif

<p class="disclaimer">
    Income is the net of posted credits and debits on income accounts during the financial year.
    Drafts, voided entries, and void reversals are excluded. Cash collected against an invoice that was already accrued is not counted again.
    Amounts are in {{ $currency }}. This report is a bookkeeping summary, not tax advice.
</p>

</body>
</html>
