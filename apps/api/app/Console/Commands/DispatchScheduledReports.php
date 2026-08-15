<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReport;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Services\ReportScheduleTimingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledReports extends Command
{
    protected $signature = 'reports:dispatch-scheduled';

    protected $description = 'Queue due scheduled report email deliveries';

    public function handle(ReportScheduleTimingService $timing): int
    {
        $jobs = 0;
        $now = CarbonImmutable::now('UTC');

        ReportSchedule::query()
            ->where('is_active', true)
            ->with(['organization', 'station'])
            ->orderBy('id')
            ->chunkById(100, function ($schedules) use ($timing, $now, &$jobs): void {
                foreach ($schedules as $schedule) {
                    $scheduledFor = $timing->latestDueAt($schedule, $now);

                    if (! $scheduledFor
                        || $schedule->last_sent_at?->greaterThanOrEqualTo($scheduledFor)) {
                        continue;
                    }

                    $shouldDispatch = DB::transaction(function () use (
                        $schedule,
                        $scheduledFor,
                    ): bool {
                        $locked = ReportSchedule::query()
                            ->lockForUpdate()
                            ->findOrFail($schedule->id);

                        if (! $locked->is_active
                            || $locked->last_sent_at?->greaterThanOrEqualTo($scheduledFor)) {
                            return false;
                        }

                        foreach ($locked->recipients as $recipient) {
                            ReportScheduleDelivery::query()->firstOrCreate([
                                'report_schedule_id' => $locked->id,
                                'scheduled_for' => $scheduledFor,
                                'recipient' => strtolower($recipient),
                            ]);
                        }

                        return ReportScheduleDelivery::query()
                            ->where('report_schedule_id', $locked->id)
                            ->where('scheduled_for', $scheduledFor)
                            ->where('status', '!=', 'sent')
                            ->exists();
                    });

                    if ($shouldDispatch) {
                        SendScheduledReport::dispatch(
                            $schedule->id,
                            $scheduledFor->toIso8601String(),
                        );
                        $jobs++;
                    }
                }
            });

        $this->info("Queued {$jobs} scheduled report job(s).");

        return self::SUCCESS;
    }
}
