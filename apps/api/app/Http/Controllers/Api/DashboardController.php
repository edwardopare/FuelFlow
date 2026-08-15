<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\Reconciliation;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Tank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('dashboard.portfolio.view')
                || $request->user()->hasPermission('dashboard.station.view')
                || $request->user()->hasPermission('dashboard.shift.view'),
            403,
        );

        $stationIds = $this->authorizedStationIds($request);
        $sales = Sale::query()
            ->whereIn('station_id', $stationIds)
            ->where('status', 'confirmed')
            ->whereDate('sold_at', today());
        $tanks = Tank::query()
            ->whereIn('station_id', $stationIds)
            ->with(['station', 'product'])
            ->orderBy('book_stock_litres')
            ->get();
        $recentSales = Sale::query()
            ->whereIn('station_id', $stationIds)
            ->where('status', 'confirmed')
            ->with(['station', 'product'])
            ->latest('sold_at')
            ->limit(8)
            ->get();
        $salesByProduct = (clone $sales)
            ->selectRaw('product_id, SUM(litres) as litres, SUM(amount) as amount')
            ->with('product')
            ->groupBy('product_id')
            ->get();
        $salesByAttendant = (clone $sales)
            ->selectRaw('station_id, attendant_id, SUM(amount) as amount')
            ->with('attendant:id,name')
            ->groupBy('station_id', 'attendant_id')
            ->get()
            ->keyBy(fn (Sale $sale) => $sale->station_id.'|'.$sale->attendant_id);
        $shiftsByAttendant = Shift::query()
            ->whereIn('station_id', $stationIds)
            ->whereDate('scheduled_start', today())
            ->with('attendant:id,name')
            ->orderBy('scheduled_start')
            ->get()
            ->groupBy(fn (Shift $shift) => $shift->station_id.'|'.$shift->attendant_id);
        $dailyAttendantSales = $salesByAttendant->keys()
            ->merge($shiftsByAttendant->keys())
            ->unique()
            ->map(function (string $key) use ($salesByAttendant, $shiftsByAttendant): array {
                /** @var Sale|null $saleTotal */
                $saleTotal = $salesByAttendant->get($key);
                $attendantShifts = $shiftsByAttendant->get($key, collect());
                /** @var Shift|null $firstShift */
                $firstShift = $attendantShifts->first();
                $startedAt = $attendantShifts
                    ->pluck('opened_at')
                    ->filter()
                    ->sortBy(fn ($timestamp) => $timestamp->getTimestamp())
                    ->first();
                $closedAt = $attendantShifts
                    ->pluck('closed_at')
                    ->filter()
                    ->sortBy(fn ($timestamp) => $timestamp->getTimestamp())
                    ->last();

                return [
                    'date' => today()->toDateString(),
                    'attendant_id' => $saleTotal?->attendant_id ?? $firstShift?->attendant_id,
                    'attendant_name' => $saleTotal?->attendant?->name
                        ?? $firstShift?->attendant?->name
                        ?? 'Unknown attendant',
                    'started_at' => $startedAt?->toIso8601String(),
                    'closed_at' => $closedAt?->toIso8601String(),
                    'amount' => round((float) ($saleTotal?->amount ?? 0), 2),
                ];
            })
            ->sortBy('attendant_name')
            ->values();

        return response()->json([
            'data' => [
                'currency' => 'GHS',
                'business_date' => today()->toDateString(),
                'sales_value' => round((float) (clone $sales)->sum('amount'), 2),
                'sales_litres' => round((float) (clone $sales)->sum('litres'), 3),
                'transactions' => (clone $sales)->count(),
                'authorized_stations' => count($stationIds),
                'pending_purchase_orders' => PurchaseOrder::query()
                    ->whereIn('station_id', $stationIds)
                    ->whereIn('status', ['draft', 'pending_approval', 'approved', 'paid', 'sent', 'partially_received'])
                    ->count(),
                'pending_deliveries' => Delivery::query()
                    ->whereIn('station_id', $stationIds)
                    ->whereIn('status', ['pending_confirmation', 'pending_signoff'])
                    ->count(),
                'low_tanks' => $tanks->filter(
                    fn (Tank $tank) => (float) $tank->book_stock_litres
                        <= (float) $tank->minimum_safe_litres,
                )->count(),
                'total_stock_litres' => round((float) $tanks->sum('book_stock_litres'), 3),
                'tanks' => $tanks->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'name' => $tank->name,
                    'station_name' => $tank->station?->name,
                    'product_name' => $tank->product?->name,
                    'book_stock_litres' => $tank->book_stock_litres,
                    'capacity_litres' => $tank->capacity_litres,
                    'stock_percentage' => round(
                        (float) $tank->book_stock_litres
                            / max((float) $tank->capacity_litres, 1) * 100,
                        1,
                    ),
                ])->values(),
                'sales_by_product' => $salesByProduct->map(fn ($row) => [
                    'product_name' => $row->product?->name,
                    'litres' => $row->litres,
                    'amount' => $row->amount,
                ])->values(),
                'recent_sales' => $recentSales->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'station_name' => $sale->station?->name,
                    'product_name' => $sale->product?->name,
                    'litres' => $sale->litres,
                    'amount' => $sale->amount,
                    'sold_at' => $sale->sold_at->toIso8601String(),
                ])->values(),
                'daily_attendant_sales' => $dailyAttendantSales,
                'latest_reconciliation' => Reconciliation::query()
                    ->whereIn('station_id', $stationIds)
                    ->latest('business_date')
                    ->first()?->only([
                        'business_date',
                        'status',
                        'tank_variance_litres',
                        'cash_variance',
                    ]),
                'supplier_count' => Supplier::query()
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('is_active', true)
                    ->count(),
            ],
        ]);
    }
}
