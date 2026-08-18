<?php

namespace App\Mail;

use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public const JOIN_LINK_TTL_DAYS = 7;

    public function __construct(public TeamInvitationModel $invitation) {}

    public function build(): self
    {
        $joinUrl = URL::temporarySignedRoute(
            'team-invitations.join',
            now()->addDays(self::JOIN_LINK_TTL_DAYS),
            ['invitation' => $this->invitation],
        );

        return $this->markdown('emails.team-invitation', [
            'acceptUrl' => $joinUrl,
            'joinUrl' => $joinUrl,
            'invitation' => $this->invitation,
        ])->subject(__('You are invited to join :team', [
            'team' => $this->invitation->team?->name ?? config('app.name'),
        ]));
    }
}
