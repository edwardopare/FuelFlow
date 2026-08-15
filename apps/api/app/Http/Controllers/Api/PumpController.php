<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pump;
use App\Models\Tank;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PumpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'pumps.view');
        $pumps = Pump::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with([
                'station',
                'nozzles.product',
                'nozzles.tank',
                'maintenanceEvents' => fn ($query) => $query->latest('started_at')->limit(5),
            ])
            ->orderBy('code')
            ->get()
            ->map(fn (Pump $pump) => $this->serialize($pump));

        return response()->json(['data' => $pumps]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', Rule::exists('stations', 'id')],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'nozzles' => ['required', 'array', 'min:1'],
            'nozzles.*.code' => ['required', 'string', 'max:32', 'distinct'],
            'nozzles.*.tank_id' => ['required', Rule::exists('tanks', 'id')],
            'nozzles.*.current_meter_reading' => ['required', 'numeric', 'min:0'],
            'nozzles.*.meter_maximum' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $this->requireStationAccess($request, $validated['station_id']);
        $this->requirePermission($request, 'pumps.manage', $validated['station_id']);

        $pump = DB::transaction(function () use (
            $validated,
            $request,
            $audit,
        ): Pump {
            $pump = Pump::query()->create([
                'organization_id' => $request->user()->organization_id,
                'station_id' => $validated['station_id'],
                'code' => $validated['code'],
                'name' => $validated['name'],
                'status' => 'operational',
            ]);

            foreach ($validated['nozzles'] as $nozzle) {
                $tank = Tank::query()
                    ->where('station_id', $pump->station_id)
                    ->findOrFail($nozzle['tank_id']);
                $pump->nozzles()->create([
                    ...$nozzle,
                    'product_id' => $tank->product_id,
                    'status' => 'operational',
                ]);
            }
            $audit->record(
                'pump.created',
                $pump,
                after: $pump->toArray(),
                stationId: $pump->station_id,
            );

            return $pump;
        });

        return response()->json([
            'data' => $this->serialize($pump->load([
                'station',
                'nozzles.product',
                'nozzles.tank',
                'maintenanceEvents',
            ])),
        ], 201);
    }

    public function setStatus(
        Request $request,
        Pump $pump,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $pump);
        $this->requirePermission($request, 'pumps.manage', $pump->station_id);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['operational', 'under_maintenance', 'out_of_service'])],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $before = ['status' => $pump->status];

        DB::transaction(function () use ($pump, $validated, $request, $audit, $before): void {
            $pump->update(['status' => $validated['status']]);
            $pump->nozzles()->update([
                'status' => $validated['status'] === 'operational'
                    ? 'operational'
                    : 'out_of_service',
            ]);
            $pump->maintenanceEvents()->create([
                'status' => $validated['status'],
                'description' => $validated['description'],
                'started_at' => now(),
                'completed_at' => $validated['status'] === 'operational' ? now() : null,
                'recorded_by' => $request->user()->id,
            ]);
            $audit->record(
                'pump.status_changed',
                $pump,
                before: $before,
                after: ['status' => $validated['status']],
                stationId: $pump->station_id,
                reason: $validated['description'],
            );
        });

        return response()->json([
            'data' => $this->serialize($pump->load([
                'station',
                'nozzles.product',
                'nozzles.tank',
                'maintenanceEvents',
            ])),
        ]);
    }

    private function assertAccess(Request $request, Pump $pump): void
    {
        abort_unless(
            $pump->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $pump->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Pump $pump): array
    {
        return [
            'id' => $pump->id,
            'station_id' => $pump->station_id,
            'station_name' => $pump->station?->name,
            'code' => $pump->code,
            'name' => $pump->name,
            'status' => $pump->status,
            'nozzles' => $pump->relationLoaded('nozzles')
                ? $pump->nozzles->map(fn ($nozzle) => [
                    'id' => $nozzle->id,
                    'code' => $nozzle->code,
                    'status' => $nozzle->status,
                    'tank_id' => $nozzle->tank_id,
                    'tank_name' => $nozzle->tank?->name,
                    'product_id' => $nozzle->product_id,
                    'product_name' => $nozzle->product?->name,
                    'current_meter_reading' => $nozzle->current_meter_reading,
                    'meter_maximum' => $nozzle->meter_maximum,
                ])->values()
                : [],
            'maintenance_events' => $pump->relationLoaded('maintenanceEvents')
                ? $pump->maintenanceEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => $event->status,
                    'description' => $event->description,
                    'started_at' => $event->started_at->toIso8601String(),
                    'completed_at' => $event->completed_at?->toIso8601String(),
                ])->values()
                : [],
        ];
    }
}
