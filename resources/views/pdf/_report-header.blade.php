@php
    $issuer = $issuer ?? ['name' => config('app.name'), 'address_lines' => []];
    $businessName = $issuer['name'] ?? config('app.name');
    $subtitle = trim((string) ($subtitle ?? ''));
    $note = trim((string) ($note ?? ''));
    $generated = isset($generatedAt)
        ? $generatedAt->timezone(config('app.timezone'))->format('Y-m-d H:i')
        : '';
    $letterhead = array_values(array_filter(array_map(
        static fn ($line) => trim((string) $line),
        is_array($issuer['address_lines'] ?? null) ? $issuer['address_lines'] : [],
    )));
    if (! empty($issuer['registration_number'])) {
        $letterhead[] = 'Reg '.$issuer['registration_number'];
    }
    if (! empty($issuer['vat_number'])) {
        $letterhead[] = 'VAT '.$issuer['vat_number'];
    }
    if (! empty($issuer['email'])) {
        $letterhead[] = (string) $issuer['email'];
    }
    if (! empty($issuer['phone'])) {
        $letterhead[] = (string) $issuer['phone'];
    }
    $rows = [];
    foreach ($meta ?? [] as $row) {
        if (! is_array($row)) {
            continue;
        }
        $label = trim((string) ($row[0] ?? ''));
        $value = trim((string) ($row[1] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $rows[] = [$label, $value];
    }
    if ($generated !== '') {
        $rows[] = ['Generated', $generated];
    }
@endphp
<table class="report-head">
    <tr>
        <td class="report-id">
            @if(!empty($logoSrc))
                <img class="report-logo" src="{{ $logoSrc }}" alt="">
            @endif
            <div class="report-business">{{ $businessName }}</div>
            @foreach($letterhead as $line)
                <div class="report-letterhead">{{ $line }}</div>
            @endforeach
        </td>
        <td class="report-head-meta">
            <div class="report-title">{{ $title }}</div>
            @if($subtitle !== '')
                <div class="report-subtitle">{{ $subtitle }}</div>
            @endif
            @if($rows !== [])
                <table class="doc-meta">
                    @foreach($rows as [$label, $value])
                        <tr>
                            <td class="key">{{ $label }}</td>
                            <td class="val">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td>
    </tr>
</table>
@if($note !== '')
    <p class="report-note">{{ $note }}</p>
@endif
