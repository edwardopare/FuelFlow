<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduledReportNotification extends Notification
{
    public function __construct(
        public string $scheduleName,
        public string $organizationName,
        public string $reportLabel,
        public string $periodLabel,
        public int $rowCount,
        public string $csv,
        public string $filename,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->scheduleName} — {$this->organizationName}")
            ->greeting('Your scheduled FuelFlow report is ready')
            ->line("Report: {$this->reportLabel}")
            ->line("Period: {$this->periodLabel}")
            ->line("Records: {$this->rowCount}")
            ->line('The report is attached as a CSV file and uses Ghanaian cedi (GHS) for monetary values.')
            ->attachData($this->csv, $this->filename, ['mime' => 'text/csv'])
            ->salutation('FuelFlow FSMS — © '.now()->year.' S4F. All rights reserved.');
    }
}
