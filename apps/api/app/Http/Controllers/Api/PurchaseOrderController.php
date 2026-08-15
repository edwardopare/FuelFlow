<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tank;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'procurement.purchase_orders.view');
        $query = PurchaseOrder::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with([
                'station',
                'supplier',
                'approvedBy',
                'paidBy',
                'lines.product',
                'lines.targetTank',
            ]);

        $isAccountant = $request->user()->roleAssignments()
            ->whereHas('role', fn ($role) => $role->where('slug', 'accountant'))
            ->exists();
        $canManageOrApprove = $request->user()->hasPermission('procurement.purchase_orders.create')
            || $request->user()->hasPermission('procurement.purchase_orders.approve');
        $paymentQueueRequested = $request->query('queue') === 'payment';

        if ($paymentQueueRequested) {
            $this->requirePermission($request, 'procurement.purchase_orders.pay');
        }

        if ($paymentQueueRequested || ($isAccountant && ! $canManageOrApprove)) {
            $query->whereIn('status', ['approved', 'paid']);
        }

        $orders = $query
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (PurchaseOrder $order) => $this->serialize($order));

        return response()->json(['data' => $orders]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', Rule::exists('stations', 'id')],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')],
            'expected_delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')],
            'lines.*.target_tank_id' => ['required', Rule::exists('tanks', 'id')],
            'lines.*.quantity_litres' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
        ]);
        $this->requireStationAccess($request, $validated['station_id']);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.create',
            $validated['station_id'],
        );
        $supplier = Supplier::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->findOrFail($validated['supplier_id']);

        $order = DB::transaction(function () use (
            $validated,
            $request,
            $supplier,
            $audit,
        ): PurchaseOrder {
            $subtotal = 0.0;
            $preparedLines = [];

            foreach ($validated['lines'] as $line) {
                $product = Product::query()
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('is_active', true)
                    ->findOrFail($line['product_id']);
                $tank = Tank::query()
                    ->where('station_id', $validated['station_id'])
                    ->where('product_id', $product->id)
                    ->findOrFail($line['target_tank_id']);
                $availableCapacity = (float) $tank->maximum_safe_litres
                    - (float) $tank->book_stock_litres;
                abort_if(
                    (float) $line['quantity_litres'] > $availableCapacity,
                    422,
                    "{$tank->name} has only {$availableCapacity} litres of safe capacity.",
                );
                $lineTotal = round(
                    (float) $line['quantity_litres'] * (float) $line['unit_price'],
                    2,
                );
                $subtotal += $lineTotal;
                $preparedLines[] = [
                    ...$line,
                    'product_id' => $product->id,
                    'target_tank_id' => $tank->id,
                ];
            }

            $order = PurchaseOrder::query()->create([
                'organization_id' => $request->user()->organization_id,
                'station_id' => $validated['station_id'],
                'supplier_id' => $supplier->id,
                'po_number' => 'PO-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6)),
                'status' => 'draft',
                'expected_delivery_date' => $validated['expected_delivery_date'],
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $order->lines()->createMany($preparedLines);
            $audit->record(
                'purchase_order.created',
                $order,
                after: $order->load('lines')->toArray(),
                stationId: $order->station_id,
            );

            return $order;
        });

        return response()->json([
            'data' => $this->serialize($order->load([
                'station',
                'supplier',
                'approvedBy',
                'paidBy',
                'lines.product',
                'lines.targetTank',
            ])),
        ], 201);
    }

    public function submit(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.update',
            $purchaseOrder->station_id,
        );
        $purchaseOrder = $this->transition(
            $purchaseOrder,
            'draft',
            'pending_approval',
            afterTransition: fn (PurchaseOrder $locked) => $audit->record(
                'purchase_order.submitted',
                $locked,
                after: ['status' => 'pending_approval'],
                stationId: $locked->station_id,
            ),
        );

        return $this->response($purchaseOrder);
    }

    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requireAdministrator($request);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.approve',
            $purchaseOrder->station_id,
        );
        abort_if(
            $purchaseOrder->created_by === $request->user()->id,
            403,
            'The purchase order creator cannot approve their own order.',
        );
        $purchaseOrder = $this->transition(
            $purchaseOrder,
            'pending_approval',
            'approved',
            [
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ],
            fn (PurchaseOrder $locked) => $audit->record(
                'purchase_order.approved',
                $locked,
                after: ['status' => 'approved'],
                stationId: $locked->station_id,
            ),
        );

        return $this->response($purchaseOrder);
    }

    public function reject(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requireAdministrator($request);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.approve',
            $purchaseOrder->station_id,
        );
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $purchaseOrder = $this->transition(
            $purchaseOrder,
            'pending_approval',
            'rejected',
            [
                'notes' => trim(($purchaseOrder->notes ? $purchaseOrder->notes."\n" : '').'Rejected: '.$validated['reason']),
            ],
            fn (PurchaseOrder $locked) => $audit->record(
                'purchase_order.rejected',
                $locked,
                after: ['status' => 'rejected'],
                stationId: $locked->station_id,
                reason: $validated['reason'],
            ),
        );

        return $this->response($purchaseOrder);
    }

    public function pay(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.pay',
            $purchaseOrder->station_id,
        );
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'receipt' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ]);
        $receipt = $validated['receipt'];
        $path = $receipt->store(
            'purchase-order-payment-receipts/'.$purchaseOrder->organization_id,
            'local',
        );
        abort_unless($path, 500, 'The payment receipt could not be stored.');

        try {
            $purchaseOrder = DB::transaction(function () use (
                $purchaseOrder,
                $request,
                $validated,
                $receipt,
                $path,
                $audit,
            ): PurchaseOrder {
                $locked = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($purchaseOrder->id);
                abort_unless(
                    $locked->status === 'approved',
                    409,
                    'Only an approved purchase order can be marked as paid.',
                );
                $locked->update([
                    'status' => 'paid',
                    'paid_by' => $request->user()->id,
                    'paid_at' => now(),
                    'payment_reference' => $validated['payment_reference'] ?? null,
                    'payment_receipt_path' => $path,
                    'payment_receipt_name' => $receipt->getClientOriginalName(),
                    'payment_receipt_mime' => $receipt->getMimeType(),
                    'payment_receipt_size' => $receipt->getSize(),
                ]);
                $audit->record(
                    'purchase_order.paid',
                    $locked,
                    after: [
                        'status' => 'paid',
                        'paid_by' => $request->user()->id,
                        'paid_at' => $locked->paid_at?->toIso8601String(),
                        'payment_reference' => $locked->payment_reference,
                        'payment_receipt_name' => $locked->payment_receipt_name,
                    ],
                    stationId: $locked->station_id,
                );

                return $locked;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return $this->response($purchaseOrder);
    }

    public function paymentReceipt(
        Request $request,
        PurchaseOrder $purchaseOrder,
    ): StreamedResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.view',
            $purchaseOrder->station_id,
        );
        abort_unless(
            $purchaseOrder->payment_receipt_path
                && Storage::disk('local')->exists($purchaseOrder->payment_receipt_path),
            404,
            'No payment receipt is attached to this purchase order.',
        );

        return Storage::disk('local')->download(
            $purchaseOrder->payment_receipt_path,
            $purchaseOrder->payment_receipt_name ?? 'payment-receipt',
            ['Content-Type' => $purchaseOrder->payment_receipt_mime ?? 'application/octet-stream'],
        );
    }

    public function send(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.send',
            $purchaseOrder->station_id,
        );
        $purchaseOrder = $this->transition(
            $purchaseOrder,
            'paid',
            'sent',
            ['sent_at' => now()],
            fn (PurchaseOrder $locked) => $audit->record(
                'purchase_order.sent',
                $locked,
                after: ['status' => 'sent'],
                stationId: $locked->station_id,
            ),
        );

        return $this->response($purchaseOrder);
    }

    public function cancel(
        Request $request,
        PurchaseOrder $purchaseOrder,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $purchaseOrder);
        $this->requirePermission(
            $request,
            'procurement.purchase_orders.update',
            $purchaseOrder->station_id,
        );
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $purchaseOrder = DB::transaction(function () use (
            $purchaseOrder,
            $validated,
            $audit,
        ): PurchaseOrder {
            $locked = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);
            abort_unless(
                in_array($locked->status, ['draft', 'pending_approval', 'approved'], true),
                409,
                'A sent or received purchase order cannot be cancelled.',
            );
            $locked->update([
                'status' => 'cancelled',
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'Cancelled: '.$validated['reason']),
            ]);
            $audit->record(
                'purchase_order.cancelled',
                $locked,
                after: ['status' => 'cancelled'],
                stationId: $locked->station_id,
                reason: $validated['reason'],
            );

            return $locked;
        });

        return $this->response($purchaseOrder);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        PurchaseOrder $order,
        string $from,
        string $to,
        array $extra = [],
        ?Closure $afterTransition = null,
    ): PurchaseOrder {
        return DB::transaction(function () use (
            $order,
            $from,
            $to,
            $extra,
            $afterTransition,
        ): PurchaseOrder {
            $locked = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);
            abort_unless(
                $locked->status === $from,
                409,
                "Only a {$from} purchase order can move to {$to}.",
            );
            $locked->update(['status' => $to, ...$extra]);
            $afterTransition?->__invoke($locked);

            return $locked;
        });
    }

    private function assertAccess(Request $request, PurchaseOrder $order): void
    {
        abort_unless(
            $order->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $order->station_id);
    }

    private function requireAdministrator(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->roleAssignments()
                ->where('organization_id', $user->organization_id)
                ->whereNull('station_id')
                ->whereHas(
                    'role',
                    fn ($query) => $query->where('slug', 'administrator'),
                )
                ->exists(),
            403,
            'Only an Administrator can approve or reject purchase orders.',
        );
    }

    private function response(PurchaseOrder $order): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($order->load([
                'station',
                'supplier',
                'approvedBy',
                'paidBy',
                'lines.product',
                'lines.targetTank',
            ])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'station_id' => $order->station_id,
            'station_name' => $order->station?->name,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'status' => $order->status,
            'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'notes' => $order->notes,
            'created_by' => $order->created_by,
            'approved_by' => $order->approved_by,
            'approved_by_name' => $order->approvedBy?->name,
            'approved_at' => $order->approved_at?->toIso8601String(),
            'paid_by' => $order->paid_by,
            'paid_by_name' => $order->paidBy?->name,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'payment_reference' => $order->payment_reference,
            'payment_receipt_name' => $order->payment_receipt_name,
            'payment_receipt_mime' => $order->payment_receipt_mime,
            'payment_receipt_size' => $order->payment_receipt_size,
            'payment_receipt_url' => $order->payment_receipt_path
                ? "/api/v1/purchase-orders/{$order->id}/payment-receipt"
                : null,
            'sent_at' => $order->sent_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'lines' => $order->relationLoaded('lines')
                ? $order->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'target_tank_id' => $line->target_tank_id,
                    'target_tank_name' => $line->targetTank?->name,
                    'quantity_litres' => $line->quantity_litres,
                    'unit_price' => $line->unit_price,
                    'received_quantity_litres' => $line->received_quantity_litres,
                    'line_total' => number_format(
                        (float) $line->quantity_litres * (float) $line->unit_price,
                        2,
                        '.',
                        '',
                    ),
                ])->values()
                : [],
        ];
    }
}
