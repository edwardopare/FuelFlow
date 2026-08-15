<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\PurchaseOrder;
use App\Models\Reconciliation;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\CarbonInterface;

class ReportDataService
{
    /**
     * @param  list<string>  $stationIds
     * @return array{columns: list<string>, rows: mixed, currency: string}
     */
    public function generate(
        string $organizationId,
        array $stationIds,
        string $type,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $includeOrganizationEvents = false,
    ): array {
        [$columns, $rows] = match ($type) {
            'daily-sales' => $this->sales($stationIds, $from, $to),
            'stock-movements' => $this->stock($stationIds, $from, $to),
            'procurement' => $this->procurement($stationIds, $from, $to),
            'reconciliation' => $this->reconciliations($stationIds, $from, $to),
            'supplier-performance' => $this->suppliers($organizationId, $stationIds),
            'shift-attendance' => $this->shiftAttendance($stationIds, $from, $to),
            'user-activity' => $this->activity(
                $organizationId,
                $stationIds,
                $from,
                $to,
                $includeOrganizationEvents,
            ),
            default => throw new \InvalidArgumentException("Unsupported report type: {$type}"),
        };

        return [
            'columns' => $columns,
            'rows' => $rows,
            'currency' => 'GHS',
        ];
    }

    /** @param list<string> $stationIds */
    private function sales(array $stationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Sale::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('sold_at', [$from, $to])
            ->with(['station', 'product', 'attendant'])
            ->latest('sold_at')
            ->get()
            ->map(fn (Sale $sale) => [
                'date' => $sale->sold_at->format('Y-m-d H:i'),
                'receipt' => $sale->receipt_number,
                'station' => $sale->station?->name,
                'product' => $sale->product?->name,
                'attendant' => $sale->attendant?->name,
                'litres' => $sale->litres,
                'amount_ghs' => $sale->amount,
                'payment' => $sale->payment_method,
                'status' => $sale->status,
            ])->values();

        return [['date', 'receipt', 'station', 'product', 'attendant', 'litres', 'amount_ghs', 'payment', 'status'], $rows];
    }

    /** @param list<string> $stationIds */
    private function stock(array $stationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = StockMovement::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->with(['tank', 'product'])
            ->latest('occurred_at')
            ->get()
            ->map(fn (StockMovement $movement) => [
                'date' => $movement->occurred_at->format('Y-m-d H:i'),
                'tank' => $movement->tank?->name,
                'product' => $movement->product?->name,
                'type' => $movement->type,
                'quantity_litres' => $movement->quantity_litres,
                'balance_litres' => $movement->balance_after_litres,
                'notes' => $movement->notes,
            ])->values();

        return [['date', 'tank', 'product', 'type', 'quantity_litres', 'balance_litres', 'notes'], $rows];
    }

