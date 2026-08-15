<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const DEFAULTS = [
        'receiving_variance_tolerance_percent' => 0.5,
        'pump_variance_tolerance_percent' => 0.5,
        'cash_variance_tolerance_ghs' => 0,
        'low_stock_alert_percent' => 20,
        'report_timezone' => 'Africa/Accra',
        'currency' => 'GHS',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'stations.view');
        $stored = SystemSetting::query()
            ->where('organization_id', $request->user()->organization_id)
            ->pluck('value', 'key')
            ->all();

        return response()->json([
            'data' => [
                'organization' => $request->user()->organization->only([
                    'id',
                    'name',
                    'currency',
                    'timezone',
                ]),
                'settings' => [...$this->defaults(), ...$stored],
            ],
        ]);
    }

    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $this->requirePermission($request, 'stations.manage');
        $validated = $request->validate([
            'session_timeout_minutes' => ['required', 'integer', 'between:5,10080'],
            'receiving_variance_tolerance_percent' => ['required', 'numeric', 'between:0,20'],
            'pump_variance_tolerance_percent' => ['required', 'numeric', 'between:0,20'],
            'cash_variance_tolerance_ghs' => ['required', 'numeric', 'min:0'],
            'low_stock_alert_percent' => ['required', 'numeric', 'between:1,99'],
            'report_timezone' => ['required', 'timezone'],
        ]);
        foreach ($validated as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                [
                    'organization_id' => $request->user()->organization_id,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                    'updated_by' => $request->user()->id,
                ],
            );
        }
        $audit->record(
            'system.settings_updated',
            $request->user()->organization,
            after: $validated,
        );

        return $this->index($request);
    }

    /**
     * @return array<string, int|float|string>
     */
    private function defaults(): array
    {
        return [
            'session_timeout_minutes' => (int) config('session.lifetime', 120),
            ...self::DEFAULTS,
        ];
    }
}
