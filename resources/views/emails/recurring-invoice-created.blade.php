@component('mail::message')
# Invoice added

A recurring invoice was created for **{{ $team_name }}**.

@component('mail::panel')
**Invoice:** {{ $number }}  
**Client:** {{ $client_name }}  
**Issued:** {{ $issue_date }}  
**Due:** {{ $due_date }}  
**Total:** {{ $total }}  
**Status:** {{ $status }}
@endcomponent

{{ $status_note }}

@component('mail::button', ['url' => $invoice_url])
View invoice
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
