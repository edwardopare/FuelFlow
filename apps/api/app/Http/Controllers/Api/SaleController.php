<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nozzle;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permission = $request->user()->hasPermission('sales.view')
            ? 'sales.view'
            : 'sales.own.view';
        $this->requirePermission($request, $permission);
        $query = Sale::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with(['station', 'product', 'nozzle.pump', 'attendant'])
            ->latest('sold_at');

        if ($permission === 'sales.own.view') {
            $query->where('attendant_id', $request->user()->id);
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (Sale $sale) => $this->serialize($sale)),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nozzle_id' => ['required', Rule::exists('nozzles', 'id')],
            'litres' => ['required', 'numeric', 'gt:0'],
        ]);
        $nozzle = Nozzle::query()->with(['pump', 'tank', 'product'])->findOrFail(
            $validated['nozzle_id'],
        );
        $this->requireStationAccess($request, $nozzle->pump->station_id);
        $this->requirePermission($request, 'sales.create', $nozzle->pump->station_id);
        $price = $this->currentPrice($nozzle->product_id, $nozzle->pump->station_id);
        abort_unless($price, 422, 'No active selling price is configured for this product.');

        return response()->json([
            'data' => [
                'product_id' => $nozzle->product_id,
                'product_name' => $nozzle->product->name,
                'unit_price' => $price->price,
                'litres' => number_format((float) $validated['litres'], 3, '.', ''),
                'amount' => number_format(
                    round((float) $validated['litres'] * (float) $price->price, 2, PHP_ROUND_HALF_UP),
                    2,
                    '.',
                    '',
                ),
                'available_stock_litres' => $nozzle->tank->book_stock_litres,
            ],
        ]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'nozzle_id' => ['required', Rule::exists('nozzles', 'id')],
            'shift_id' => ['nullable', Rule::exists('shifts', 'id')],
            'litres' => ['required', 'numeric', 'gt:0'],
            'payment_method' => [
                'required',
                Rule::in(['cash', 'card', 'mobile_money', 'credit', 'fleet']),
            ],
            'payment_reference' => [
                Rule::requiredIf(fn () => $request->input('payment_method') !== 'cash'),
                'nullable',
                'string',
                'max:120',
            ],
        ]);
        $nozzle = Nozzle::query()
            ->with(['pump', 'tank', 'product'])
            ->findOrFail($validated['nozzle_id']);
        $stationId = $nozzle->pump->station_id;
        $this->requireStationAccess($request, $stationId);
        $this->requirePermission($request, 'sales.create', $stationId);
        abort_unless(
            $nozzle->status === 'operational'
                && $nozzle->pump->status === 'operational',
            422,
            'This pump or nozzle is not available for sales.',
        );
        $shift = isset($validated['shift_id'])
            ? Shift::query()
                ->where('station_id', $stationId)
                ->findOrFail($validated['shift_id'])
            : null;
        $isAttendant = $request->user()->roleAssignments()
            ->whereHas('role', fn ($query) => $query->where('slug', 'cashier_attendant'))
            ->exists();

        if ($isAttendant) {
            abort_unless(
                $shift
                    && $shift->status === 'open'
                    && $shift->attendant_id === $request->user()->id
                    && $shift->pump_id === $nozzle->pump_id,
                403,
                'Attendant sales require the assigned open shift and pump.',
            );
        }

        $price = $this->currentPrice($nozzle->product_id, $stationId);
        abort_unless($price, 422, 'No active selling price is configured.');
        $litres = round((float) $validated['litres'], 3);
        $amount = round($litres * (float) $price->price, 2, PHP_ROUND_HALF_UP);

        $sale = DB::transaction(function () use (
            $validated,
            $nozzle,
            $stationId,
            $request,
            $shift,
            $price,
            $litres,
            $amount,
            $audit,
        ): Sale {
            $tank = Tank::query()->lockForUpdate()->findOrFail($nozzle->tank_id);
            abort_if(
                (float) $tank->book_stock_litres < $litres,
                422,
                'The tank does not have enough book stock for this sale.',
            );
            $tank->decrement('book_stock_litres', $litres);
            $nozzle->increment('current_meter_reading', $litres);
            $sale = Sale::query()->create([
                'organization_id' => $request->user()->organization_id,
                'station_id' => $stationId,
                'shift_id' => $shift?->id,
                'attendant_id' => $request->user()->id,
                'nozzle_id' => $nozzle->id,
                'tank_id' => $tank->id,
                'product_id' => $nozzle->product_id,
                'receipt_number' => 'RCPT-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6)),
                'litres' => $litres,
                'unit_price' => $price->price,
                'amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'status' => 'confirmed',
                'sold_at' => now(),
            ]);
            StockMovement::query()->create([
                'organization_id' => $tank->organization_id,
                'station_id' => $tank->station_id,
                'tank_id' => $tank->id,
                'product_id' => $tank->product_id,
                'type' => 'sale',
                'quantity_litres' => -$litres,
                'balance_after_litres' => $tank->fresh()->book_stock_litres,
                'source_type' => 'sale',
                'source_id' => $sale->id,
                'recorded_by' => $request->user()->id,
                'occurred_at' => $sale->sold_at,
                'notes' => $sale->receipt_number,
            ]);

            if ($shift && $validated['payment_method'] === 'cash') {
                $shift->increment('expected_cash', $amount);
            }
            $audit->record(
                'sale.confirmed',
                $sale,
                after: [
                    'receipt_number' => $sale->receipt_number,
                    'litres' => $litres,
                    'amount' => $amount,
                    'currency' => 'GHS',
                ],
                stationId: $stationId,
            );

            return $sale;
        });

        return response()->json([
            'data' => $this->serialize($sale->load([
                'station',
                'product',
                'nozzle.pump',
                'attendant',
            ])),
        ], 201);
    }

    public function reverse(
        Request $request,
        Sale $sale,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $sale);
        $this->requirePermission($request, 'sales.reverse', $sale->station_id);
        abort_unless($sale->status === 'confirmed', 409, 'Only a confirmed sale can be reversed.');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        DB::transaction(function () use ($sale, $request, $validated, $audit): void {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            abort_unless($lockedSale->status === 'confirmed', 409);
            $tank = Tank::query()->lockForUpdate()->findOrFail($lockedSale->tank_id);
            $tank->increment('book_stock_litres', $lockedSale->litres);
            $lockedSale->update([
                'status' => 'reversed',
                'reversed_by' => $request->user()->id,
                'reversed_at' => now(),
                'reversal_reason' => $validated['reason'],
            ]);
            StockMovement::query()->create([
                'organization_id' => $tank->organization_id,
                'station_id' => $tank->station_id,
                'tank_id' => $tank->id,
                'product_id' => $tank->product_id,
                'type' => 'sale_reversal',
                'quantity_litres' => $lockedSale->litres,
                'balance_after_litres' => $tank->fresh()->book_stock_litres,
                'source_type' => 'sale',
                'source_id' => $lockedSale->id,
                'recorded_by' => $request->user()->id,
                'occurred_at' => now(),
                'notes' => $validated['reason'],
            ]);
            $audit->record(
                'sale.reversed',
                $lockedSale,
                after: ['status' => 'reversed'],
                stationId: $lockedSale->station_id,
                reason: $validated['reason'],
            );
        });

        return response()->json([
            'data' => $this->serialize($sale->fresh()->load([
                'station',
                'product',
                'nozzle.pump',
                'attendant',
            ])),
        ]);
    }

    private function currentPrice(string $productId, string $stationId): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', $productId)
            ->where('station_id', $stationId)
            ->where('effective_from', '<=', now())
            ->latest('effective_from')
            ->first()
            ?? ProductPrice::query()
                ->where('product_id', $productId)
                ->whereNull('station_id')
                ->where('effective_from', '<=', now())
                ->latest('effective_from')
                ->first();
    }

    private function assertAccess(Request $request, Sale $sale): void
    {
        abort_unless(
            $sale->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $sale->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'station_id' => $sale->station_id,
            'station_name' => $sale->station?->name,
            'shift_id' => $sale->shift_id,
            'attendant_id' => $sale->attendant_id,
            'attendant_name' => $sale->attendant?->name,
            'nozzle_id' => $sale->nozzle_id,
            'nozzle_code' => $sale->nozzle?->code,
            'pump_name' => $sale->nozzle?->pump?->name,
            'product_id' => $sale->product_id,
            'product_name' => $sale->product?->name,
            'litres' => $sale->litres,
            'unit_price' => $sale->unit_price,
            'amount' => $sale->amount,
            'currency' => 'GHS',
            'payment_method' => $sale->payment_method,
            'payment_reference' => $sale->payment_reference,
            'status' => $sale->status,
            'sold_at' => $sale->sold_at->toIso8601String(),
            'reversal_reason' => $sale->reversal_reason,
        ];
    }
}
