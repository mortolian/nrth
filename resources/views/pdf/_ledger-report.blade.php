@php
    $isIncome = ($kind ?? 'income') === 'income';
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
    $currency = (string) ($currency ?? 'ZAR');
    $fmt = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, $currency);
    $fy = $financial_year ?? ['label' => '', 'from' => '', 'to' => ''];
    $totalKey = $isIncome ? 'income' : 'expenses';
    $amountKey = $isIncome ? 'income_cents' : 'expense_cents';
    $accountsKey = $isIncome ? 'income_accounts' : 'expense_accounts';
    $reportTitle = $isIncome ? 'Income report' : 'Expense report';
    $sectionLabel = $isIncome ? 'Income by account' : 'Expenses by account';
    $totalLabel = $isIncome ? 'Total income' : 'Total expenses';
    $emptyAccounts = $isIncome
        ? 'No income was recognised in this financial year.'
        : 'No expenses were recognised in this financial year.';
    $emptyTransactions = $isIncome
        ? 'No income transactions were posted in this financial year.'
        : 'No expense transactions were posted in this financial year.';
    $accounts = $report['accounts'] ?? [];
    $totalAmount = (int) ($report['totals'][$totalKey] ?? 0);
    $transactions = $transactions ?? [];
    $months = $months ?? [];
    $presentation = $presentation ?? ['view' => 'detailed', 'breakdown' => 'year'];
    $view = ($presentation['view'] ?? 'detailed') === 'summary' ? 'summary' : 'detailed';
    $breakdown = ($presentation['breakdown'] ?? 'year') === 'monthly' ? 'monthly' : 'year';
    $period = trim(($fy['from'] ?? '').' – '.($fy['to'] ?? ''), ' –');
    $subtitle = $isIncome
        ? 'Financial year statement of recognised income'
        : 'Financial year statement of recognised expenses';
    $meta = [
        ['Financial year', (string) ($fy['label'] ?? '')],
        ['Period', $period],
        ['Report', $view === 'summary' ? 'Summary' : 'Detailed'],
        ['Breakdown', $breakdown === 'monthly' ? 'Monthly' : 'Full year'],
        ['Currency', $currency],
    ];
    $activeMonths = array_values(array_filter(
        $months,
        static fn ($month): bool => is_array($month) && ($month['accounts'] ?? []) !== [],
    ));
    $rateLabel = static function (array $tax): string {
        $percent = $tax['rate_percent'] ?? null;
        if ($percent === null || $percent === '') {
            return (string) ($tax['rate_name'] ?? '');
        }

        return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.').'%';
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle }} — {{ $businessName }} — FY {{ $fy['label'] ?? '' }}</title>
    @include('pdf._report-styles')
</head>
<body>
@include('pdf._report-header', [
    'title' => $reportTitle,
    'subtitle' => $subtitle,
    'meta' => $meta,
])

