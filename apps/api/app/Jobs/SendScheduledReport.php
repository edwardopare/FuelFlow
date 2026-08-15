<?php

namespace App\Jobs;

use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\Station;
use App\Notifications\ScheduledReportNotification;
use App\Services\ReportCsvService;
use App\Services\ReportDataService;
use App\Services\ReportScheduleTimingService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class SendScheduledReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $reportScheduleId,
        public string $scheduledFor,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->reportScheduleId.'|'.$this->scheduledFor;
    }

    public function handle(
        ReportDataService $reports,
        ReportCsvService $csv,
        ReportScheduleTimingService $timing,
    ): void {
        $schedule = ReportSchedule::query()
            ->with(['organization', 'station'])
            ->find($this->reportScheduleId);

        if (! $schedule || ! $schedule->is_active) {
            return;
        }

        $scheduledFor = CarbonImmutable::parse($this->scheduledFor)->utc();
        [$from, $to] = $timing->reportPeriod($schedule, $scheduledFor);
        $stationIds = $schedule->station_id
            ? [$schedule->station_id]
            : Station::query()
                ->where('organization_id', $schedule->organization_id)
                ->pluck('id')
                ->all();
        $report = $reports->generate(
            $schedule->organization_id,
            $stationIds,
            $schedule->report_type,
            $from,
            $to,
            $schedule->station_id === null,
        );
        $contents = $csv->make($report['columns'], $report['rows']);
        $timezone = $schedule->station?->timezone ?: $schedule->organization->timezone;
        $periodLabel = $from->setTimezone($timezone)->format('j M Y, g:i A')
            .' – '.$to->setTimezone($timezone)->format('j M Y, g:i A T');
        $reportLabel = Str::headline($schedule->report_type);
        $filename = Str::slug($schedule->name).'-'
            .$scheduledFor->setTimezone($timezone)->format('Y-m-d').'.csv';
        $rowCount = is_countable($report['rows']) ? count($report['rows']) : 0;
        $deliveries = ReportScheduleDelivery::query()
            ->where('report_schedule_id', $schedule->id)
            ->where('scheduled_for', $scheduledFor)
            ->where('status', '!=', 'sent')
            ->orderBy('recipient')
            ->get();

        foreach ($deliveries as $delivery) {
            $attemptedAt = now();
            $delivery->forceFill([
                'status' => 'sending',
                'attempts' => $delivery->attempts + 1,
                'attempted_at' => $attemptedAt,
                'last_error' => null,
            ])->save();

            try {
                Notification::route('mail', $delivery->recipient)->notify(
                    new ScheduledReportNotification(
                        $schedule->name,
                        $schedule->organization->name,
                        $reportLabel,
                        $periodLabel,
                        $rowCount,
                        $contents,
                        $filename,
                    ),
                );
            } catch (\Throwable $exception) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'last_error' => Str::limit($exception->getMessage(), 2000, ''),
                ])->save();

                throw $exception;
            }

            $delivery->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'last_error' => null,
            ])->save();
        }

        $remaining = ReportScheduleDelivery::query()
            ->where('report_schedule_id', $schedule->id)
            ->where('scheduled_for', $scheduledFor)
            ->where('status', '!=', 'sent')
            ->exists();

        if (! $remaining) {
            $schedule->forceFill(['last_sent_at' => $scheduledFor])->save();
        }
    }
}
