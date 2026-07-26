@component('mail::message')
# {{ __('Join :team', ['team' => $invitation->team->name]) }}

{{ __('You’ve been invited to collaborate on :team in :app.', ['team' => $invitation->team->name, 'app' => config('app.name')]) }}

{{ __('Click the button below. If you’re new, you’ll create a password and join immediately — no separate business setup.') }}

@component('mail::button', ['url' => $joinUrl])
{{ __('Join :team', ['team' => $invitation->team->name]) }}
@endcomponent

{{ __('This link is for :email only. If you did not expect this invitation, you can ignore this email.', ['email' => $invitation->email]) }}
@endcomponent