@if($breakdown === 'monthly')
    <h2 class="section-heading">Monthly breakdown</h2>
    @if($activeMonths === [])
        <div class="empty-note">{{ $emptyAccounts }}</div>
    @else
        <table class="sheet">
            <thead>
                <tr>
                    <th style="width: 16%">Code</th>
                    <th>Account</th>
                    <th class="num" style="width: 22%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeMonths as $month)
                    <tr class="group">
                        <td colspan="3">{{ $month['label'] }}</td>
                    </tr>
                    @foreach($month['accounts'] as $index => $account)
                        <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                            <td>{{ $account['code'] }}</td>
                            <td>{{ $account['name'] }}</td>
                            <td class="num">{{ $fmt((int) $account['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="2">Total</td>
                        <td class="num">{{ $fmt((int) $month['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="grand">
                    <td colspan="2">{{ $totalLabel }}</td>
                    <td class="num">{{ $fmt($totalAmount) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
@else
    <h2 class="section-heading">{{ $sectionLabel }}</h2>
    @if($accounts === [])
        <div class="empty-note">{{ $emptyAccounts }}</div>
    @else
        <table class="sheet">
            <thead>
                <tr>
                    <th style="width: 16%">Code</th>
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
                    <td colspan="2">{{ $totalLabel }}</td>
                    <td class="num">{{ $fmt($totalAmount) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
@endif

@if($view === 'detailed')
    <h2 class="section-heading">Transaction register</h2>
    @if($transactions === [])
        <div class="empty-note">{{ $emptyTransactions }}</div>
    @else
        <table class="sheet">
            <thead>
                <tr>
                    <th style="width: 14%">Date</th>
                    <th>Details</th>
                    <th style="width: 24%">Account</th>
                    <th class="num" style="width: 16%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $index => $transaction)
                    @php
                        $amount = (int) ($transaction[$amountKey] ?? 0);
                        $primary = $transaction['description'] ?? null;
                        $invoiceNumber = $transaction['invoice']['number'] ?? null;
                        $reference = $transaction['reference'] ?? null;
                        $type = $transaction['type_label'] ?? null;
                        $bits = array_filter([
                            ($type && ! ($invoiceNumber && strcasecmp((string) $type, 'Invoice') === 0)) ? $type : null,
                            ($reference && $reference !== $invoiceNumber) ? 'Ref '.$reference : null,
                            $transaction['counterparty'] ?? null,
                            $invoiceNumber ? 'Invoice '.$invoiceNumber : null,
                            ! empty($transaction['paid_from']) ? 'Paid from '.$transaction['paid_from'] : null,
                            ! empty($transaction['notes']) ? $transaction['notes'] : null,
                            ! empty($transaction['receipt_not_required']) ? 'Receipt not required' : null,
                            ! empty($transaction['distance_km']) ? number_format((float) $transaction['distance_km'], 1, '.', '').' km' : null,
                            ! empty($transaction['office_percentage']) ? 'Office '.rtrim(rtrim(number_format((float) $transaction['office_percentage'], 2, '.', ''), '0'), '.').'%' : null,
                        ]);
                        $payment = $transaction['payment'] ?? null;
                        if (is_array($payment) && ! empty($payment['method_label'])) {
                            $pay = (string) $payment['method_label'];
                            if (! empty($payment['reference']) && $payment['reference'] !== $reference && $payment['reference'] !== $invoiceNumber) {
                                $pay .= ' '.$payment['reference'];
                            }
                            if (! empty($payment['date']) && $payment['date'] !== ($transaction['date'] ?? null)) {
                                $pay .= ' '.$payment['date'];
                            }
                            $bits[] = $pay;
                            if (! empty($payment['notes']) && $payment['notes'] !== $primary) {
                                $bits[] = $payment['notes'];
                            }
                        }
                        foreach ($transaction['tax_lines'] ?? [] as $tax) {
                            $bits[] = trim(implode(' ', array_filter([
                                $tax['type_label'] ?? 'Tax',
                                $rateLabel($tax),
                                $fmt((int) ($tax['tax_cents'] ?? 0)),
                            ])));
                        }
                        $accountLines = [];
                        $accountList = $transaction[$accountsKey] ?? [];
                        foreach ($accountList as $account) {
                            $label = trim(($account['code'] ?? '').' '.($account['name'] ?? ''));
                            if (count($accountList) > 1) {
                                $label = trim($label.' '.$fmt((int) ($account['amount'] ?? 0)));
                            }
                            $accountLines[] = $label !== '' ? $label : '—';
                        }
                        if ($accountLines === []) {
                            $accountLines = ['—'];
                        }
                    @endphp
                    <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                        <td>{{ $transaction['date'] ?? '—' }}</td>
                        <td class="details">
                            @if($primary)
                                <div>{{ $primary }}</div>
                            @endif
                            @if($bits !== [])
                                <div class="{{ $primary ? 'sub' : '' }}">{{ implode(' · ', $bits) }}</div>
                            @endif
                            @if(! $primary && $bits === [])
                                —
                            @endif
                        </td>
                        <td>
                            @foreach($accountLines as $accountLine)
                                <div>{{ $accountLine }}</div>
                            @endforeach
                        </td>
                        <td class="num {{ $amount < 0 ? 'neg' : '' }}">{{ $fmt($amount) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="3">{{ $totalLabel }}</td>
                    <td class="num">{{ $fmt($totalAmount) }}</td>
                </tr>
            </tbody>
        </table>
    @endif
@endif

<p class="disclaimer">
    @if($isIncome)
        Income is the net of posted credits and debits on income accounts during the financial year.
        Drafts, voided entries, and void reversals are excluded. Cash collected against an invoice that was already accrued is not counted again.
    @else
        Expenses are the net of posted debits and credits on expense accounts during the financial year.
        Drafts, voided entries, and void reversals are excluded.
    @endif
    Amounts are in {{ $currency }}. This report is a bookkeeping summary, not tax advice.
</p>
</body>
</html>
