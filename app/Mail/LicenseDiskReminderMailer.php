<?php

namespace App\Mail;

use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseDiskReminderMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Vehicle $vehicle,
    ) {}

    public function envelope(): Envelope
    {
        $this->vehicle->loadMissing('team');
        $label = $this->vehicleLabel();

        return new Envelope(
            subject: 'Licence disc renews soon: '.$label,
        );
    }

    public function content(): Content
    {
        $this->vehicle->loadMissing('team');
        $expiresOn = optional($this->vehicle->license_disk_expires_on)->format('d M Y') ?? '—';

        return new Content(
            markdown: 'emails.license-disk-reminder',
            with: [
                'vehicle_name' => $this->vehicleLabel(),
                'registration' => $this->vehicle->registration_number ?: '—',
                'expires_on' => $expiresOn,
                'team_name' => $this->vehicle->team?->name ?? (string) config('app.name'),
                'vehicle_url' => route('vehicles.show', $this->vehicle),
            ],
        );
    }

    private function vehicleLabel(): string
    {
        $name = trim((string) $this->vehicle->name);
        $registration = trim((string) ($this->vehicle->registration_number ?? ''));

        if ($name !== '' && $registration !== '') {
            return $name.' ('.$registration.')';
        }

        return $name !== '' ? $name : ($registration !== '' ? $registration : 'Vehicle #'.$this->vehicle->id);
    }
}
