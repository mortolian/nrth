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
    $fmtKm = static function (?float $km): string {
        if ($km === null) {
            return '—';
        }

        return number_format($km, 1, '.', ',').' km';
    };
@endphp
    <title>Vehicle Log Book — {{ $businessName }}</title>
    @include('pdf._styles')
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body { font-size: 9.5px; }
        .logbook-title {
            margin: 0;
            font-size: 20px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 800;
            color: #0f172a;
        }
        .logbook-subtitle {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 10px;
        }
        table.vehicle-card {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 10px;
        }
        table.vehicle-card td {
            vertical-align: top;
            padding: 10px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        table.vehicle-card .label {
            color: #64748b;
            font-size: 8px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        table.vehicle-card .value {
            color: #0f172a;
            font-weight: 700;
            font-size: 10.5px;
        }
        table.vehicle-card .muted-value {
            color: #334155;
            font-weight: 600;
            font-size: 10px;
        }
        .vehicle-heading {
            margin: 18px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #0f172a;
            font-size: 11px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 800;
            color: #0f172a;
            page-break-after: avoid;
        }
        .vehicle-block {
            page-break-inside: auto;
        }
        table.log-lines {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        table.log-lines th {
            background: #0f172a;
            color: #f8fafc;
            text-align: left;
            font-size: 8px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 7px 6px;
            font-weight: 600;
        }
        table.log-lines th.num,
        table.log-lines td.num {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        table.log-lines td {
            padding: 6px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 9px;
            color: #0f172a;
        }
        table.log-lines tr.zebra td { background: #f8fafc; }
        table.log-lines .purpose-business { font-weight: 700; color: #0369a1; }
        table.log-lines .purpose-private { font-weight: 700; color: #475569; }
        table.log-lines .route { color: #334155; }
        table.log-lines .notes { color: #64748b; font-size: 8.5px; }
        .section-totals {
            width: 42%;
            margin-left: auto;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 8px;
        }
        .section-totals td { padding: 3px 0; font-size: 9.5px; }
        .section-totals .label { color: #64748b; }
        .section-totals .value { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
        .section-totals .grand td {
            border-top: 1.5px solid #0f172a;
            padding-top: 6px;
            font-weight: 800;
            font-size: 11px;
        }
        .disclaimer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 8px;
            line-height: 1.45;
        }
        .empty-note {
            margin: 24px 0;
            padding: 16px;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>

<table class="brand">
    <tr>
        <td class="logo-cell">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="" style="max-width: 180px; max-height: 58px; object-fit: contain; margin-bottom: 6px;">
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
            <h1 class="logbook-title">Vehicle Log Book</h1>
            <p class="logbook-subtitle">Supporting travel record for accounting and tax purposes</p>
            <table class="doc-meta">
                <tr>
                    <td class="key">Period</td>
                    <td class="val strong">{{ $period }}</td>
                </tr>
                <tr>
                    <td class="key">Purpose</td>
                    <td class="val">{{ $purposeLabel }}</td>
                </tr>
                @if(!empty($searchLabel))
                    <tr>
                        <td class="key">Search</td>
                        <td class="val">{{ $searchLabel }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="key">Generated</td>
                    <td class="val">{{ $generatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="meta-strip">
    <tr>
        <td>
            <div class="key">Trips</div>
            <div class="val">{{ number_format($summary['trips']) }}</div>
        </td>
        <td>
            <div class="key">Vehicles</div>
            <div class="val">{{ number_format($summary['vehicles']) }}</div>
        </td>
        <td>
            <div class="key">Business distance</div>
            <div class="val">{{ $fmtKm($summary['business_km']) }}</div>
        </td>
        <td>
            <div class="key">Private distance</div>
            <div class="val">{{ $fmtKm($summary['private_km']) }}</div>
        </td>
        <td>
            <div class="key">Total distance</div>
            <div class="val">{{ $fmtKm($summary['total_km']) }}</div>
        </td>
    </tr>
</table>

@forelse($sections as $section)
    <div class="vehicle-block">
        <div class="vehicle-heading">
            {{ $section['vehicle']['name'] }}
            @if(!empty($section['vehicle']['registration_number']))
                — {{ $section['vehicle']['registration_number'] }}
            @endif
        </div>

        <table class="vehicle-card">
            <tr>
                <td style="width: 22%;">
                    <div class="label">Vehicle</div>
                    <div class="value">{{ $section['vehicle']['name'] }}</div>
                    <div class="muted-value">
                        @php
                            $desc = trim(implode(' ', array_filter([
                                $section['vehicle']['year'] ? (string) $section['vehicle']['year'] : null,
                                $section['vehicle']['make'],
                                $section['vehicle']['model'],
                            ])));
                        @endphp
                        {{ $desc !== '' ? $desc : '—' }}
                    </div>
                </td>
                <td style="width: 28%;">
                    <div class="label">Registration</div>
                    <div class="value">{{ $section['vehicle']['registration_number'] ?: '—' }}</div>
                </td>
                <td style="width: 30%;">
                    <div class="label">VIN</div>
                    <div class="value">{{ $section['vehicle']['vin'] ?: '—' }}</div>
                </td>
                <td style="width: 14%;">
                    <div class="label">Trips in period</div>
                    <div class="value">{{ number_format($section['totals']['trips']) }}</div>
                </td>
            </tr>
        </table>

        <table class="log-lines">
            <thead>
                <tr>
                    <th style="width: 10%;">Date</th>
                    <th style="width: 9%;">Purpose</th>
                    <th style="width: 26%;">From</th>
                    <th style="width: 26%;">To</th>
                    <th class="num" style="width: 11%;">Distance</th>
                    <th style="width: 18%;">Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($section['trips'] as $index => $trip)
                    <tr class="{{ $index % 2 === 1 ? 'zebra' : '' }}">
                        <td>{{ $trip['trip_date'] ?: '—' }}</td>
                        <td>
                            <span class="{{ $trip['purpose'] === 'business' ? 'purpose-business' : 'purpose-private' }}">
                                {{ ucfirst($trip['purpose']) }}
                            </span>
                        </td>
                        <td class="route">{{ $trip['from_location'] ?: '—' }}</td>
                        <td class="route">{{ $trip['to_location'] ?: '—' }}</td>
                        <td class="num">{{ $fmtKm($trip['distance_km']) }}</td>
                        <td class="notes">{{ $trip['notes'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="section-totals">
            <tr>
                <td class="label">Business distance</td>
                <td class="value">{{ $fmtKm($section['totals']['business_km']) }}</td>
            </tr>
            <tr>
                <td class="label">Private distance</td>
                <td class="value">{{ $fmtKm($section['totals']['private_km']) }}</td>
            </tr>
            <tr class="grand">
                <td class="label">Total distance</td>
                <td class="value">{{ $fmtKm($section['totals']['total_km']) }}</td>
            </tr>
        </table>
    </div>
@empty
    <div class="empty-note">
        No trips matched the selected filters for this log book report.
    </div>
@endforelse

<div class="disclaimer">
    This document was generated from the electronic vehicle log book maintained by {{ $businessName }}.
    It is intended as supporting documentation for accounting and tax purposes.
    Distances are as recorded in the log book.
    Generated on {{ $generatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}.
</div>

</body>
</html>
