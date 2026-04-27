<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote {{ $quote->number }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    <p>Hello {{ $quote->client?->name ?? 'there' }},</p>

    <p>Please find attached quote <strong>{{ $quote->number }}</strong>.</p>

    <p>
        <strong>Issue date:</strong> {{ optional($quote->issue_date)->format('d M Y') }}<br>
        <strong>Valid until:</strong> {{ optional($quote->expiry_date)->format('d M Y') }}<br>
        <strong>Total:</strong> R {{ number_format(((int) $quote->getRawOriginal('total_cents')) / 100, 2) }}
    </p>

    @if($quote->notes)
        <p><strong>Notes:</strong> {{ $quote->notes }}</p>
    @endif

    <p>Kind regards,<br>{{ $issuer_name }}</p>
</body>
</html>

