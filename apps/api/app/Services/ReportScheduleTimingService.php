<?php

namespace App\Services;

use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;

class ReportScheduleTimingService
{
    public function latestDueAt(
        ReportSchedule $schedule,
        ?CarbonImmutable $now = null,
    ): ?CarbonImmutable {
        $now ??= CarbonImmutable::now('UTC');
        $timezone = $schedule->station?->timezone
            ?: $schedule->organization->timezone;
        $localNow = $now->setTimezone($timezone);
        [$hour, $minute] = array_map(
            'intval',
            array_slice(explode(':', (string) $schedule->send_time), 0, 2),
        );

        $candidate = match ($schedule->frequency) {
            'daily' => $localNow->startOfDay()->setTime($hour, $minute),
            'weekly' => $localNow
                ->startOfWeek()
                ->addDays(max(1, (int) $schedule->day_of_week) - 1)
                ->setTime($hour, $minute),
            'monthly' => $localNow
                ->startOfMonth()
                ->addDays(max(1, (int) $schedule->day_of_month) - 1)
                ->setTime($hour, $minute),
            default => null,
        };

        if (! $candidate) {
            return null;
        }

        if ($candidate->isAfter($localNow)) {
            $candidate = match ($schedule->frequency) {
                'daily' => $candidate->subDay(),
                'weekly' => $candidate->subWeek(),
                'monthly' => $candidate->subMonthNoOverflow(),
            };
        }

        return $candidate->utc();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function reportPeriod(
        ReportSchedule $schedule,
        CarbonImmutable $scheduledFor,
    ): array {
        $timezone = $schedule->station?->timezone
            ?: $schedule->organization->timezone;
        $localOccurrence = $scheduledFor->setTimezone($timezone);

        return match ($schedule->frequency) {
            'daily' => [
                $localOccurrence->subDay()->startOfDay()->utc(),
                $localOccurrence->subDay()->endOfDay()->utc(),
            ],
            'weekly' => [
                $localOccurrence->subDays(7)->startOfDay()->utc(),
                $localOccurrence->subDay()->endOfDay()->utc(),
            ],
            'monthly' => [
                $localOccurrence->subMonthNoOverflow()->startOfMonth()->startOfDay()->utc(),
                $localOccurrence->subMonthNoOverflow()->endOfMonth()->endOfDay()->utc(),
            ],
            default => throw new \InvalidArgumentException(
                "Unsupported report frequency: {$schedule->frequency}",
            ),
        };
    }
}