    /** @param list<string> $stationIds */
    private function procurement(array $stationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = PurchaseOrder::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('created_at', [$from, $to])
            ->with(['station', 'supplier'])
            ->latest()
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'date' => $order->created_at->format('Y-m-d'),
                'po_number' => $order->po_number,
                'station' => $order->station?->name,
                'supplier' => $order->supplier?->name,
                'status' => $order->status,
                'expected_delivery' => $order->expected_delivery_date->toDateString(),
                'total_ghs' => $order->total,
            ])->values();

        return [['date', 'po_number', 'station', 'supplier', 'status', 'expected_delivery', 'total_ghs'], $rows];
    }

    /** @param list<string> $stationIds */
    private function reconciliations(array $stationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Reconciliation::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->with('station')
            ->latest('business_date')
            ->get()
            ->map(fn (Reconciliation $item) => [
                'date' => $item->business_date->toDateString(),
                'station' => $item->station?->name,
                'status' => $item->status,
                'sales_litres' => $item->sales_litres,
                'sales_value_ghs' => $item->sales_value,
                'tank_variance_litres' => $item->tank_variance_litres,
                'cash_variance_ghs' => $item->cash_variance,
            ])->values();

        return [['date', 'station', 'status', 'sales_litres', 'sales_value_ghs', 'tank_variance_litres', 'cash_variance_ghs'], $rows];
    }

    /** @param list<string> $stationIds */
    private function suppliers(string $organizationId, array $stationIds): array
    {
        $rows = Supplier::query()
            ->where('organization_id', $organizationId)
            ->withCount([
                'purchaseOrders' => fn ($query) => $query->whereIn('station_id', $stationIds),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier) => [
                'code' => $supplier->code,
                'supplier' => $supplier->name,
                'purchase_orders' => $supplier->purchase_orders_count,
                'on_time_percent' => $supplier->on_time_percentage,
                'quantity_accuracy_percent' => $supplier->quantity_accuracy,
                'quality_rating' => $supplier->quality_rating,
                'status' => $supplier->is_active ? 'active' : 'inactive',
            ])->values();

        return [['code', 'supplier', 'purchase_orders', 'on_time_percent', 'quantity_accuracy_percent', 'quality_rating', 'status'], $rows];
    }

    /** @param list<string> $stationIds */
    private function shiftAttendance(array $stationIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Shift::query()
            ->whereIn('station_id', $stationIds)
            ->whereBetween('scheduled_start', [$from, $to])
            ->with(['station', 'attendant', 'pump'])
            ->latest('scheduled_start')
            ->get()
            ->map(function (Shift $shift): array {
                $workedSeconds = $shift->status !== 'scheduled' && $shift->opened_at
                    ? max(
                        0,
                        ($shift->closed_at ?? now())->getTimestamp()
                            - $shift->opened_at->getTimestamp(),
                    )
                    : 0;
                $attendance = $shift->status === 'scheduled' || ! $shift->opened_at
                    ? 'not_started'
                    : ((int) $shift->late_seconds > 0 ? 'late' : 'on_time');
                $overtimeSeconds = $shift->status === 'open'
                    ? max(
                        0,
                        now()->getTimestamp()
                            - $shift->scheduled_end->getTimestamp(),
                    )
                    : (int) $shift->overtime_seconds;

                return [
                    'date' => $shift->scheduled_start->toDateString(),
                    'shift' => $shift->code,
                    'station' => $shift->station?->name,
                    'attendant' => $shift->attendant?->name,
                    'pump' => $shift->pump?->name,
                    'scheduled_start' => $shift->scheduled_start->toIso8601String(),
                    'actual_start' => $shift->opened_at?->toIso8601String(),
                    'scheduled_end' => $shift->scheduled_end->toIso8601String(),
                    'actual_end' => $shift->closed_at?->toIso8601String(),
                    'attendance' => $attendance,
                    'late_minutes' => round((int) $shift->late_seconds / 60, 1),
                    'overtime_minutes' => round($overtimeSeconds / 60, 1),
                    'worked_minutes' => round($workedSeconds / 60, 1),
                    'status' => $shift->status,
                ];
            })
            ->values();

        return [[
            'date',
            'shift',
            'station',
            'attendant',
            'pump',
            'scheduled_start',
            'actual_start',
            'scheduled_end',
            'actual_end',
            'attendance',
            'late_minutes',
            'overtime_minutes',
            'worked_minutes',
            'status',
        ], $rows];
    }

    /** @param list<string> $stationIds */
    private function activity(
        string $organizationId,
        array $stationIds,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $includeOrganizationEvents,
    ): array {
        $rows = AuditEvent::query()
            ->where('organization_id', $organizationId)
            ->where(
                fn ($query) => $query
                    ->whereIn('station_id', $stationIds)
                    ->when(
                        $includeOrganizationEvents,
                        fn ($scope) => $scope->orWhereNull('station_id'),
                    ),
            )
            ->whereBetween('created_at', [$from, $to])
            ->with('actor')
            ->latest()
            ->limit(1000)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'date' => $event->created_at->format('Y-m-d H:i'),
                'user' => $event->actor?->name ?? 'System',
                'action' => $event->action,
                'station_id' => $event->station_id,
                'reason' => $event->reason,
                'request_id' => $event->request_id,
            ])->values();

        return [['date', 'user', 'action', 'station_id', 'reason', 'request_id'], $rows];
    }
}
