<?php

namespace App\Mail;

use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BusinessOwnerAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Team $team,
        public User $user,
        public bool $newAccount,
    ) {}

    public function build(): self
    {
        return $this->markdown('emails.business-owner-assigned', [
            'team' => $this->team,
            'user' => $this->user,
            'newAccount' => $this->newAccount,
            'signInUrl' => route('login'),
        ])->subject(__('You own :business', [
            'business' => $this->team->name,
        ]));
    }
}
