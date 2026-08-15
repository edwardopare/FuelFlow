<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(
        Request $request,
        ReportDataService $reports,
    ): JsonResponse {
        $this->requirePermission($request, 'reports.view');
        $validated = $request->validate([
            'type' => ['required', Rule::in([
                'daily-sales',
                'stock-movements',
                'procurement',
                'reconciliation',
                'supplier-performance',
                'shift-attendance',
                'user-activity',
            ])],
            'station_id' => ['nullable', Rule::exists('stations', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $stationIds = $this->authorizedStationIds($request);
        if ($validated['station_id'] ?? null) {
            $this->requireStationAccess($request, $validated['station_id']);
            $stationIds = [$validated['station_id']];
        }
        $from = CarbonImmutable::parse($validated['from'] ?? now()->subDays(30))->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? now())->endOfDay();
        $result = $reports->generate(
            $request->user()->organization_id,
            $stationIds,
            $validated['type'],
            $from,
            $to,
            ! isset($validated['station_id'])
                && $request->user()->hasOrganizationWidePermission('reports.view'),
        );

        return response()->json([
            'data' => [
                'type' => $validated['type'],
                'currency' => $result['currency'],
                'generated_at' => now()->toIso8601String(),
                'columns' => $result['columns'],
                'rows' => $result['rows'],
            ],
        ]);
    }
}
