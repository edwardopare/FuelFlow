<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pump;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('shifts.view')
                || $request->user()->hasPermission('shifts.own.open')
                || $request->user()->hasPermission('shifts.own.close'),
            403,
        );
        $query = Shift::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with([
                'attendant',
                'pump.nozzles.product',
                'meterReadings.nozzle.product',
                'startedBy',
                'endedBy',
            ])
            ->latest('scheduled_start');

        if (($request->user()->hasPermission('shifts.own.open')
                || $request->user()->hasPermission('shifts.own.close'))
            && ! $request->user()->hasPermission('shifts.manage')) {
            $query->where('attendant_id', $request->user()->id);
        }

        return response()->json([
            'data' => $query->limit(500)->get()->map(fn (Shift $shift) => $this->serialize($shift)),
        ]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $isRecurring = $request->filled('date_from')
            || $request->filled('date_to')
            || $request->filled('start_time')
            || $request->filled('end_time');
        $rules = [
            'station_id' => ['required', Rule::exists('stations', 'id')],
            'attendant_id' => ['required', Rule::exists('users', 'id')],
            'pump_id' => ['required', Rule::exists('pumps', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($isRecurring) {
            $rules = [
                ...$rules,
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'start_time' => ['required', 'date_format:H:i'],
                'end_time' => ['required', 'date_format:H:i'],
            ];
        } else {
            $rules = [
                ...$rules,
                'scheduled_start' => ['required', 'date'],
                'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            ];
        }
        $validated = $request->validate($rules);
        $this->requireStationAccess($request, $validated['station_id']);
        $this->requirePermission($request, 'shifts.manage', $validated['station_id']);
        $pump = Pump::query()
            ->with('station')
            ->where('station_id', $validated['station_id'])
            ->findOrFail($validated['pump_id']);
        $attendant = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereHas('stations', fn ($query) => $query->where('stations.id', $validated['station_id']))
            ->findOrFail($validated['attendant_id']);
        abort_unless(
            $attendant->roleAssignments()
                ->where('station_id', $validated['station_id'])
                ->whereHas('role', fn ($query) => $query->where('slug', 'cashier_attendant'))
                ->exists(),
            422,
            'The selected user is not an attendant at this station.',
        );

        $timezone = $pump->station?->timezone ?? config('app.timezone');
        $occurrences = [];
        if ($isRecurring) {
            $firstDate = CarbonImmutable::parse($validated['date_from'], $timezone)->startOfDay();
            $lastDate = CarbonImmutable::parse($validated['date_to'], $timezone)->startOfDay();
            abort_if(
                $firstDate->diffInDays($lastDate) > 365,
                422,
                'A recurring shift date range cannot exceed 366 days.',
            );
            for ($date = $firstDate; $date->lte($lastDate); $date = $date->addDay()) {
                $scheduledStart = $date->setTimeFromTimeString($validated['start_time']);
                $scheduledEnd = $date->setTimeFromTimeString($validated['end_time']);
                if ($scheduledEnd->lte($scheduledStart)) {
                    $scheduledEnd = $scheduledEnd->addDay();
                }
                $occurrences[] = compact('scheduledStart', 'scheduledEnd');
            }
        } else {
            $occurrences[] = [
                'scheduledStart' => CarbonImmutable::parse($validated['scheduled_start'], $timezone),
                'scheduledEnd' => CarbonImmutable::parse($validated['scheduled_end'], $timezone),
            ];
        }

        $recurrenceId = $isRecurring ? (string) Str::ulid() : null;
        $shifts = DB::transaction(function () use (
            $occurrences,
            $attendant,
            $validated,
            $request,
            $pump,
            $recurrenceId,
            $audit,
        ) {
            foreach ($occurrences as $occurrence) {
                $overlap = Shift::query()
                    ->where('attendant_id', $attendant->id)
                    ->whereNot('status', 'closed')
                    ->where('scheduled_start', '<', $occurrence['scheduledEnd'])
                    ->where('scheduled_end', '>', $occurrence['scheduledStart'])
                    ->exists();
                abort_if(
                    $overlap,
                    409,
                    'The attendant already has an overlapping shift on '
                        .$occurrence['scheduledStart']->toDateString().'.',
                );
            }

            return collect($occurrences)->map(function (array $occurrence) use (
                $attendant,
                $validated,
                $request,
                $pump,
                $recurrenceId,
                $audit,
            ): Shift {
                $shift = Shift::query()->create([
                    'organization_id' => $request->user()->organization_id,
                    'station_id' => $validated['station_id'],
                    'attendant_id' => $attendant->id,
                    'pump_id' => $pump->id,
                    'code' => 'SH-'.$occurrence['scheduledStart']->format('Ymd')
                        .'-'.strtoupper(substr((string) Str::ulid(), -6)),
                    'recurrence_id' => $recurrenceId,
                    'status' => 'scheduled',
                    'scheduled_start' => $occurrence['scheduledStart'],
                    'scheduled_end' => $occurrence['scheduledEnd'],
                    'notes' => $validated['notes'] ?? null,
                ]);
                $audit->record(
                    'shift.scheduled',
                    $shift,
                    after: $shift->toArray(),
                    stationId: $shift->station_id,
                    metadata: [
                        'recurrence_id' => $recurrenceId,
                        'recurring' => $recurrenceId !== null,
                    ],
                );

                return $shift;
            });
        });

        /** @var Shift $shift */
        $shift = $shifts->first();

        return response()->json([
            'data' => $this->serialize($shift->load([
                'attendant',
                'pump.nozzles.product',
                'meterReadings.nozzle.product',
                'startedBy',
                'endedBy',
            ])),
            'meta' => [
                'created_count' => $shifts->count(),
                'recurrence_id' => $recurrenceId,
                'date_from' => $occurrences[0]['scheduledStart']->toDateString(),
                'date_to' => $occurrences[array_key_last($occurrences)]['scheduledStart']->toDateString(),
            ],
        ], 201);
    }

    public function open(
        Request $request,
        Shift $shift,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $shift);
        $isOwn = $shift->attendant_id === $request->user()->id;
        abort_unless(
            ($isOwn && $request->user()->hasPermission('shifts.own.open', $shift->station_id))
                || $request->user()->hasPermission('shifts.override', $shift->station_id),
            403,
        );
        abort_unless($shift->status === 'scheduled', 409, 'Only a scheduled shift can be opened.');
        abort_unless(
            $shift->scheduled_start->isSameDay(now()),
            409,
            'This shift can only be started on its scheduled date.',
        );
        $validated = $request->validate([
            'readings' => ['required', 'array', 'min:1'],
            'readings.*.nozzle_id' => ['required', 'distinct', Rule::exists('nozzles', 'id')],
            'readings.*.reading' => ['required', 'numeric', 'min:0'],
            'override_reason' => [$isOwn ? 'nullable' : 'required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($shift, $validated, $request, $audit): void {
            $lockedShift = Shift::query()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedShift->status === 'scheduled', 409, 'Only a scheduled shift can be opened.');
            abort_unless(
                $lockedShift->scheduled_start->isSameDay(now()),
                409,
                'This shift can only be started on its scheduled date.',
            );

            $nozzleIds = $lockedShift->pump->nozzles()->pluck('id');
            foreach ($validated['readings'] as $reading) {
                abort_unless($nozzleIds->contains($reading['nozzle_id']), 422, 'A reading does not belong to this pump.');
                $lockedShift->meterReadings()->updateOrCreate(
                    [
                        'nozzle_id' => $reading['nozzle_id'],
                        'type' => 'opening',
                    ],
                    [
                        'reading' => $reading['reading'],
                        'recorded_by' => $request->user()->id,
                        'recorded_at' => now(),
                    ],
                );
            }
            $startedAt = now();
            $lateSeconds = max(
                0,
                $startedAt->getTimestamp()
                    - $lockedShift->scheduled_start->getTimestamp(),
            );
            $lockedShift->update([
                'status' => 'open',
                'opened_at' => $startedAt,
                'started_by' => $request->user()->id,
                'late_seconds' => $lateSeconds,
            ]);
            $audit->record(
                'shift.opened',
                $lockedShift,
                after: [
                    'status' => 'open',
                    'opened_at' => $startedAt->toIso8601String(),
                    'started_by' => $request->user()->id,
                    'late_seconds' => $lateSeconds,
                ],
                stationId: $lockedShift->station_id,
                reason: $validated['override_reason'] ?? null,
            );
        });

        return response()->json([
            'data' => $this->serialize($shift->refresh()->load([
                'attendant',
                'pump.nozzles.product',
                'meterReadings.nozzle.product',
                'startedBy',
                'endedBy',
            ])),
        ]);
    }

    public function close(
        Request $request,
        Shift $shift,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $shift);
        $isOwn = $shift->attendant_id === $request->user()->id;
        abort_unless(
            ($isOwn && $request->user()->hasPermission('shifts.own.close', $shift->station_id))
                || $request->user()->hasPermission('shifts.override', $shift->station_id),
            403,
        );
        abort_unless($shift->status === 'open', 409, 'Only an open shift can be closed.');
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'readings' => ['required', 'array', 'min:1'],
            'readings.*.nozzle_id' => ['required', Rule::exists('nozzles', 'id')],
            'readings.*.reading' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'override_reason' => [$isOwn ? 'nullable' : 'required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($shift, $validated, $request, $audit): void {
            $nozzleIds = $shift->pump->nozzles()->pluck('id');
            foreach ($validated['readings'] as $reading) {
                abort_unless($nozzleIds->contains($reading['nozzle_id']), 422);
                $opening = $shift->meterReadings()
                    ->where('nozzle_id', $reading['nozzle_id'])
                    ->where('type', 'opening')
                    ->value('reading');
                abort_if($opening !== null && (float) $reading['reading'] < (float) $opening, 422, 'Closing reading cannot be below opening reading.');
                $shift->meterReadings()->create([
                    'nozzle_id' => $reading['nozzle_id'],
                    'type' => 'closing',
                    'reading' => $reading['reading'],
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                ]);
            }
            $endedAt = now();
            $overtimeSeconds = max(
                0,
                $endedAt->getTimestamp()
                    - $shift->scheduled_end->getTimestamp(),
            );
            $shift->update([
                'status' => 'closed',
                'closed_at' => $endedAt,
                'ended_by' => $request->user()->id,
                'overtime_seconds' => $overtimeSeconds,
                'counted_cash' => $validated['counted_cash'],
                'notes' => $validated['notes'] ?? null,
            ]);
            $audit->record(
                'shift.closed',
                $shift,
                after: [
                    'status' => 'closed',
                    'closed_at' => $endedAt->toIso8601String(),
                    'ended_by' => $request->user()->id,
                    'overtime_seconds' => $overtimeSeconds,
                    'counted_cash' => $validated['counted_cash'],
                ],
                stationId: $shift->station_id,
                reason: $validated['override_reason'] ?? null,
            );
        });

        return response()->json([
            'data' => $this->serialize($shift->load([
                'attendant',
                'pump.nozzles.product',
                'meterReadings.nozzle.product',
                'startedBy',
                'endedBy',
            ])),
        ]);
    }

    private function assertAccess(Request $request, Shift $shift): void
    {
        abort_unless(
            $shift->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $shift->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Shift $shift): array
    {
        $liveEnd = $shift->closed_at ?? now();
        $elapsedSeconds = $shift->status !== 'scheduled' && $shift->opened_at
            ? max(0, $liveEnd->getTimestamp() - $shift->opened_at->getTimestamp())
            : 0;
        $overtimeSeconds = $shift->status === 'open'
            ? max(0, now()->getTimestamp() - $shift->scheduled_end->getTimestamp())
            : (int) $shift->overtime_seconds;

        return [
            'id' => $shift->id,
            'code' => $shift->code,
            'recurrence_id' => $shift->recurrence_id,
            'station_id' => $shift->station_id,
            'attendant_id' => $shift->attendant_id,
            'attendant_name' => $shift->attendant?->name,
            'pump_id' => $shift->pump_id,
            'pump_name' => $shift->pump?->name,
            'status' => $shift->status,
            'scheduled_start' => $shift->scheduled_start->toIso8601String(),
            'scheduled_end' => $shift->scheduled_end->toIso8601String(),
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'started_by' => $shift->started_by,
            'started_by_name' => $shift->startedBy?->name,
            'late_seconds' => (int) $shift->late_seconds,
            'is_late' => (int) $shift->late_seconds > 0,
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'ended_by' => $shift->ended_by,
            'ended_by_name' => $shift->endedBy?->name,
            'overtime_seconds' => $overtimeSeconds,
            'is_overtime' => $overtimeSeconds > 0,
            'elapsed_seconds' => $elapsedSeconds,
            'expected_cash' => $shift->expected_cash,
            'counted_cash' => $shift->counted_cash,
            'notes' => $shift->notes,
            'meter_readings' => $shift->relationLoaded('meterReadings')
                ? $shift->meterReadings->map(fn ($reading) => [
                    'id' => $reading->id,
                    'nozzle_id' => $reading->nozzle_id,
                    'nozzle_code' => $reading->nozzle?->code,
                    'type' => $reading->type,
                    'reading' => $reading->reading,
                    'recorded_at' => $reading->recorded_at->toIso8601String(),
                ])->values()
                : [],
            'pump_nozzles' => $shift->pump?->relationLoaded('nozzles')
                ? $shift->pump->nozzles->map(fn ($nozzle) => [
                    'id' => $nozzle->id,
                    'code' => $nozzle->code,
                    'product_name' => $nozzle->product?->name,
                    'current_meter_reading' => $nozzle->current_meter_reading,
                ])->values()
                : [],
        ];
    }
}
