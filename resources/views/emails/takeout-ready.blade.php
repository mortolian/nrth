@component('mail::message')
# {{ __('Your data takeout is ready') }}

{{ __('The export for :from to :to is ready to download.', [
    'from' => $fromDate,
    'to' => $toDate,
]) }}

@if ($expiresAt)
{{ __('This download link expires :when.', ['when' => $expiresAt]) }}
@endif

@component('mail::button', ['url' => $downloadUrl])
{{ __('Download takeout') }}
@endcomponent

{{ __('You can also download it from Settings → Backups & exports.') }}
@endcomponent
