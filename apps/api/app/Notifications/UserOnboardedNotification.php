<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserOnboardedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public User $account)
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
        $account = $this->account->loadMissing([
            'organization',
            'stations',
            'roleAssignments.role',
        ]);
        $roles = $account->roleAssignments
            ->pluck('role.name')
            ->filter()
            ->unique()
            ->implode(', ');
        $stations = $account->stations->pluck('name')->unique()->implode(', ');

        return (new MailMessage)
            ->subject("Your {$account->organization->name} FuelFlow account is ready")
            ->greeting("Hello {$account->name},")
            ->line("An account has been created for you on FuelFlow FSMS for {$account->organization->name}.")
            ->line('Role: '.($roles ?: 'Assigned user'))
            ->line('Station: '.($stations ?: 'Head Office'))
            ->line('Sign in with your email address and the temporary password supplied securely by your administrator.')
            ->line('You will be required to choose a new password when you first sign in. Passwords are never included in email.')
            ->action('Sign in to FuelFlow FSMS', rtrim((string) config('app.frontend_url'), '/').'/login')
            ->line('If you did not expect this account, contact your company administrator.')
            ->salutation('FuelFlow FSMS — © '.now()->year.' S4F. All rights reserved.');
    }
}
