<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ ($is_tax_invoice ?? false) ? 'Tax invoice' : 'Invoice' }} {{ $invoice->number }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    <p>Hello {{ $invoice->client?->name ?? 'there' }},</p>

    <p>Please find attached your <strong>@if($is_tax_invoice ?? false)tax invoice@else invoice@endif {{ $invoice->number }}</strong>.</p>

    <p>
        <strong>Issue date:</strong> {{ optional($invoice->issue_date)->format('d M Y') }}<br>
        <strong>Due date:</strong> {{ optional($invoice->due_date)->format('d M Y') }}<br>
        <strong>Total due:</strong> {{ \App\Support\FormatMoney::minorUnits((int) $invoice->getRawOriginal('total_cents'), (string) ($invoice->currency ?? 'ZAR')) }}
    </p>

    <p>
        Please use your invoice number as payment reference when making payment.
        If you need any help, reply to this email.
    </p>

    <p>Kind regards,<br>{{ $issuer_name }}</p>
</body>
</html>
