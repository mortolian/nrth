<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estimate {{ $estimate->number }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    <p>Hello {{ $estimate->client?->name ?? 'there' }},</p>

    <p>Please find attached estimate <strong>{{ $estimate->number }}</strong>.</p>

    <p>
        <strong>Issue date:</strong> {{ optional($estimate->issue_date)->format('d M Y') }}<br>
        <strong>Valid until:</strong> {{ optional($estimate->expiry_date)->format('d M Y') }}<br>
        <strong>Total:</strong> R {{ number_format(((int) $estimate->getRawOriginal('total_cents')) / 100, 2) }}
    </p>

    @if($estimate->notes)
        <p><strong>Notes:</strong> {{ $estimate->notes }}</p>
    @endif

    <p>Kind regards,<br>{{ $issuer_name }}</p>
</body>
</html>
