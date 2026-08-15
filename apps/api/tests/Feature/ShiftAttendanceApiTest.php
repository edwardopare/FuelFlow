<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Nozzle;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Pump;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Station;
use App\Models\Tank;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendant_start_and_end_records_lateness_overtime_and_manager_report(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-08 08:15:00', 'Africa/Accra'));
        $this->seed(RolePermissionSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'Shift Test Fuel Company',
            'slug' => 'shift-test-fuel-company',
            'currency' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $station = Station::query()->create([
            'organization_id' => $organization->id,
            'code' => 'ACC-S01',
            'name' => 'Accra Shift Test Station',
            'timezone' => 'Africa/Accra',
            'is_active' => true,
        ]);
        $stationManager = $this->userWithRole(
            $organization,
            $station,
            'station_manager',
        );
        $attendant = $this->userWithRole(
            $organization,
            $station,
            'cashier_attendant',
        );
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'code' => 'PMS-SHIFT',
            'name' => 'Shift Test Petrol',
            'unit' => 'litres',
            'tank_grade' => 'Petrol',
            'is_active' => true,
        ]);
        $tank = Tank::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'product_id' => $product->id,
            'code' => 'T-SHIFT',
            'name' => 'Shift Test Tank',
            'tank_grade' => 'Petrol',
            'capacity_litres' => 10000,
            'book_stock_litres' => 5000,
            'minimum_safe_litres' => 1000,
            'maximum_safe_litres' => 9000,
            'atg_enabled' => true,
            'status' => 'active',
        ]);
        $pump = Pump::query()->create([
            'organization_id' => $organization->id,
            'station_id' => $station->id,
            'code' => 'P-SHIFT',
            'name' => 'Shift Test Pump',
            'status' => 'operational',
        ]);
        $nozzle = Nozzle::query()->create([
            'pump_id' => $pump->id,
            'tank_id' => $tank->id,
            'product_id' => $product->id,
            'code' => 'N-SHIFT',
            'status' => 'operational',
            'current_meter_reading' => 1000,
            'meter_maximum' => 999999,
        ]);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'station_id' => $station->id,
            'price' => 15,
            'effective_from' => now()->subDay(),
            'reason' => 'Shift sales test price.',
            'changed_by' => $stationManager->id,
        ]);

        Sanctum::actingAs($stationManager);
        $shiftResponse = $this->postJson('/api/v1/shifts', [
            'station_id' => $station->id,
            'attendant_id' => $attendant->id,
            'pump_id' => $pump->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->addDays(2)->toDateString(),
            'start_time' => now()->subMinutes(15)->format('H:i'),
            'end_time' => now()->addMinutes(45)->format('H:i'),
            'notes' => 'Attendance timing test.',
        ])->assertCreated()
            ->assertJsonPath('meta.created_count', 3)
            ->json('data');
        $shift = $shiftResponse;
        $recurringShifts = Shift::query()
            ->where('recurrence_id', $shift['recurrence_id'])
            ->orderBy('scheduled_start')
            ->get();
        $this->assertCount(3, $recurringShifts);
        $this->assertSame(
            ['08:00', '08:00', '08:00'],
            $recurringShifts->map(fn (Shift $record) => $record->scheduled_start->format('H:i'))->all(),
        );
        $this->assertSame(
            ['09:00', '09:00', '09:00'],
            $recurringShifts->map(fn (Shift $record) => $record->scheduled_end->format('H:i'))->all(),
        );

        $this->postJson('/api/v1/sales/quote', [
            'nozzle_id' => $nozzle->id,
            'litres' => 10,
        ])->assertForbidden();
        $this->postJson('/api/v1/sales', [
            'nozzle_id' => $nozzle->id,
            'shift_id' => $shift['id'],
            'litres' => 10,
            'payment_method' => 'cash',
        ])->assertForbidden();

        // A reset demo shift can retain an earlier opening reading. Starting the
        // scheduled shift must replace it instead of violating the unique key.
        Shift::query()->findOrFail($shift['id'])->meterReadings()->create([
            'nozzle_id' => $nozzle->id,
            'type' => 'opening',
            'reading' => 995,
            'recorded_by' => $stationManager->id,
            'recorded_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($attendant);
        $this->postJson("/api/v1/shifts/{$recurringShifts[1]->id}/open", [
            'readings' => [[
                'nozzle_id' => $nozzle->id,
                'reading' => 1000,
            ]],
        ])->assertConflict()
            ->assertJsonPath('message', 'This shift can only be started on its scheduled date.');
        $this->postJson("/api/v1/shifts/{$shift['id']}/open", [
            'readings' => [[
                'nozzle_id' => $nozzle->id,
                'reading' => 1000,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.started_by', $attendant->id)
            ->assertJsonPath('data.is_late', true)
            ->assertJsonPath('data.late_seconds', 900);

        $this->postJson('/api/v1/sales', [
            'nozzle_id' => $nozzle->id,
            'shift_id' => $shift['id'],
            'litres' => 10,
            'payment_method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.attendant_id', $attendant->id)
            ->assertJsonPath('data.amount', '150.00');

        $this->assertDatabaseCount('meter_readings', 1);
        $this->assertDatabaseHas('meter_readings', [
            'shift_id' => $shift['id'],
            'nozzle_id' => $nozzle->id,
            'type' => 'opening',
            'reading' => 1000,
            'recorded_by' => $attendant->id,
        ]);

        $this->travel(75)->minutes();
        $this->postJson("/api/v1/shifts/{$shift['id']}/close", [
            'counted_cash' => 750,
            'readings' => [[
                'nozzle_id' => $nozzle->id,
                'reading' => 1010,
            ]],
            'notes' => 'Attendant completed the assigned shift.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.ended_by', $attendant->id)
            ->assertJsonPath('data.is_overtime', true)
            ->assertJsonPath('data.overtime_seconds', 1800);

        $record = Shift::query()->findOrFail($shift['id']);
        $this->assertNotNull($record->opened_at);
        $this->assertNotNull($record->closed_at);
        $this->assertSame(900, $record->late_seconds);
        $this->assertSame(1800, $record->overtime_seconds);

        Sanctum::actingAs($stationManager);
        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'data.daily_attendant_sales')
            ->assertJsonPath('data.daily_attendant_sales.0.date', '2026-08-08')
            ->assertJsonPath('data.daily_attendant_sales.0.attendant_name', $attendant->name)
            ->assertJsonPath('data.daily_attendant_sales.0.amount', 150)
            ->assertJsonPath('data.daily_attendant_sales.0.started_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.daily_attendant_sales.0.closed_at', fn ($value) => is_string($value));
        $this->getJson(
            '/api/v1/reports?type=shift-attendance&from=2026-08-08&to=2026-08-08',
        )->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.attendant', $attendant->name)
            ->assertJsonPath('data.rows.0.attendance', 'late')
            ->assertJsonPath('data.rows.0.late_minutes', 15)
            ->assertJsonPath('data.rows.0.overtime_minutes', 30)
            ->assertJsonPath('data.rows.0.status', 'closed');

        $this->travelTo(CarbonImmutable::parse('2026-08-09 08:15:00', 'Africa/Accra'));
        Sanctum::actingAs($attendant);
        $this->postJson("/api/v1/shifts/{$recurringShifts[1]->id}/open", [
            'readings' => [[
                'nozzle_id' => $nozzle->id,
                'reading' => 1010,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.late_seconds', 900);
    }

    private function userWithRole(
        Organization $organization,
        Station $station,
        string $roleSlug,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'status' => UserStatus::Active,
        ]);
        $user->stations()->attach($station->id, ['is_primary' => true]);
        $user->roleAssignments()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'organization_id' => $organization->id,
            'station_id' => $station->id,
        ]);

        return $user;
    }
}
