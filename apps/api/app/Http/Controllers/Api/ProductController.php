<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'products.view');
        $stationId = $request->query('station_id');

        if ($stationId) {
            $this->requireStationAccess($request, $stationId);
        }

        $products = Product::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['prices' => fn ($query) => $query->orderByDesc('effective_from')])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => $this->serialize($product, $stationId));

        return response()->json(['data' => $products]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $this->requirePermission($request, 'products.manage');
        $organizationId = $request->user()->organization_id;
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('products', 'code')
                    ->where('organization_id', $organizationId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', Rule::in(['litres'])],
            'tank_grade' => ['nullable', 'string', 'max:80'],
            'initial_price' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $product = DB::transaction(function () use (
            $validated,
            $organizationId,
            $request,
            $audit,
        ): Product {
            $product = Product::query()->create([
                'organization_id' => $organizationId,
                'code' => $validated['code'],
                'name' => $validated['name'],
                'unit' => $validated['unit'],
                'tank_grade' => $validated['tank_grade'] ?? null,
                'is_active' => true,
            ]);
            $product->prices()->create([
                'station_id' => null,
                'price' => $validated['initial_price'],
                'effective_from' => $validated['effective_from'],
                'reason' => $validated['reason'],
                'changed_by' => $request->user()->id,
            ]);
            $audit->record('product.created', $product, after: $product->toArray());

            return $product;
        });

        return response()->json([
            'data' => $this->serialize($product->load('prices')),
        ], 201);
    }

    public function setPrice(
        Request $request,
        Product $product,
        AuditService $audit,
    ): JsonResponse {
        $this->assertOrganization($request, $product);
        $this->requirePermission($request, 'products.manage');
        $validated = $request->validate([
            'station_id' => [
                'nullable',
                Rule::exists('stations', 'id')
                    ->where('organization_id', $product->organization_id),
            ],
            'price' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($validated['station_id'] ?? null) {
            $this->requireStationAccess($request, $validated['station_id']);
        }

        $price = $product->prices()->create([
            ...$validated,
            'changed_by' => $request->user()->id,
        ]);
        $audit->record('product.price_changed', $product, after: $price->toArray());

        return response()->json([
            'data' => $this->serialize($product->load('prices'), $validated['station_id'] ?? null),
        ], 201);
    }

    public function deactivate(
        Request $request,
        Product $product,
        AuditService $audit,
    ): JsonResponse {
        $this->assertOrganization($request, $product);
        $this->requirePermission($request, 'products.manage');
        $product->update(['is_active' => false]);
        $audit->record('product.deactivated', $product, after: ['is_active' => false]);

        return response()->json([
            'data' => $this->serialize($product->load('prices')),
        ]);
    }

    private function assertOrganization(Request $request, Product $product): void
    {
        abort_unless(
            $product->organization_id === $request->user()->organization_id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Product $product, ?string $stationId = null): array
    {
        $eligiblePrices = $product->relationLoaded('prices')
            ? $product->prices
                ->filter(fn ($price) => $price->effective_from->lte(now()))
            : collect();
        $currentPrice = $stationId
            ? $eligiblePrices->firstWhere('station_id', $stationId)
                ?? $eligiblePrices->firstWhere('station_id', null)
            : $eligiblePrices->firstWhere('station_id', null)
                ?? $eligiblePrices->first();

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'unit' => $product->unit,
            'tank_grade' => $product->tank_grade,
            'is_active' => $product->is_active,
            'current_price' => $currentPrice?->price,
            'price_effective_from' => $currentPrice?->effective_from?->toIso8601String(),
            'prices' => $eligiblePrices->map(fn ($price) => [
                'id' => $price->id,
                'station_id' => $price->station_id,
                'price' => $price->price,
                'effective_from' => $price->effective_from->toIso8601String(),
                'reason' => $price->reason,
            ])->values(),
            'created_at' => $product->created_at?->toIso8601String(),
        ];
    }
}
