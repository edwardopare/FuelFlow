<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private array $permissions = [
        'stations.view' => 'View authorized stations.',
        'stations.manage' => 'Manage station configuration.',
        'users.view' => 'View users within authorized scope.',
        'users.manage' => 'Create, update, activate, deactivate, and delete eligible users.',
        'audit.events.view' => 'View immutable audit events.',
        'dashboard.portfolio.view' => 'View the owner portfolio dashboard.',
        'dashboard.station.view' => 'View a station dashboard.',
        'dashboard.shift.view' => 'View an assigned shift dashboard.',
        'suppliers.view' => 'View suppliers.',
        'suppliers.manage' => 'Manage suppliers and documents.',
        'products.view' => 'View products and prices.',
        'products.manage' => 'Manage products and price schedules.',
        'procurement.purchase_orders.view' => 'View purchase orders.',
        'procurement.purchase_orders.create' => 'Create purchase orders.',
        'procurement.purchase_orders.update' => 'Edit purchase order drafts.',
        'procurement.purchase_orders.approve' => 'Approve or reject purchase orders.',
        'procurement.purchase_orders.pay' => 'Record payment and attach payment evidence for approved purchase orders.',
        'procurement.purchase_orders.send' => 'Send approved purchase orders.',
        'receiving.deliveries.view' => 'View deliveries.',
        'receiving.deliveries.create' => 'Record deliveries.',
        'receiving.deliveries.confirm' => 'Confirm in-tolerance deliveries.',
        'receiving.variances.sign_off' => 'Sign off receiving variances.',
        'tanks.view' => 'View tanks, stock, readings, and movements.',
        'tanks.manage' => 'Manage tanks and thresholds.',
        'tanks.readings.create' => 'Record tank readings.',
        'tanks.transfers.create' => 'Create tank transfers.',
        'pumps.view' => 'View pumps, nozzles, and maintenance.',
        'pumps.manage' => 'Manage pumps, nozzles, and maintenance.',
        'shifts.view' => 'View station shifts.',
        'shifts.manage' => 'Schedule and manage station shifts.',
        'shifts.own.open' => 'Open the assigned shift.',
        'shifts.own.close' => 'Close the assigned shift.',
        'shifts.override' => 'Override a shift-sensitive action with a reason.',
        'meter_readings.create' => 'Record assigned pump meter readings.',
        'sales.view' => 'View station sales.',
        'sales.own.view' => 'View sales recorded by the current attendant.',
        'sales.create' => 'Record sales for the assigned shift.',
        'sales.reverse' => 'Reverse a confirmed sale with a reason.',
        'receipts.own.reprint' => 'Reprint an own-shift receipt.',
        'till_counts.create' => 'Submit counted cash and payment slips.',
        'credit_accounts.view' => 'View credit balances.',
        'credit_accounts.manage' => 'Manage credit accounts and limits.',
        'reconciliations.view' => 'View reconciliations.',
        'reconciliations.generate' => 'Generate a reconciliation.',
        'reconciliations.comment' => 'Comment on an unlocked reconciliation.',
        'reconciliations.sign_off' => 'Sign off and lock a reconciliation.',
        'reconciliations.reopen' => 'Administratively reopen a reconciliation.',
        'reports.view' => 'View reports.',
        'reports.export' => 'Export reports.',
        'reports.schedules.manage' => 'Manage report schedules and recipients.',
    ];

    /**
     * @var array<string, array{name: string, scope: string, description: string}>
     */
    private array $roles = [
        'administrator' => [
            'name' => 'Administrator',
            'scope' => 'organization',
            'description' => 'Full system access and configuration rights.',
        ],
        'owner' => [
            'name' => 'Owner',
            'scope' => 'organization',
            'description' => 'Portfolio visibility and reporting access.',
        ],
        'station_manager' => [
            'name' => 'Station Manager',
            'scope' => 'station',
            'description' => 'Manages procurement, receiving, tanks, pumps, shifts, and reconciliation for a station.',
        ],
        'cashier_attendant' => [
            'name' => 'Cashier / Pump Attendant',
            'scope' => 'station',
            'description' => 'Records sales and starts or ends assigned shifts.',
        ],
        'accountant' => [
            'name' => 'Accountant',
            'scope' => 'organization',
            'description' => 'Head Office finance role for approved purchase orders, reconciliation, and reporting.',
        ],
        'auditor' => [
            'name' => 'Auditor',
            'scope' => 'organization',
            'description' => 'Read-only access to authorized operational and reporting modules.',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'owner' => [
            'stations.view',
            'dashboard.portfolio.view',
            'procurement.purchase_orders.view',
            'reports.view',
            'reports.export',
        ],
        'station_manager' => [
            'stations.view',
            'users.view',
            'dashboard.station.view',
            'suppliers.view',
            'suppliers.manage',
            'products.view',
            'products.manage',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.create',
            'procurement.purchase_orders.update',
            'procurement.purchase_orders.send',
            'receiving.deliveries.view',
            'receiving.deliveries.create',
            'receiving.deliveries.confirm',
            'receiving.variances.sign_off',
            'tanks.view',
            'tanks.manage',
            'tanks.readings.create',
            'tanks.transfers.create',
            'pumps.view',
            'pumps.manage',
            'shifts.view',
            'shifts.manage',
            'shifts.override',
            'sales.view',
            'credit_accounts.view',
            'reconciliations.view',
            'reconciliations.generate',
            'reconciliations.sign_off',
            'reports.view',
            'reports.export',
            'reports.schedules.manage',
        ],
        'cashier_attendant' => [
            'stations.view',
            'dashboard.shift.view',
            'shifts.own.open',
            'shifts.own.close',
            'meter_readings.create',
            'sales.own.view',
            'sales.create',
            'receipts.own.reprint',
            'till_counts.create',
        ],
        'accountant' => [
            'stations.view',
            'dashboard.station.view',
            'suppliers.view',
            'products.view',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.pay',
            'receiving.deliveries.view',
            'tanks.view',
            'pumps.view',
            'sales.view',
            'credit_accounts.view',
            'reconciliations.view',
            'reconciliations.comment',
            'reports.view',
            'reports.export',
            'reports.schedules.manage',
        ],
    ];

    public function run(): void
    {
        $permissions = collect($this->permissions)->mapWithKeys(
            fn (string $description, string $name) => [
                $name => Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['description' => $description],
                ),
            ],
        );

        foreach ($this->roles as $slug => $attributes) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], $attributes);

            $names = match ($slug) {
                'administrator' => $permissions->keys()->all(),
                'auditor' => $permissions->keys()
                    ->filter(fn (string $name) => str_ends_with($name, '.view')
                        || $name === 'reports.export')
                    ->reject(fn (string $name) => $name === 'audit.events.view')
                    ->values()
                    ->all(),
                default => $this->rolePermissions[$slug] ?? [],
            };

            $role->permissions()->sync(
                $permissions->only($names)->pluck('id')->all(),
            );
        }
    }
}
