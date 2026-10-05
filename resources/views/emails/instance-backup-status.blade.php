@component('mail::message')
@if ($successful)
# Backup ready

The latest backup of **{{ $applicationName }}** is ready.
@else
# Backup failed

The backup of **{{ $applicationName }}** did not finish.
@endif

@if ($finishedAt)
Finished at {{ $finishedAt }}.
@endif

@if ($filename)
**File:** {{ $filename }}
@endif

@if ($error)
@component('mail::panel')
{{ $error }}
@endcomponent
@endif

@component('mail::table')
| Destination | Result | Size |
| :---------- | :----- | ----: |
@foreach ($destinations as $destination)
| {{ $destination['label'] }} | {{ $destination['status'] }} | {{ $destination['size'] ?? '—' }} |
@endforeach
@endcomponent

@if ($warning)
@component('mail::panel')
{{ $warning }}
@endcomponent
@endif

Local is the copy used for restore. S3 and Path / NFS are extra copies of that same zip.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
