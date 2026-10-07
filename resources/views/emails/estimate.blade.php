@component('mail::message')
Hello {{ $estimate->client?->name ?? 'there' }},

Please find attached estimate **{{ $estimate->number }}**.

@component('mail::panel')
**Issue date:** {{ optional($estimate->issue_date)->format('d M Y') }}  
**Valid until:** {{ optional($estimate->expiry_date)->format('d M Y') }}  
**Total:** {{ $total }}
@endcomponent

@if ($estimate->notes)
**Notes:** {{ $estimate->notes }}
@endif

Kind regards,<br>
{{ $issuer_name }}
@endcomponent
