<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'receiving.deliveries.view');
        $deliveries = Delivery::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with(['purchaseOrder.supplier', 'product', 'tank'])
            ->latest('arrival_time')
            ->limit(100)
            ->get()
            ->map(fn (Delivery $delivery) => $this->serialize($delivery));

        return response()->json(['data' => $deliveries]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => ['required', Rule::exists('purchase_orders', 'id')],
            'purchase_order_line_id' => ['required', Rule::exists('purchase_order_lines', 'id')],
            'tank_id' => ['required', Rule::exists('tanks', 'id')],
            'truck_number' => ['required', 'string', 'max:80'],
            'driver_name' => ['required', 'string', 'max:120'],
            'waybill_number' => ['required', 'string', 'max:120'],
            'arrival_time' => ['required', 'date'],
            'departure_time' => ['nullable', 'date', 'after_or_equal:arrival_time'],
            'invoiced_quantity_litres' => ['required', 'numeric', 'gt:0'],
            'pre_dip_litres' => ['required', 'numeric', 'min:0'],
            'post_dip_litres' => ['required', 'numeric', 'gt:pre_dip_litres'],
            'variance_comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = PurchaseOrder::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($validated['purchase_order_id']);
        $this->requireStationAccess($request, $order->station_id);
        $this->requirePermission($request, 'receiving.deliveries.create', $order->station_id);
        abort_unless(
            in_array($order->status, ['sent', 'partially_received'], true),
            409,
            'Only a sent purchase order can be received.',
        );
        $line = PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->id)
            ->findOrFail($validated['purchase_order_line_id']);
        $tank = Tank::query()
            ->where('station_id', $order->station_id)
            ->findOrFail($validated['tank_id']);
        abort_unless(
            $line->product_id === $tank->product_id,
            422,
            'The delivered product does not match the target tank product.',
        );

        $received = round(
            (float) $validated['post_dip_litres'] - (float) $validated['pre_dip_litres'],
            3,
        );
        $variance = round(
            (($received - (float) $validated['invoiced_quantity_litres'])
                / (float) $validated['invoiced_quantity_litres']) * 100,
            4,
        );
        abort_if(
            (float) $tank->book_stock_litres + $received
                > (float) $tank->maximum_safe_litres,
            422,
            'The delivery would exceed the tank maximum safe level.',
        );

        $delivery = Delivery::query()->create([
            ...$validated,
            'organization_id' => $order->organization_id,
            'station_id' => $order->station_id,
            'product_id' => $line->product_id,
            'received_quantity_litres' => $received,
            'variance_percentage' => $variance,
            'status' => abs($variance) > 0.5 ? 'pending_signoff' : 'pending_confirmation',
        ]);
        $audit->record(
            'delivery.recorded',
            $delivery,
            after: $delivery->toArray(),
            stationId: $delivery->station_id,
        );

        return response()->json([
            'data' => $this->serialize($delivery->load(['purchaseOrder.supplier', 'product', 'tank'])),
        ], 201);
    }

    public function confirm(
        Request $request,
        Delivery $delivery,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $delivery);
        $this->requirePermission($request, 'receiving.deliveries.confirm', $delivery->station_id);
        abort_unless(
            in_array($delivery->status, ['pending_confirmation', 'pending_signoff'], true),
            409,
            'This delivery has already been confirmed or cannot be confirmed.',
        );
        $validated = $request->validate([
            'variance_comment' => [
                $delivery->status === 'pending_signoff' ? 'required' : 'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($delivery->status === 'pending_signoff') {
            $this->requirePermission(
                $request,
                'receiving.variances.sign_off',
                $delivery->station_id,
            );
        }

        DB::transaction(function () use ($delivery, $request, $validated, $audit): void {
            $lockedDelivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_unless(
                in_array($lockedDelivery->status, ['pending_confirmation', 'pending_signoff'], true)
                    && ! $lockedDelivery->confirmed_at,
                409,
                'Delivery is already confirmed or cannot be confirmed.',
            );
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail(
                $lockedDelivery->purchase_order_id,
            );
            abort_unless(
                in_array($order->status, ['sent', 'partially_received'], true),
                409,
                'The purchase order is no longer available for receiving.',
            );
            $line = PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->id)
                ->lockForUpdate()
                ->findOrFail($lockedDelivery->purchase_order_line_id);
            $tank = Tank::query()
                ->where('station_id', $order->station_id)
                ->lockForUpdate()
                ->findOrFail($lockedDelivery->tank_id);
            abort_unless(
                $line->product_id === $tank->product_id
                    && $lockedDelivery->product_id === $line->product_id,
                422,
                'The delivered product does not match the purchase order line and target tank.',
            );
            $quantity = (float) $lockedDelivery->received_quantity_litres;
            $remainingQuantity = max(
                0,
                round(
                    (float) $line->quantity_litres
                        - (float) $line->received_quantity_litres,
                    3,
                ),
            );
            abort_if(
                $quantity > $remainingQuantity,
                422,
                'The delivery quantity exceeds the remaining purchase order line quantity.',
            );
            abort_if(
                (float) $tank->book_stock_litres + $quantity
                    > (float) $tank->maximum_safe_litres,
                422,
                'The delivery would exceed the tank maximum safe level.',
            );
            $tank->increment('book_stock_litres', $quantity);
            $line->increment('received_quantity_litres', $quantity);
            $lockedDelivery->update([
                'status' => 'confirmed',
                'variance_comment' => $validated['variance_comment'] ?? $lockedDelivery->variance_comment,
                'signed_off_by' => $lockedDelivery->status === 'pending_signoff'
                    ? $request->user()->id
                    : null,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
                'departure_time' => $lockedDelivery->departure_time ?? now(),
            ]);
            StockMovement::query()->create([
                'organization_id' => $tank->organization_id,
                'station_id' => $tank->station_id,
                'tank_id' => $tank->id,
                'product_id' => $tank->product_id,
                'type' => 'receipt',
                'quantity_litres' => $quantity,
                'balance_after_litres' => $tank->fresh()->book_stock_litres,
                'source_type' => 'delivery',
                'source_id' => $lockedDelivery->id,
                'recorded_by' => $request->user()->id,
                'occurred_at' => now(),
                'notes' => "Waybill {$lockedDelivery->waybill_number}",
            ]);

            $order->load('lines');
            $fullyReceived = $order->lines->every(
                fn ($orderLine) => (float) $orderLine->fresh()->received_quantity_litres
                    >= (float) $orderLine->quantity_litres,
            );
            $order->update(['status' => $fullyReceived ? 'received' : 'partially_received']);
            $audit->record(
                'delivery.confirmed',
                $lockedDelivery,
                after: [
                    'status' => 'confirmed',
                    'tank_balance' => $tank->fresh()->book_stock_litres,
                ],
                stationId: $lockedDelivery->station_id,
                reason: $validated['variance_comment'] ?? null,
            );
        });

        return response()->json([
            'data' => $this->serialize($delivery->fresh()->load([
                'purchaseOrder.supplier',
                'product',
                'tank',
            ])),
        ]);
    }

    private function assertAccess(Request $request, Delivery $delivery): void
    {
        abort_unless(
            $delivery->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $delivery->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'purchase_order_id' => $delivery->purchase_order_id,
            'purchase_order_line_id' => $delivery->purchase_order_line_id,
            'po_number' => $delivery->purchaseOrder?->po_number,
            'supplier_name' => $delivery->purchaseOrder?->supplier?->name,
            'station_id' => $delivery->station_id,
            'tank_id' => $delivery->tank_id,
            'tank_name' => $delivery->tank?->name,
            'product_id' => $delivery->product_id,
            'product_name' => $delivery->product?->name,
            'status' => $delivery->status,
            'truck_number' => $delivery->truck_number,
            'driver_name' => $delivery->driver_name,
            'waybill_number' => $delivery->waybill_number,
            'arrival_time' => $delivery->arrival_time->toIso8601String(),
            'departure_time' => $delivery->departure_time?->toIso8601String(),
            'invoiced_quantity_litres' => $delivery->invoiced_quantity_litres,
            'pre_dip_litres' => $delivery->pre_dip_litres,
            'post_dip_litres' => $delivery->post_dip_litres,
            'received_quantity_litres' => $delivery->received_quantity_litres,
            'variance_percentage' => $delivery->variance_percentage,
            'variance_comment' => $delivery->variance_comment,
            'confirmed_at' => $delivery->confirmed_at?->toIso8601String(),
        ];
    }
}
