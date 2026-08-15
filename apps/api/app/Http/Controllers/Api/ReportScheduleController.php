<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'reports.schedules.manage');

        return response()->json([
            'data' => ReportSchedule::query()
                ->where('organization_id', $request->user()->organization_id)
                ->where(
                    fn ($query) => $query
                        ->whereNull('station_id')
                        ->orWhereIn('station_id', $this->authorizedStationIds($request)),
                )
                ->with('station')
                ->orderBy('name')
                ->get()
                ->map(fn (ReportSchedule $schedule) => $this->serialize($schedule)),
        ]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $this->requirePermission($request, 'reports.schedules.manage');
        $validated = $request->validate($this->rules());
        if ($validated['station_id'] ?? null) {
            $this->requireStationAccess($request, $validated['station_id']);
        }
        $schedule = ReportSchedule::query()->create([
            ...$validated,
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);
        $audit->record('report_schedule.created', $schedule, after: $schedule->toArray());

        return response()->json([
            'data' => $this->serialize($schedule->load('station')),
        ], 201);
    }

    public function update(
        Request $request,
        ReportSchedule $reportSchedule,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $reportSchedule);
        $this->requirePermission($request, 'reports.schedules.manage');
        $validated = $request->validate($this->rules());
        if ($validated['station_id'] ?? null) {
            $this->requireStationAccess($request, $validated['station_id']);
        }
        $before = $reportSchedule->toArray();
        $reportSchedule->update($validated);
        $audit->record(
            'report_schedule.updated',
            $reportSchedule,
            before: $before,
            after: $reportSchedule->fresh()->toArray(),
        );

        return response()->json([
            'data' => $this->serialize($reportSchedule->load('station')),
        ]);
    }

    public function toggle(
        Request $request,
        ReportSchedule $reportSchedule,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $reportSchedule);
        $this->requirePermission($request, 'reports.schedules.manage');
        $reportSchedule->update(['is_active' => ! $reportSchedule->is_active]);
        $audit->record(
            'report_schedule.status_changed',
            $reportSchedule,
            after: ['is_active' => $reportSchedule->is_active],
        );

        return response()->json([
            'data' => $this->serialize($reportSchedule->load('station')),
        ]);
    }

    private function rules(): array
    {
        return [
            'station_id' => ['nullable', Rule::exists('stations', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'report_type' => ['required', Rule::in([
                'daily-sales',
                'stock-movements',
                'procurement',
                'reconciliation',
                'supplier-performance',
                'shift-attendance',
                'user-activity',
            ])],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'send_time' => ['required', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email'],
        ];
    }

    private function assertAccess(Request $request, ReportSchedule $schedule): void
    {
        abort_unless(
            $schedule->organization_id === $request->user()->organization_id,
            404,
        );
        if ($schedule->station_id) {
            $this->requireStationAccess($request, $schedule->station_id);
        }
    }

    private function serialize(ReportSchedule $schedule): array
    {
        return [
            ...$schedule->only([
                'id',
                'station_id',
                'name',
                'report_type',
                'frequency',
                'send_time',
                'day_of_week',
                'day_of_month',
                'recipients',
                'is_active',
            ]),
            'station_name' => $schedule->station?->name,
            'last_sent_at' => $schedule->last_sent_at?->toIso8601String(),
        ];
    }
}
