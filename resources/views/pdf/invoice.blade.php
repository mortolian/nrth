<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
@php
    $public_pay_url = $public_pay_url ?? null;
    $public_pay_qr_data_uri = $public_pay_qr_data_uri ?? null;

    $team = $invoice->team;
    $client = $invoice->client;
    $banksForInvoice = $team ? $team->bankAccountsForInvoicePdf() : [];
    $hasBankDetails = count($banksForInvoice) > 0;

    $issuer = $team ? $team->issuerForInvoicingDocuments() : [
        'name' => config('app.name'),
        'address' => null,
        'address_lines' => [],
        'email' => null,
        'phone' => null,
        'website' => null,
        'registration_number' => null,
        'vat_number' => null,
    ];
    $businessName = $issuer['name'];
    $companyVat = $issuer['vat_number'];
    $companyReg = $issuer['registration_number'];
    $companyEmail = $issuer['email'];
    $companyPhone = $issuer['phone'];
    $companyWebsite = $issuer['website'];
    $physical = $issuer['address'] ?? '';
    $physicalLines = $issuer['address_lines'] ?? (
        is_string($physical) && $physical !== ''
            ? preg_split("/\r\n|\n|\r/", $physical) ?: []
            : []
    );
    $physicalLines = array_values(array_filter(array_map(
        static fn ($line) => trim((string) $line),
        is_array($physicalLines) ? $physicalLines : [],
    )));

    $clientAddress = is_array($client?->address)
        ? trim(collect([
            $client->address['street'] ?? null,
            $client->address['city'] ?? null,
            $client->address['province'] ?? null,
            $client->address['postal_code'] ?? null,
            $client->address['country'] ?? null,
        ])->filter()->implode(', '))
        : '';

    $logoSrc = $team?->logoDataUriForPdf();

    $subtotal = (int) ($invoice->getRawOriginal('subtotal_cents') ?? 0);
    $vatTotal = (int) ($invoice->getRawOriginal('vat_amount_cents') ?? 0);
    $total = (int) ($invoice->getRawOriginal('total_cents') ?? 0);
    $paid = (int) ($invoice->getRawOriginal('amount_paid_cents') ?? 0);
    $due = max(0, $total - $paid);
    $discountTotal = (int) ($invoice->getRawOriginal('discount_total_cents') ?? 0);
    $markdown = app(\App\Domain\Invoicing\Services\InvoiceMarkdownRenderer::class);

    $chargesVat = $team && method_exists($team, 'chargesVat') ? $team->chargesVat() : false;
    $documentTitle = $chargesVat ? 'Tax Invoice' : 'Invoice';

    $fmtMoney = static fn (int $cents): string => \App\Support\FormatMoney::minorUnits($cents, (string) ($invoice->currency ?? 'ZAR'));
@endphp
    <title>{{ $documentTitle }} {{ $invoice->number }}</title>
    @include('pdf._styles')
</head>
<body>

