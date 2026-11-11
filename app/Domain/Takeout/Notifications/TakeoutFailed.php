<?php

namespace App\Domain\Takeout\Notifications;

use App\Domain\Takeout\Models\TakeoutRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TakeoutFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TakeoutRun $takeoutRun,
        public string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->takeoutRun->from_date->toDateString();
        $to = $this->takeoutRun->to_date->toDateString();

        return (new MailMessage)
            ->subject(__('Your data takeout failed'))
            ->markdown('emails.takeout-failed', [
                'fromDate' => $from,
                'toDate' => $to,
                'reason' => $this->reason,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'takeout_failed',
            'takeout_run_id' => $this->takeoutRun->id,
            'from_date' => $this->takeoutRun->from_date->toDateString(),
            'to_date' => $this->takeoutRun->to_date->toDateString(),
            'message' => sprintf(
                'Data takeout for %s to %s failed.',
                $this->takeoutRun->from_date->toDateString(),
                $this->takeoutRun->to_date->toDateString(),
            ),
            'error' => $this->reason,
        ];
    }
}
