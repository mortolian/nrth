@component('mail::message')
# Payment reminder

Hi {{ $client_name }},

This is a friendly reminder that **{{ $doc_label }} {{ $invoice->number }}** still has an outstanding balance.

@component('mail::panel')
**{{ ucfirst($doc_label) }}:** {{ $invoice->number }}  
**Issue date:** {{ $issue_date }}  
**Due date:** {{ $due_date }}  
**Amount due:** {{ $amount_due }}
@endcomponent

Please use **{{ $invoice->number }}** as your payment reference. If you have already paid, you can ignore this message.

@if ($has_attachment)
A PDF copy of this {{ $doc_label }} is attached to this email.
@endif

Thanks,<br>
{{ $issuer_name }}
@endcomponent