<table class="brand">
    <tr>
        <td class="logo-cell">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="" style="max-width: 200px; max-height: 70px; object-fit: contain; margin-bottom: 6px;">
            @endif
            <div class="company-name">{{ $businessName }}</div>
            @foreach($physicalLines as $line)
                <div class="company-line">{{ $line }}</div>
            @endforeach
            @if($companyEmail)<div class="company-line">{{ $companyEmail }}</div>@endif
            @if($companyPhone)<div class="company-line">{{ $companyPhone }}</div>@endif
            @if($companyWebsite)<div class="company-line">{{ $companyWebsite }}</div>@endif
            <div class="company-line small">
                @if($companyReg)Reg: {{ $companyReg }}@endif
                @if($companyVat) &middot; VAT: {{ $companyVat }}@endif
            </div>
        </td>
        <td class="doc-cell">
            <h1>{{ $documentTitle }}</h1>
                <table class="doc-meta">
                    <tr>
                        <td class="key">Invoice #:</td>
                        <td class="val strong">{{ $invoice->number }}</td>
                    </tr>
                    @if($invoice->reference)
                        <tr>
                            <td class="key">Reference:</td>
                            <td class="val">{{ $invoice->reference }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="key">Issued:</td>
                        <td class="val">{{ optional($invoice->issue_date)->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="key">Due:</td>
                        <td class="val">{{ optional($invoice->due_date)->format('d M Y') }}</td>
                    </tr>
                </table>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="label">Billed to</div>
            <div class="name">{{ $client?->name ?? 'Client' }}</div>
            @if($client?->contact_name)<p>{{ $client->contact_name }}</p>@endif
            @if($clientAddress)<p>{{ $clientAddress }}</p>@endif
            @if($client?->email)<p>{{ $client->email }}</p>@endif
            @if($client?->phone)<p>{{ $client->phone }}</p>@endif
            @if($client?->vat_number)<p class="small muted">VAT: {{ $client->vat_number }}</p>@endif
            @if($client?->registration_number)<p class="small muted">Reg: {{ $client->registration_number }}</p>@endif
        </td>
        <td class="spacer"></td>
        <td>
            <div class="label">Invoice total</div>
            <div class="name accent" style="font-size: 22px;">{{ $fmtMoney($total) }}</div>
            @if($paid > 0)
                <p class="small muted">Paid to date: {{ $fmtMoney($paid) }}</p>
                <p class="small muted">Outstanding: {{ $fmtMoney($due) }}</p>
            @else
                <p class="small muted">Amount due: {{ $fmtMoney($due) }}</p>
            @endif
            <p class="small muted pad-top-12">Please use <span class="b">{{ $invoice->number }}</span> as your payment reference.</p>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: {{ $chargesVat ? '48%' : '58%' }};">Description</th>
            <th class="num" style="width: 8%;">Qty</th>
            <th class="num" style="width: {{ $chargesVat ? '14%' : '22%' }};">Unit</th>
            @if($chargesVat)
                <th class="num" style="width: 10%;">VAT %</th>
                <th class="num" style="width: 10%;">VAT</th>
            @endif
            <th class="num" style="width: {{ $chargesVat ? '14%' : '12%' }};">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->lineItems as $i => $line)
            @php
                $unit = (int) $line->unit_price_cents;
                $qty = (float) $line->quantity;
                $rate = (float) $line->vat_rate;
                $lineVat = (int) $line->vat_amount_cents;
                $lineTotal = (int) $line->total_cents;
                $lineDiscount = (int) ($line->discount_amount_cents ?? 0);
            @endphp
            <tr @if($i % 2 === 1) class="zebra" @endif>
                <td>
                    {{ $line->description }}
                    @if($lineDiscount > 0)
                        <div class="small muted">Discount: {{ $fmtMoney($lineDiscount) }}</div>
                    @endif
                </td>
                <td class="num">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="num">{{ $fmtMoney($unit) }}</td>
                @if($chargesVat)
                    <td class="num">{{ number_format($rate * 100, 0) }}%</td>
                    <td class="num">{{ $fmtMoney($lineVat) }}</td>
                @endif
                <td class="num b">{{ $fmtMoney($lineTotal) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label">{{ $chargesVat ? 'Subtotal (excl. VAT)' : 'Subtotal' }}</td>
        <td class="value">{{ $fmtMoney($subtotal) }}</td>
    </tr>
    @if($discountTotal > 0)
    <tr>
        <td class="label">Discounts applied</td>
        <td class="value">{{ $fmtMoney($discountTotal) }}</td>
    </tr>
    @endif
    @if($chargesVat)
    <tr>
        <td class="label">VAT</td>
        <td class="value">{{ $fmtMoney($vatTotal) }}</td>
    </tr>
    @endif
    <tr class="grand">
        <td class="label">{{ $chargesVat ? 'Total (incl. VAT)' : 'Total' }}</td>
        <td class="value">{{ $fmtMoney($total) }}</td>
    </tr>
    @if($paid > 0)
    <tr>
        <td class="label">Amount paid</td>
        <td class="value">{{ $fmtMoney($paid) }}</td>
    </tr>
    <tr>
        <td class="label">Outstanding</td>
        <td class="value">{{ $fmtMoney($due) }}</td>
    </tr>
    @endif
</table>

@if($hasBankDetails)
    <div class="section section-banking">
        <h3>Payment details</h3>
        <table class="bank-grid">
            <tbody>
                @foreach(collect($banksForInvoice)->chunk(2) as $bankPair)
                    <tr>
                        @foreach($bankPair as $bank)
                            @php
                                $hasBankCells = !empty($bank['name'])
                                    || !empty($bank['holder'])
                                    || !empty($bank['account'])
                                    || !empty($bank['swift_code'])
                                    || !empty($bank['bic'])
                                    || !empty($bank['iban'])
                                    || !empty($bank['routing_sort_code'])
                                    || !empty($bank['branch'])
                                    || !empty($bank['type']);
                            @endphp
                            <td class="bank-grid-cell {{ $loop->first ? 'bank-grid-cell-left' : 'bank-grid-cell-right' }}">
                                <div class="bank-card">
                                    @if(!empty($bank['title']))
                                        <div class="bank-card-title">{{ $bank['title'] }}</div>
                                    @endif
                                    @if($hasBankCells)
                                        <table class="bank-kv">
                                            @if(!empty($bank['name']))
                                                <tr>
                                                    <td class="bank-k">Bank</td>
                                                    <td class="bank-v">{{ $bank['name'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['holder']))
                                                <tr>
                                                    <td class="bank-k">Account holder</td>
                                                    <td class="bank-v">{{ $bank['holder'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['account']))
                                                <tr>
                                                    <td class="bank-k">Account no.</td>
                                                    <td class="bank-v">{{ $bank['account'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['swift_code']))
                                                <tr>
                                                    <td class="bank-k">SWIFT</td>
                                                    <td class="bank-v">{{ $bank['swift_code'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['bic']))
                                                <tr>
                                                    <td class="bank-k">BIC</td>
                                                    <td class="bank-v">{{ $bank['bic'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['iban']))
                                                <tr>
                                                    <td class="bank-k">IBAN</td>
                                                    <td class="bank-v">{{ $bank['iban'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['routing_sort_code']))
                                                <tr>
                                                    <td class="bank-k">Routing / sort</td>
                                                    <td class="bank-v">{{ $bank['routing_sort_code'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['branch']))
                                                <tr>
                                                    <td class="bank-k">Branch code</td>
                                                    <td class="bank-v">{{ $bank['branch'] }}</td>
                                                </tr>
                                            @endif
                                            @if(!empty($bank['type']))
                                                <tr>
                                                    <td class="bank-k">Account type</td>
                                                    <td class="bank-v">{{ ucfirst((string) $bank['type']) }}</td>
                                                </tr>
                                            @endif
                                        </table>
                                    @endif
                                </div>
                            </td>
                        @endforeach
                        @if($bankPair->count() === 1)
                            <td class="bank-grid-cell bank-grid-cell-right"></td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@include('pdf._markdown-section', ['title' => 'Notes', 'html' => $markdown->toHtml($invoice->notes)])
@include('pdf._markdown-section', ['title' => 'Terms & conditions', 'html' => $markdown->toHtml($invoice->footer)])

@if($public_pay_qr_data_uri && $public_pay_url)
    <div class="section section-pay-online pay-online-qr">
        <h3>Pay online</h3>
        <p class="small muted" style="margin: 0 0 8px;">Scan the QR code to open the payment page (card or bank transfer where enabled).</p>
        <img src="{{ $public_pay_qr_data_uri }}" alt="" width="168" height="168" style="display: block; width: 168px; height: 168px;">
        <p class="small muted" style="margin-top: 8px; word-break: break-all;">{{ $public_pay_url }}</p>
    </div>
@endif

<div class="footer">
    {{ $businessName }} &middot; {{ $documentTitle }} {{ $invoice->number }} &middot; Generated {{ now()->format('d M Y') }}
</div>
</body>
</html>
