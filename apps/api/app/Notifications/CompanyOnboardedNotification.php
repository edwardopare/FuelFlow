<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyOnboardedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public Organization $organization)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->organization->loadMissing('users');
        $expiresAt = $organization->license_expires_at
            ? $organization->license_expires_at
                ->timezone($organization->timezone)
                ->format('j F Y, g:i A T')
            : 'No expiry date';

        return (new MailMessage)
            ->subject("{$organization->name} is ready on FuelFlow FSMS")
            ->greeting('Welcome to FuelFlow FSMS')
            ->line("{$organization->name} has been successfully onboarded.")
            ->line('Currency: Ghanaian cedi (GHS)')
            ->line("License expiry: {$expiresAt}")
            ->line('Accounts created: '.$organization->users->count())
            ->line('Each account holder receives a separate secure account email. Passwords are never included in email.')
            ->action('Open FuelFlow FSMS', rtrim((string) config('app.frontend_url'), '/').'/login')
            ->line('Contact the FuelFlow vendor if any company or license detail is incorrect.')
            ->salutation('FuelFlow FSMS — © '.now()->year.' S4F. All rights reserved.');
    }
}
