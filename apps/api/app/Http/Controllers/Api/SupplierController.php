<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'suppliers.view');

        $suppliers = Supplier::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['prices.product'])
            ->withCount('purchaseOrders')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier) => $this->serialize($supplier));

        return response()->json(['data' => $suppliers]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $this->requirePermission($request, 'suppliers.manage');
        $organizationId = $request->user()->organization_id;
        $validated = $request->validate($this->rules($organizationId));

        $supplier = Supplier::query()->create([
            ...$validated,
            'organization_id' => $organizationId,
            'is_active' => true,
        ]);
        $audit->record('supplier.created', $supplier, after: $this->serialize($supplier));

        return response()->json([
            'data' => $this->serialize($supplier->load('prices.product')),
        ], 201);
    }

    public function update(
        Request $request,
        Supplier $supplier,
        AuditService $audit,
    ): JsonResponse {
        $this->assertOrganization($request, $supplier);
        $this->requirePermission($request, 'suppliers.manage');
        $before = $this->serialize($supplier->load('prices.product'));
        $validated = $request->validate($this->rules(
            $supplier->organization_id,
            $supplier->id,
        ));
        $supplier->update($validated);
        $audit->record(
            'supplier.updated',
            $supplier,
            before: $before,
            after: $this->serialize($supplier),
        );

        return response()->json([
            'data' => $this->serialize($supplier->load('prices.product')),
        ]);
    }

    public function deactivate(
        Request $request,
        Supplier $supplier,
        AuditService $audit,
    ): JsonResponse {
        $this->assertOrganization($request, $supplier);
        $this->requirePermission($request, 'suppliers.manage');
        $supplier->update(['is_active' => false]);
        $audit->record(
            'supplier.deactivated',
            $supplier,
            after: ['is_active' => false],
        );

        return response()->json([
            'data' => $this->serialize($supplier->load('prices.product')),
        ]);
    }

    public function addPrice(
        Request $request,
        Supplier $supplier,
        AuditService $audit,
    ): JsonResponse {
        $this->assertOrganization($request, $supplier);
        $this->requirePermission($request, 'suppliers.manage');
        $validated = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')
                    ->where('organization_id', $supplier->organization_id),
            ],
            'price' => ['required', 'numeric', 'min:0.0001'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $price = $supplier->prices()->create($validated);
        $audit->record(
            'supplier.price_added',
            $supplier,
            after: $price->toArray(),
        );

        return response()->json([
            'data' => $this->serialize($supplier->load('prices.product')),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $organizationId, ?string $supplierId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('suppliers', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($supplierId),
            ],
            'name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_registration_number' => ['nullable', 'string', 'max:80'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function assertOrganization(Request $request, Supplier $supplier): void
    {
        abort_unless(
            $supplier->organization_id === $request->user()->organization_id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_name' => $supplier->contact_name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'tax_registration_number' => $supplier->tax_registration_number,
            'has_bank_details' => filled($supplier->bank_details),
            'payment_terms' => $supplier->payment_terms,
            'is_active' => $supplier->is_active,
            'on_time_percentage' => $supplier->on_time_percentage,
            'quality_rating' => $supplier->quality_rating,
            'quantity_accuracy' => $supplier->quantity_accuracy,
            'purchase_orders_count' => $supplier->purchase_orders_count ?? 0,
            'prices' => $supplier->relationLoaded('prices')
                ? $supplier->prices->map(fn ($price) => [
                    'id' => $price->id,
                    'product_id' => $price->product_id,
                    'product_name' => $price->product?->name,
                    'price' => $price->price,
                    'effective_from' => $price->effective_from?->toIso8601String(),
                    'effective_until' => $price->effective_until?->toIso8601String(),
                ])->values()
                : [],
            'created_at' => $supplier->created_at?->toIso8601String(),
        ];
    }
}
