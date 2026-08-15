<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Pump;
use App\Models\PurchaseOrder;
use App\Models\Reconciliation;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tank;
use App\Models\User;
use App\Services\OrganizationLicenseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoDataSeeder extends Seeder
{
    public function run(OrganizationLicenseService $licenses): void
    {
        $organization = Organization::query()->updateOrCreate(
            ['slug' => 'fuelflow-ghana'],
            [
                'name' => 'FuelFlow Ghana',
                'currency' => 'GHS',
                'timezone' => 'Africa/Accra',
            ],
        );

        if ($organization->license_expires_at === null) {
            $organization->forceFill($licenses->terms(1, 'years'))->save();
        }

        $station = Station::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'code' => 'ACC-001',
            ],
            [
                'station_number' => 'STN-0001',
                'name' => 'Accra Central Station',
                'timezone' => 'Africa/Accra',
                'registration_number' => 'LOCAL-DEMO',
                'phone' => '+233 30 200 1000',
                'address' => 'Independence Avenue, Accra, Ghana',
                'is_active' => true,
            ],
        );

        $headOffice = Station::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'code' => 'HO-001',
            ],
            [
                'station_number' => 'HO-0001',
                'name' => 'Head Office',
                'timezone' => 'Africa/Accra',
                'registration_number' => 'HEAD-OFFICE',
                'phone' => '+233 30 200 0000',
                'address' => 'Accra, Ghana',
                'is_active' => true,
            ],
        );

        $administrator = User::query()->updateOrCreate(
            ['email' => 'admin@fuelflow.local'],
            [
                'organization_id' => $organization->getKey(),
                'name' => 'FuelFlow Administrator',
                'phone' => '+233 20 000 0000',
                'password' => env('DEMO_ADMIN_PASSWORD', 'ChangeMeNow1!'),
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        $administrator->stations()->sync([
            $headOffice->getKey() => ['is_primary' => true],
        ]);

        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        $administrator->roleAssignments()->updateOrCreate(
            [
                'role_id' => $role->getKey(),
                'organization_id' => $organization->getKey(),
                'station_id' => null,
            ],
        );

        $stationManager = $this->user(
            $organization,
            $station,
            'manager@fuelflow.local',
            'Ama Mensah',
            'station_manager',
        );
        $attendant = $this->user(
            $organization,
            $station,
            'attendant@fuelflow.local',
            'Kwame Asante',
            'cashier_attendant',
        );
        $accountant = $this->user(
            $organization,
            $headOffice,
            'accountant@fuelflow.local',
            'Akosua Owusu',
            'accountant',
        );
        $owner = $this->user(
            $organization,
            $headOffice,
            'owner@fuelflow.local',
            'Kofi Boateng',
            'owner',
        );

        $petrol = $this->product($organization, 'PMS', 'Premium Petrol', 'Petrol', 15.49, $administrator);
        $diesel = $this->product($organization, 'AGO', 'Automotive Gas Oil', 'Diesel', 16.25, $administrator);

        $supplier = Supplier::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'GOIL-001',
            ],
            [
                'name' => 'GOIL PLC',
                'contact_name' => 'Commercial Fuels Desk',
                'email' => 'supply@example.test',
                'phone' => '+233 30 268 6930',
                'address' => 'Accra, Ghana',
                'tax_registration_number' => 'GH-TIN-100001',
                'bank_details' => 'Demo bank details protected at rest',
                'payment_terms' => 'Net 14 days',
                'is_active' => true,
                'on_time_percentage' => 96.5,
                'quality_rating' => 4.8,
                'quantity_accuracy' => 99.2,
            ],
        );
        foreach ([[$petrol, 13.90], [$diesel, 14.55]] as [$product, $price]) {
            $supplier->prices()->updateOrCreate(
                ['product_id' => $product->id, 'effective_from' => now()->startOfMonth()],
                ['price' => $price],
            );
        }

        $petrolTank = $this->tank($organization, $station, $petrol, 'T-01', 'Petrol Tank 1', 45000, 28450, 7500);
        $dieselTank = $this->tank($organization, $station, $diesel, 'T-02', 'Diesel Tank 1', 40000, 22300, 6500);

        $pump = Pump::query()->updateOrCreate(
            ['station_id' => $station->id, 'code' => 'P-01'],
            [
                'organization_id' => $organization->id,
                'name' => 'Forecourt Pump 1',
                'status' => 'operational',
            ],
        );
        $petrolNozzle = $pump->nozzles()->updateOrCreate(
            ['code' => 'N-01'],
            [
                'tank_id' => $petrolTank->id,
                'product_id' => $petrol->id,
                'status' => 'operational',
                'current_meter_reading' => 126405.250,
                'meter_maximum' => 999999.999,
            ],
        );
        $pump->nozzles()->updateOrCreate(
            ['code' => 'N-02'],
            [
                'tank_id' => $dieselTank->id,
                'product_id' => $diesel->id,
                'status' => 'operational',
                'current_meter_reading' => 98213.700,
                'meter_maximum' => 999999.999,
            ],
        );

        $demoShift = Shift::query()->updateOrCreate(
            ['code' => 'SH-DEMO-TODAY'],
            [
                'organization_id' => $organization->id,
                'station_id' => $station->id,
                'attendant_id' => $attendant->id,
                'pump_id' => $pump->id,
                'status' => 'scheduled',
                'scheduled_start' => now()->startOfDay()->addHours(6),
                'scheduled_end' => now()->startOfDay()->addHours(14),
                'opened_at' => null,
                'started_by' => null,
                'late_seconds' => 0,
                'closed_at' => null,
                'ended_by' => null,
                'overtime_seconds' => 0,
                'expected_cash' => 0,
                'counted_cash' => null,
                'notes' => 'Demo morning forecourt shift.',
            ],
        );
        $demoShift->meterReadings()->delete();

        $receiptPath = 'purchase-order-payment-receipts/'.$organization->id.'/po-demo-001-receipt.png';
        $receiptContents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        Storage::disk('local')->put($receiptPath, $receiptContents ?: '');

        $order = PurchaseOrder::query()->updateOrCreate(
            ['po_number' => 'PO-DEMO-001'],
            [
                'organization_id' => $organization->id,
                'station_id' => $station->id,
                'supplier_id' => $supplier->id,
                'status' => 'sent',
                'expected_delivery_date' => today()->addDays(2),
                'subtotal' => 139000,
                'total' => 139000,
                'notes' => 'Scheduled petrol replenishment.',
                'created_by' => $stationManager->id,
                'approved_by' => $administrator->id,
                'approved_at' => now()->subDay(),
                'paid_by' => $accountant->id,
                'paid_at' => now()->subHours(22),
                'payment_reference' => 'GCB-DEMO-0001',
                'payment_receipt_path' => $receiptPath,
                'payment_receipt_name' => 'PO-DEMO-001-payment-receipt.png',
                'payment_receipt_mime' => 'image/png',
                'payment_receipt_size' => strlen($receiptContents ?: ''),
                'sent_at' => now()->subHours(20),
            ],
        );
        $order->lines()->updateOrCreate(
            ['product_id' => $petrol->id],
            [
                'target_tank_id' => $petrolTank->id,
                'quantity_litres' => 10000,
                'unit_price' => 13.90,
                'received_quantity_litres' => 0,
            ],
        );

        $pendingOrder = PurchaseOrder::query()->updateOrCreate(
            ['po_number' => 'PO-DEMO-002'],
            [
                'organization_id' => $organization->id,
                'station_id' => $station->id,
                'supplier_id' => $supplier->id,
                'status' => 'pending_approval',
                'expected_delivery_date' => today()->addDays(4),
                'subtotal' => 72750,
                'total' => 72750,
                'notes' => 'Diesel replenishment requested after reviewing the station stock forecast.',
                'created_by' => $stationManager->id,
                'approved_by' => null,
                'approved_at' => null,
                'paid_by' => null,
                'paid_at' => null,
                'payment_reference' => null,
                'payment_receipt_path' => null,
                'payment_receipt_name' => null,
                'payment_receipt_mime' => null,
                'payment_receipt_size' => null,
                'sent_at' => null,
            ],
        );
        $pendingOrder->lines()->updateOrCreate(
            ['product_id' => $diesel->id],
            [
                'target_tank_id' => $dieselTank->id,
                'quantity_litres' => 5000,
                'unit_price' => 14.55,
                'received_quantity_litres' => 0,
            ],
        );

        $approvedOrder = PurchaseOrder::query()->updateOrCreate(
            ['po_number' => 'PO-DEMO-003'],
            [
                'organization_id' => $organization->id,
                'station_id' => $station->id,
                'supplier_id' => $supplier->id,
                'status' => 'approved',
                'expected_delivery_date' => today()->addDays(3),
                'subtotal' => 55600,
                'total' => 55600,
                'notes' => 'Administrator-approved petrol top-up awaiting accountant payment.',
                'created_by' => $stationManager->id,
                'approved_by' => $administrator->id,
                'approved_at' => now()->subHours(3),
                'paid_by' => null,
                'paid_at' => null,
                'payment_reference' => null,
                'payment_receipt_path' => null,
                'payment_receipt_name' => null,
                'payment_receipt_mime' => null,
                'payment_receipt_size' => null,
                'sent_at' => null,
            ],
        );
        $approvedOrder->lines()->updateOrCreate(
            ['product_id' => $petrol->id],
            [
                'target_tank_id' => $petrolTank->id,
                'quantity_litres' => 4000,
                'unit_price' => 13.90,
                'received_quantity_litres' => 0,
            ],
        );

        foreach ([
            ['RCPT-DEMO-001', 38.2, 'cash', now()->subHours(3)],
            ['RCPT-DEMO-002', 24.5, 'mobile_money', now()->subHours(2)],
            ['RCPT-DEMO-003', 51.0, 'card', now()->subHour()],
        ] as [$receipt, $litres, $payment, $soldAt]) {
            Sale::query()->updateOrCreate(
                ['receipt_number' => $receipt],
                [
                    'organization_id' => $organization->id,
                    'station_id' => $station->id,
                    'shift_id' => null,
                    'attendant_id' => $attendant->id,
                    'nozzle_id' => $petrolNozzle->id,
                    'tank_id' => $petrolTank->id,
                    'product_id' => $petrol->id,
                    'litres' => $litres,
                    'unit_price' => 15.49,
                    'amount' => round($litres * 15.49, 2),
                    'payment_method' => $payment,
                    'payment_reference' => $payment === 'cash' ? null : 'DEMO-'.strtoupper($payment),
                    'status' => 'confirmed',
                    'sold_at' => $soldAt,
                ],
            );
        }

        Reconciliation::query()->updateOrCreate(
            ['station_id' => $station->id, 'business_date' => today()->subDay()],
            [
                'organization_id' => $organization->id,
                'status' => 'reconciled',
                'opening_stock_litres' => 51025,
                'receipts_litres' => 0,
                'sales_litres' => 275,
                'closing_book_stock_litres' => 50750,
                'closing_dip_stock_litres' => 50746.5,
                'tank_variance_litres' => -3.5,
                'sales_value' => 4258.75,
                'expected_cash' => 1810.20,
                'counted_cash' => 1810.20,
                'cash_variance' => 0,
                'manager_comment' => 'Dip variance reviewed and within operating tolerance.',
                'reviewed_by' => $stationManager->id,
                'reviewed_at' => now()->subHours(8),
                'locked_at' => now()->subHours(8),
            ],
        );

        ReportSchedule::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Daily finance and reconciliation pack',
            ],
            [
                'station_id' => $station->id,
                'report_type' => 'reconciliation',
                'frequency' => 'daily',
                'send_time' => '06:00',
                'day_of_week' => null,
                'day_of_month' => null,
                'recipients' => ['accountant@fuelflow.local', 'owner@fuelflow.local'],
                'is_active' => true,
                'created_by' => $administrator->id,
            ],
        );
    }

    private function user(
        Organization $organization,
        Station $station,
        string $email,
        string $name,
        string $roleSlug,
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'organization_id' => $organization->id,
                'name' => $name,
                'phone' => '+233 20 000 0001',
                'password' => env('DEMO_ADMIN_PASSWORD', 'ChangeMeNow1!'),
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );
        $user->stations()->sync([
            $station->id => ['is_primary' => true],
        ]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roleAssignments()->updateOrCreate([
            'role_id' => $role->id,
            'organization_id' => $organization->id,
        ], [
            'station_id' => $role->scope === 'station' ? $station->id : null,
        ]);

        return $user;
    }

    private function product(
        Organization $organization,
        string $code,
        string $name,
        string $grade,
        float $price,
        User $user,
    ): Product {
        $product = Product::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'code' => $code],
            [
                'name' => $name,
                'unit' => 'litres',
                'tank_grade' => $grade,
                'is_active' => true,
            ],
        );
        $product->prices()->updateOrCreate(
            ['station_id' => null, 'effective_from' => now()->startOfMonth()],
            [
                'price' => $price,
                'reason' => 'Demo retail price schedule',
                'changed_by' => $user->id,
            ],
        );

        return $product;
    }

    private function tank(
        Organization $organization,
        Station $station,
        Product $product,
        string $code,
        string $name,
        float $capacity,
        float $stock,
        float $minimum,
    ): Tank {
        return Tank::query()->updateOrCreate(
            ['station_id' => $station->id, 'code' => $code],
            [
                'organization_id' => $organization->id,
                'product_id' => $product->id,
                'name' => $name,
                'tank_grade' => $product->tank_grade,
                'capacity_litres' => $capacity,
                'book_stock_litres' => $stock,
                'minimum_safe_litres' => $minimum,
                'maximum_safe_litres' => $capacity * 0.95,
                'atg_enabled' => true,
                'status' => 'active',
            ],
        );
    }
}
