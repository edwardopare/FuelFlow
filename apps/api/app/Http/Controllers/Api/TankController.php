<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'tanks.view');
        $tanks = Tank::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with(['product', 'station', 'readings' => fn ($query) => $query
                ->latest('read_at')
                ->limit(1)])
            ->orderBy('code')
            ->get()
            ->map(fn (Tank $tank) => $this->serialize($tank));

        return response()->json(['data' => $tanks]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', Rule::exists('stations', 'id')],
            'product_id' => ['required', Rule::exists('products', 'id')],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'tank_grade' => ['nullable', 'string', 'max:80'],
            'capacity_litres' => ['required', 'numeric', 'gt:0'],
            'book_stock_litres' => ['required', 'numeric', 'min:0'],
            'minimum_safe_litres' => ['required', 'numeric', 'min:0'],
            'maximum_safe_litres' => [
                'required',
                'numeric',
                'gt:minimum_safe_litres',
                'lte:capacity_litres',
            ],
            'atg_enabled' => ['boolean'],
        ]);
        $this->requireStationAccess($request, $validated['station_id']);
        $this->requirePermission($request, 'tanks.manage', $validated['station_id']);
        $product = Product::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['product_id']);
        abort_if(
            $validated['book_stock_litres'] > $validated['maximum_safe_litres'],
            422,
            'Opening stock cannot exceed the maximum safe level.',
        );

        $tank = DB::transaction(function () use (
            $validated,
            $request,
            $product,
            $audit,
        ): Tank {
            $tank = Tank::query()->create([
                ...$validated,
                'organization_id' => $request->user()->organization_id,
                'product_id' => $product->id,
                'status' => 'active',
            ]);

            if ((float) $tank->book_stock_litres > 0) {
                StockMovement::query()->create([
                    'organization_id' => $tank->organization_id,
                    'station_id' => $tank->station_id,
                    'tank_id' => $tank->id,
                    'product_id' => $tank->product_id,
                    'type' => 'opening_balance',
                    'quantity_litres' => $tank->book_stock_litres,
                    'balance_after_litres' => $tank->book_stock_litres,
                    'source_type' => 'tank_setup',
                    'source_id' => $tank->id,
                    'recorded_by' => $request->user()->id,
                    'occurred_at' => now(),
                ]);
            }
            $audit->record('tank.created', $tank, after: $tank->toArray(), stationId: $tank->station_id);

            return $tank;
        });

        return response()->json([
            'data' => $this->serialize($tank->load(['product', 'station', 'readings'])),
        ], 201);
    }

    public function addReading(
        Request $request,
        Tank $tank,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $tank);
        $this->requirePermission($request, 'tanks.readings.create', $tank->station_id);
        $validated = $request->validate([
            'reading_litres' => ['required', 'numeric', 'min:0', "max:{$tank->capacity_litres}"],
            'read_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $reading = $tank->readings()->create([
            ...$validated,
            'recorded_by' => $request->user()->id,
            'book_stock_litres' => $tank->book_stock_litres,
            'variance_litres' => round(
                (float) $validated['reading_litres'] - (float) $tank->book_stock_litres,
                3,
            ),
            'source' => 'manual',
        ]);
        $audit->record(
            'tank.reading_recorded',
            $tank,
            after: $reading->toArray(),
            stationId: $tank->station_id,
        );

        return response()->json([
            'data' => $this->serialize($tank->load(['product', 'station', 'readings'])),
        ], 201);
    }

    public function movements(Request $request, Tank $tank): JsonResponse
    {
        $this->assertAccess($request, $tank);
        $this->requirePermission($request, 'tanks.view', $tank->station_id);

        return response()->json([
            'data' => $tank->movements()
                ->latest('occurred_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function transfer(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'source_tank_id' => ['required', 'different:destination_tank_id', Rule::exists('tanks', 'id')],
            'destination_tank_id' => ['required', Rule::exists('tanks', 'id')],
            'quantity_litres' => ['required', 'numeric', 'gt:0'],
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $source = Tank::query()->findOrFail($validated['source_tank_id']);
        $destination = Tank::query()->findOrFail($validated['destination_tank_id']);
        $this->assertAccess($request, $source);
        $this->assertAccess($request, $destination);
        $this->requirePermission($request, 'tanks.transfers.create', $source->station_id);
        abort_unless(
            $source->station_id === $destination->station_id
                && $source->product_id === $destination->product_id,
            422,
            'Transfers require compatible tanks at the same station.',
        );

        DB::transaction(function () use (
            $source,
            $destination,
            $validated,
            $request,
            $audit,
        ): void {
            $locked = Tank::query()
                ->whereIn('id', [$source->id, $destination->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSource = $locked[$source->id];
            $lockedDestination = $locked[$destination->id];
            $quantity = (float) $validated['quantity_litres'];
            abort_if((float) $lockedSource->book_stock_litres < $quantity, 422, 'Insufficient source stock.');
            abort_if(
                (float) $lockedDestination->book_stock_litres + $quantity
                    > (float) $lockedDestination->maximum_safe_litres,
                422,
                'The transfer would exceed the destination safe level.',
            );
            $lockedSource->decrement('book_stock_litres', $quantity);
            $lockedDestination->increment('book_stock_litres', $quantity);

            foreach ([
                [$lockedSource, -$quantity, 'transfer_out'],
                [$lockedDestination, $quantity, 'transfer_in'],
            ] as [$tank, $signedQuantity, $type]) {
                StockMovement::query()->create([
                    'organization_id' => $tank->organization_id,
                    'station_id' => $tank->station_id,
                    'tank_id' => $tank->id,
                    'product_id' => $tank->product_id,
                    'type' => $type,
                    'quantity_litres' => $signedQuantity,
                    'balance_after_litres' => $tank->fresh()->book_stock_litres,
                    'source_type' => 'tank_transfer',
                    'source_id' => $source->id.'-'.$destination->id,
                    'recorded_by' => $request->user()->id,
                    'occurred_at' => now(),
                    'notes' => $validated['notes'],
                ]);
            }
            $audit->record(
                'tank.transfer_completed',
                $source,
                after: [
                    'destination_tank_id' => $destination->id,
                    'quantity_litres' => $quantity,
                ],
                stationId: $source->station_id,
                reason: $validated['notes'],
            );
        });

        return response()->json(['message' => 'Tank transfer completed.']);
    }

    private function assertAccess(Request $request, Tank $tank): void
    {
        abort_unless(
            $tank->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $tank->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Tank $tank): array
    {
        $latestReading = $tank->relationLoaded('readings')
            ? $tank->readings->sortByDesc('read_at')->first()
            : null;
        $percentage = (float) $tank->capacity_litres > 0
            ? round(((float) $tank->book_stock_litres / (float) $tank->capacity_litres) * 100, 1)
            : 0;

        return [
            'id' => $tank->id,
            'station_id' => $tank->station_id,
            'station_name' => $tank->station?->name,
            'product_id' => $tank->product_id,
            'product_name' => $tank->product?->name,
            'product_code' => $tank->product?->code,
            'code' => $tank->code,
            'name' => $tank->name,
            'tank_grade' => $tank->tank_grade,
            'capacity_litres' => $tank->capacity_litres,
            'book_stock_litres' => $tank->book_stock_litres,
            'minimum_safe_litres' => $tank->minimum_safe_litres,
            'maximum_safe_litres' => $tank->maximum_safe_litres,
            'stock_percentage' => $percentage,
            'level_status' => (float) $tank->book_stock_litres <= (float) $tank->minimum_safe_litres
                ? 'critical'
                : ($percentage <= 30 ? 'low' : 'healthy'),
            'atg_enabled' => $tank->atg_enabled,
            'status' => $tank->status,
            'latest_reading' => $latestReading ? [
                'id' => $latestReading->id,
                'reading_litres' => $latestReading->reading_litres,
                'variance_litres' => $latestReading->variance_litres,
                'read_at' => $latestReading->read_at->toIso8601String(),
            ] : null,
        ];
    }
}
