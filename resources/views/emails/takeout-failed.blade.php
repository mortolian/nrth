@component('mail::message')
# {{ __('Your data takeout failed') }}

{{ __('The export for :from to :to did not finish.', [
    'from' => $fromDate,
    'to' => $toDate,
]) }}

@if ($reason)
@component('mail::panel')
{{ $reason }}
@endcomponent
@endif

{{ __('Open Settings → Backups & exports to try again.') }}
@endcomponent
