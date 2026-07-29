@component('mail::message')
Hi {{ $client_name }},

Thank you — we have received your payment for invoice **{{ $invoice->number }}**.

@component('mail::panel')
**Receipt for:** {{ $invoice->number }}  
**Payment date:** {{ $payment_date }}  
**Amount received:** {{ $amount_received }}  
**Invoice total:** {{ $invoice_total }}  
**Outstanding:** {{ $outstanding }}
@endcomponent

@if ($has_attachment)
A PDF copy of this payment receipt is attached to this email.
@else
Please contact us if you need a PDF copy of this receipt.
@endif

Thanks,<br>
{{ $issuer_name }}
@endcomponent
