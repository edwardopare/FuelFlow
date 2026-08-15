<?php

namespace App\Console\Commands;

use App\Enums\OrganizationStatus;
use App\Models\LicenseNotificationDispatch;
use App\Models\Organization;
use App\Services\ApplicationNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendLicenseExpiryAlerts extends Command
{
    private const ALERT_DAYS = [30, 14, 7, 3, 1, 0];

    protected $signature = 'emails:send-license-expiry-alerts';

    protected $description = 'Queue license expiry alerts for companies and vendor administrators';

    public function handle(ApplicationNotificationService $notifications): int
    {
        $queued = 0;

        Organization::query()
            ->where('status', '!=', OrganizationStatus::LicenseDeactivated->value)
            ->whereNotNull('license_expires_at')
            ->whereBetween('license_expires_at', [
                now()->startOfDay(),
                now()->addDays(max(self::ALERT_DAYS))->endOfDay(),
            ])
            ->orderBy('id')
            ->chunkById(100, function ($organizations) use ($notifications, &$queued): void {
                foreach ($organizations as $organization) {
                    $today = CarbonImmutable::now($organization->timezone)->startOfDay();
                    $expiryDate = CarbonImmutable::parse($organization->license_expires_at)
                        ->setTimezone($organization->timezone)
                        ->startOfDay();
                    $daysRemaining = (int) $today->diffInDays($expiryDate, false);

                    if (! in_array($daysRemaining, self::ALERT_DAYS, true)) {
                        continue;
                    }

                    $recipients = $notifications->licenseRecipients($organization);

                    if ($recipients === []) {
                        continue;
                    }

                    $dispatch = LicenseNotificationDispatch::query()->firstOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'license_expires_at' => $organization->license_expires_at,
                            'days_before_expiry' => $daysRemaining,
                        ],
                        [
                            'recipients' => array_keys($recipients),
                            'dispatched_at' => now(),
                        ],
                    );

                    if (! $dispatch->wasRecentlyCreated) {
                        continue;
                    }

                    try {
                        $notifications->licenseExpiring(
                            $organization,
                            $daysRemaining,
                            $recipients,
                        );
                    } catch (\Throwable $exception) {
                        $dispatch->delete();

                        throw $exception;
                    }

                    $queued += count($recipients);
                }
            });

        $this->info("Queued {$queued} license expiry email(s).");

        return self::SUCCESS;
    }
}
