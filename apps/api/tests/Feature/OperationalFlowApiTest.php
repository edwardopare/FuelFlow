<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\Reconciliation;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_users_can_complete_the_fuel_order_to_reconciliation_flow(): void
    {
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Test Fuel Company',
            'slug' => 'test-fuel-company',
            'currency' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'code' => 'ACC-T01',
            'name' => 'Accra Test Station',
            'timezone' => 'Africa/Accra',
            'is_active' => true,
        ]);
        $administrator = $this->userWithRole(
            $organization,
            $station,
            'administrator',
            null,
        );
        $stationManager = $this->userWithRole(
            $organization,
            $station,
            'station_manager',
            $station->id,
        );
        $owner = $this->userWithRole($organization, $station, 'owner', null);
        $accountant = $this->userWithRole(
            $organization,
            $station,
            'accountant',
            $station->id,
        );
        Sanctum::actingAs($administrator);

        $product = $this->postJson('/api/v1/products', [
            'code' => 'PMS',
            'name' => 'Premium Petrol',
            'unit' => 'litres',
            'tank_grade' => 'Petrol',
            'initial_price' => 15.49,
            'effective_from' => now()->subMinute()->toIso8601String(),
            'reason' => 'Initial controlled test price.',
        ])->assertCreated()->json('data');

        $supplier = $this->postJson('/api/v1/suppliers', [
            'code' => 'BDC-01',
            'name' => 'Test Bulk Distributor',
            'contact_name' => 'Supply Desk',
            'email' => 'supply@example.test',
            'phone' => '+233200000001',
            'address' => 'Accra',
            'tax_registration_number' => 'TIN-001',
            'bank_details' => 'Encrypted test account',
            'payment_terms' => 'Net 14 days',
        ])->assertCreated()->json('data');

        $tank = $this->postJson('/api/v1/tanks', [
            'station_id' => $station->id,
            'product_id' => $product['id'],
            'code' => 'T-01',
            'name' => 'Petrol Tank',
            'tank_grade' => 'Petrol',
            'capacity_litres' => 10000,
            'book_stock_litres' => 2000,
            'minimum_safe_litres' => 1000,
            'maximum_safe_litres' => 9000,
            'atg_enabled' => true,
        ])->assertCreated()->json('data');

        $pump = $this->postJson('/api/v1/pumps', [
            'station_id' => $station->id,
            'code' => 'P-01',
            'name' => 'Pump 1',
            'nozzles' => [[
                'code' => 'N-01',
                'tank_id' => $tank['id'],
                'current_meter_reading' => 1000,
                'meter_maximum' => 999999,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/sales', [
            'nozzle_id' => $pump['nozzles'][0]['id'],
            'litres' => 10,
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '154.90')
            ->assertJsonPath('data.currency', 'GHS');

        Sanctum::actingAs($stationManager);
        $order = $this->postJson('/api/v1/purchase-orders', [
            'station_id' => $station->id,
            'supplier_id' => $supplier['id'],
            'expected_delivery_date' => now()->addDay()->toDateString(),
            'notes' => 'Test replenishment order.',
            'lines' => [[
                'product_id' => $product['id'],
                'target_tank_id' => $tank['id'],
                'quantity_litres' => 500,
                'unit_price' => 13.9,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchase-orders/{$order['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
        Sanctum::actingAs($accountant);
        $this->getJson('/api/v1/purchase-orders?queue=payment')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")
            ->assertForbidden();
        Sanctum::actingAs($administrator);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($stationManager);
        $this->post("/api/v1/purchase-orders/{$order['id']}/pay", [
            'receipt' => UploadedFile::fake()->create(
                'unauthorized-receipt.pdf',
                64,
                'application/pdf',
            ),
        ])->assertForbidden();
        $rejectedOrder = $this->postJson('/api/v1/purchase-orders', [
            'station_id' => $station->id,
            'supplier_id' => $supplier['id'],
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'notes' => 'Alternative replenishment order for rejection coverage.',
            'lines' => [[
                'product_id' => $product['id'],
                'target_tank_id' => $tank['id'],
                'quantity_litres' => 100,
                'unit_price' => 13.9,
            ]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-orders/{$rejectedOrder['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/purchase-orders/{$rejectedOrder['id']}/reject", [
            'reason' => 'The proposed order exceeds the approved station purchasing plan.',
        ])->assertForbidden();
        Sanctum::actingAs($administrator);
        $this->postJson("/api/v1/purchase-orders/{$rejectedOrder['id']}/reject", [
            'reason' => 'The proposed order exceeds the approved station purchasing plan.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        Sanctum::actingAs($administrator);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/send")
            ->assertConflict();
        Sanctum::actingAs($accountant);
        $this->getJson('/api/v1/purchase-orders?queue=payment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order['id']);
        $this->post("/api/v1/purchase-orders/{$order['id']}/pay", [
            'payment_reference' => 'GCB-TRX-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $paidOrder = $this->post("/api/v1/purchase-orders/{$order['id']}/pay", [
            'payment_reference' => 'GCB-TRX-0001',
            'receipt' => UploadedFile::fake()->create(
                'payment-receipt.pdf',
                64,
                'application/pdf',
            ),
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_reference', 'GCB-TRX-0001')
            ->assertJsonPath('data.payment_receipt_name', 'payment-receipt.pdf')
            ->json('data');
        Storage::disk('local')->assertExists(
            PurchaseOrder::query()
                ->findOrFail($order['id'])
                ->payment_receipt_path,
        );
        $this->get($paidOrder['payment_receipt_url'])
            ->assertOk()
            ->assertHeader('content-disposition');
        Sanctum::actingAs($stationManager);
        $this->postJson("/api/v1/purchase-orders/{$order['id']}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        Sanctum::actingAs($administrator);
        $delivery = $this->postJson('/api/v1/deliveries', [
            'purchase_order_id' => $order['id'],
            'purchase_order_line_id' => $order['lines'][0]['id'],
            'tank_id' => $tank['id'],
            'truck_number' => 'GT-1000-26',
            'driver_name' => 'Test Driver',
            'waybill_number' => 'WB-001',
            'arrival_time' => now()->toIso8601String(),
            'invoiced_quantity_litres' => 400,
            'pre_dip_litres' => 1990,
            'post_dip_litres' => 2390,
        ])->assertCreated()->json('data');
        $excessDelivery = $this->postJson('/api/v1/deliveries', [
            'purchase_order_id' => $order['id'],
            'purchase_order_line_id' => $order['lines'][0]['id'],
            'tank_id' => $tank['id'],
            'truck_number' => 'GT-1001-26',
            'driver_name' => 'Excess Delivery Driver',
            'waybill_number' => 'WB-002',
            'arrival_time' => now()->toIso8601String(),
            'invoiced_quantity_litres' => 200,
            'pre_dip_litres' => 2390,
            'post_dip_litres' => 2590,
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/deliveries/{$delivery['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/deliveries/{$excessDelivery['id']}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The delivery quantity exceeds the remaining purchase order line quantity.',
            );

        $finalDelivery = $this->postJson('/api/v1/deliveries', [
            'purchase_order_id' => $order['id'],
            'purchase_order_line_id' => $order['lines'][0]['id'],
            'tank_id' => $tank['id'],
            'truck_number' => 'GT-1002-26',
            'driver_name' => 'Final Delivery Driver',
            'waybill_number' => 'WB-003',
            'arrival_time' => now()->toIso8601String(),
            'invoiced_quantity_litres' => 100,
            'pre_dip_litres' => 2390,
            'post_dip_litres' => 2490,
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/deliveries/{$finalDelivery['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order['id'],
            'status' => 'received',
        ]);

        $reconciliation = $this->postJson('/api/v1/reconciliations/generate', [
            'station_id' => $station->id,
            'business_date' => today()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.sales_value', '154.90')
            ->json('data');
        $this->postJson("/api/v1/reconciliations/{$reconciliation['id']}/sign-off")
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        Sanctum::actingAs($stationManager);
        $this->postJson('/api/v1/reconciliations/generate', [
            'station_id' => $station->id,
            'business_date' => today()->toDateString(),
        ])->assertConflict()->assertJsonPath(
            'message',
            'This reconciliation is locked. An administrator must reopen it before it can be regenerated.',
        );
        $lockedReconciliation = Reconciliation::query()->findOrFail($reconciliation['id']);
        $this->assertSame('reconciled', $lockedReconciliation->status);
        $this->assertNotNull($lockedReconciliation->locked_at);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.currency', 'GHS');
        $this->getJson(
            '/api/v1/reports?type=daily-sales&from='.today()->toDateString().'&to='.today()->toDateString(),
        )->assertOk()->assertJsonCount(1, 'data.rows');

        $this->postJson('/api/v1/report-schedules', [
            'name' => 'Daily sales pack',
            'report_type' => 'daily-sales',
            'frequency' => 'daily',
            'send_time' => '06:00',
            'recipients' => ['finance@example.test'],
        ])->assertCreated();
        $this->putJson('/api/v1/settings', [
            'session_timeout_minutes' => 1440,
            'receiving_variance_tolerance_percent' => 0.5,
            'pump_variance_tolerance_percent' => 0.5,
            'cash_variance_tolerance_ghs' => 0,
            'low_stock_alert_percent' => 20,
            'report_timezone' => 'Africa/Accra',
        ])->assertOk()->assertJsonPath('data.settings.currency', 'GHS');

        $this->assertDatabaseHas('stock_movements', ['type' => 'sale']);
        $this->assertDatabaseHas('stock_movements', ['type' => 'receipt']);
        $this->assertDatabaseHas('audit_events', ['action' => 'delivery.confirmed']);
    }

    private function userWithRole(
        Organization $organization,
        Station $station,
        string $roleSlug,
        ?string $stationId,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => UserStatus::Active,
        ]);
        $user->stations()->attach($station->id, ['is_primary' => true]);
        $user->roleAssignments()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'organization_id' => $organization->id,
            'station_id' => $stationId,
        ]);

        return $user;
    }
}
