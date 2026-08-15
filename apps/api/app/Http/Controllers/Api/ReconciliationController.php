<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Reconciliation;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Station;
use App\Models\Tank;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'reconciliations.view');
        $reconciliations = Reconciliation::query()
            ->whereIn('station_id', $this->authorizedStationIds($request))
            ->with('station')
            ->latest('business_date')
            ->limit(100)
            ->get()
            ->map(fn (Reconciliation $reconciliation) => $this->serialize($reconciliation));

        return response()->json(['data' => $reconciliations]);
    }

    public function generate(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', Rule::exists('stations', 'id')],
            'business_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $this->requireStationAccess($request, $validated['station_id']);
        $this->requirePermission(
            $request,
            'reconciliations.generate',
            $validated['station_id'],
        );
        $day = CarbonImmutable::parse($validated['business_date'], 'Africa/Accra');
        $start = $day->startOfDay()->utc();
        $end = $day->endOfDay()->utc();
        $sales = Sale::query()
            ->where('station_id', $validated['station_id'])
            ->where('status', 'confirmed')
            ->whereBetween('sold_at', [$start, $end]);
        $deliveries = Delivery::query()
            ->where('station_id', $validated['station_id'])
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$start, $end]);
        $tanks = Tank::query()
            ->where('station_id', $validated['station_id'])
            ->with(['readings' => fn ($query) => $query
                ->whereBetween('read_at', [$start, $end])
                ->latest('read_at')])
            ->get();
        $closingBook = (float) $tanks->sum('book_stock_litres');
        $closingDip = (float) $tanks->sum(
            fn ($tank) => (float) ($tank->readings->first()?->reading_litres
                ?? $tank->book_stock_litres),
        );
        $salesLitres = (float) (clone $sales)->sum('litres');
        $salesValue = (float) (clone $sales)->sum('amount');
        $receipts = (float) $deliveries->sum('received_quantity_litres');
        $expectedCash = (float) (clone $sales)
            ->where('payment_method', 'cash')
            ->sum('amount');
        $countedCash = (float) Shift::query()
            ->where('station_id', $validated['station_id'])
            ->whereBetween('closed_at', [$start, $end])
            ->sum('counted_cash');
        $countedCash = $countedCash ?: $expectedCash;
        $opening = $closingBook + $salesLitres - $receipts;

        $reconciliation = DB::transaction(function () use (
            $audit,
            $closingBook,
            $closingDip,
            $countedCash,
            $day,
            $expectedCash,
            $opening,
            $receipts,
            $request,
            $salesLitres,
            $salesValue,
            $validated,
        ): Reconciliation {
            Station::query()
                ->where('organization_id', $request->user()->organization_id)
                ->lockForUpdate()
                ->findOrFail($validated['station_id']);

            $reconciliation = Reconciliation::query()
                ->where('station_id', $validated['station_id'])
                ->whereDate('business_date', $day->toDateString())
                ->lockForUpdate()
                ->first();

            abort_if(
                $reconciliation
                    && ($reconciliation->locked_at || $reconciliation->status === 'reconciled'),
                409,
                'This reconciliation is locked. An administrator must reopen it before it can be regenerated.',
            );

            $attributes = [
                'organization_id' => $request->user()->organization_id,
                'status' => 'in_review',
                'opening_stock_litres' => round($opening, 3),
                'receipts_litres' => round($receipts, 3),
                'sales_litres' => round($salesLitres, 3),
                'closing_book_stock_litres' => round($closingBook, 3),
                'closing_dip_stock_litres' => round($closingDip, 3),
                'tank_variance_litres' => round($closingDip - $closingBook, 3),
                'sales_value' => round($salesValue, 2),
                'expected_cash' => round($expectedCash, 2),
                'counted_cash' => round($countedCash, 2),
                'cash_variance' => round($countedCash - $expectedCash, 2),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'locked_at' => null,
            ];

            if ($reconciliation) {
                $reconciliation->update($attributes);
            } else {
                $reconciliation = Reconciliation::query()->create([
                    'station_id' => $validated['station_id'],
                    'business_date' => $day->toDateString(),
                    ...$attributes,
                ]);
            }

            $audit->record(
                'reconciliation.generated',
                $reconciliation,
                after: $reconciliation->toArray(),
                stationId: $reconciliation->station_id,
            );

            return $reconciliation;
        });

        return response()->json([
            'data' => $this->serialize($reconciliation->load('station')),
        ], 201);
    }

    public function signOff(
        Request $request,
        Reconciliation $reconciliation,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $reconciliation);
        $this->requirePermission(
            $request,
            'reconciliations.sign_off',
            $reconciliation->station_id,
        );
        abort_unless($reconciliation->status === 'in_review', 409);
        $hasVariance = abs((float) $reconciliation->tank_variance_litres) > 0.001
            || abs((float) $reconciliation->cash_variance) > 0.001;
        $commentRules = $hasVariance
            ? ['required', 'string', 'min:10', 'max:1000']
            : ['nullable', 'string', 'max:1000'];
        $validated = $request->validate([
            'manager_comment' => $commentRules,
        ]);
        $reconciliation->update([
            'status' => 'reconciled',
            'manager_comment' => $validated['manager_comment'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'locked_at' => now(),
        ]);
        $audit->record(
            'reconciliation.signed_off',
            $reconciliation,
            after: ['status' => 'reconciled', 'locked_at' => now()->toIso8601String()],
            stationId: $reconciliation->station_id,
            reason: $validated['manager_comment'] ?? null,
        );

        return response()->json([
            'data' => $this->serialize($reconciliation->load('station')),
        ]);
    }

    public function reopen(
        Request $request,
        Reconciliation $reconciliation,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAccess($request, $reconciliation);
        $this->requirePermission(
            $request,
            'reconciliations.reopen',
            $reconciliation->station_id,
        );
        abort_unless($reconciliation->status === 'reconciled', 409);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $reconciliation->update([
            'status' => 'in_review',
            'manager_comment' => trim(($reconciliation->manager_comment ? $reconciliation->manager_comment."\n" : '').'Reopened: '.$validated['reason']),
            'locked_at' => null,
        ]);
        $audit->record(
            'reconciliation.reopened',
            $reconciliation,
            after: ['status' => 'in_review'],
            stationId: $reconciliation->station_id,
            reason: $validated['reason'],
        );

        return response()->json([
            'data' => $this->serialize($reconciliation->load('station')),
        ]);
    }

    private function assertAccess(Request $request, Reconciliation $reconciliation): void
    {
        abort_unless(
            $reconciliation->organization_id === $request->user()->organization_id,
            404,
        );
        $this->requireStationAccess($request, $reconciliation->station_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Reconciliation $reconciliation): array
    {
        return [
            ...$reconciliation->only([
                'id',
                'station_id',
                'status',
                'opening_stock_litres',
                'receipts_litres',
                'sales_litres',
                'closing_book_stock_litres',
                'closing_dip_stock_litres',
                'tank_variance_litres',
                'sales_value',
                'expected_cash',
                'counted_cash',
                'cash_variance',
                'manager_comment',
            ]),
            'station_name' => $reconciliation->station?->name,
            'business_date' => $reconciliation->business_date->toDateString(),
            'reviewed_at' => $reconciliation->reviewed_at?->toIso8601String(),
            'locked_at' => $reconciliation->locked_at?->toIso8601String(),
        ];
    }
}
