@component('mail::message')
# {{ __('You own :business', ['business' => $team->name]) }}

{{ __(':business has been created on :app, and you have been assigned as an owner.', [
    'business' => $team->name,
    'app' => config('app.name'),
]) }}

@if ($newAccount)
{{ __('Sign in with :email and the password that was set up for you. This notice does not include that password.', [
    'email' => $user->email,
]) }}
@else
{{ __('Sign in with :email. Your existing password still works.', [
    'email' => $user->email,
]) }}
@endif

{{ __('There is nothing to accept. This is a notice, not an invitation.') }}

@component('mail::button', ['url' => $signInUrl])
{{ __('Sign in') }}
@endcomponent

{{ __('If you did not expect this, contact the person who runs :app.', ['app' => config('app.name')]) }}
@endcomponent
