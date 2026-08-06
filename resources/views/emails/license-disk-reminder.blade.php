@component('mail::message')
# Licence disc reminder

The licence disc for **{{ $vehicle_name }}** expires on **{{ $expires_on }}**.

@component('mail::panel')
**Vehicle:** {{ $vehicle_name }}  
**Registration:** {{ $registration }}  
**Expires:** {{ $expires_on }}  
**Business:** {{ $team_name }}
@endcomponent

Renew the disc and update the expiry date in nrth so you get reminded again next year.

@component('mail::button', ['url' => $vehicle_url])
View vehicle
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
