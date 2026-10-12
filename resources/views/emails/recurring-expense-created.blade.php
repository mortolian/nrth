@component('mail::message')
# Expense added

A recurring expense was recorded for **{{ $team_name }}**.

@component('mail::panel')
**Description:** {{ $label }}  
**Supplier:** {{ $supplier }}  
**Date:** {{ $expense_date }}  
**Total:** {{ $total }}
@endcomponent

@component('mail::button', ['url' => $expense_url])
View expense
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
