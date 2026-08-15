<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LicenseExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public Organization $organization,
        public int $daysRemaining,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $this->organization->license_expires_at
            ->timezone($this->organization->timezone)
            ->format('j F Y, g:i A T');
        $timing = $this->daysRemaining === 0
            ? 'expires today'
            : "expires in {$this->daysRemaining} days";

        return (new MailMessage)
            ->subject("FuelFlow license alert: {$this->organization->name} {$timing}")
            ->greeting('FuelFlow license expiry alert')
            ->line("The FuelFlow FSMS license for {$this->organization->name} {$timing}.")
            ->line("License expiry: {$expiresAt}")
            ->line('Renew the license before expiry to prevent company users from losing access.')
            ->line('The vendor can renew or update the license tenure from the Vendor Companies dashboard.')
            ->salutation('FuelFlow FSMS — © '.now()->year.' S4F. All rights reserved.');
    }
}
