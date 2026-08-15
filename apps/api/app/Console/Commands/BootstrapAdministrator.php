<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuditService;
use App\Services\OrganizationLicenseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapAdministrator extends Command
{
    protected $signature = 'fuelflow:bootstrap-admin
        {--organization= : Organization name}
        {--organization-slug= : Unique organization slug (defaults from name)}
        {--admin-name= : Administrator full name}
        {--admin-email= : Administrator email address}
        {--admin-password= : Temporary password; prefer INITIAL_ADMIN_PASSWORD instead}
        {--admin-phone= : Optional administrator phone number}';

    protected $description = 'Create the organization, Head Office station, and initial Administrator account';

    public function handle(
        AuditService $audit,
        OrganizationLicenseService $licenses,
    ): int {
        $organizationName = trim((string) $this->option('organization'));
        $organizationSlug = trim((string) ($this->option('organization-slug') ?: Str::slug($organizationName)));
        $adminName = trim((string) $this->option('admin-name'));
        $adminEmail = strtolower(trim((string) $this->option('admin-email')));
        $password = (string) ($this->option('admin-password') ?: env('INITIAL_ADMIN_PASSWORD', ''));

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Temporary administrator password');
        }

        $validator = Validator::make([
            'organization' => $organizationName,
            'organization_slug' => $organizationSlug,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $password,
            'admin_phone' => $this->option('admin-phone'),
        ], [
            'organization' => ['required', 'string', 'max:255'],
            'organization_slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => [
                'required',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'admin_phone' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::withTrashed()->where('email', $adminEmail)->exists()) {
            $this->error('A user with this email address already exists. No changes were made.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--force' => true,
        ]);

        DB::transaction(function () use (
            $organizationName,
            $organizationSlug,
            $adminName,
            $adminEmail,
            $password,
            $audit,
            $licenses,
        ): void {
            $organization = Organization::query()->firstOrCreate(
                ['slug' => $organizationSlug],
                [
                    'name' => $organizationName,
                    'currency' => 'GHS',
                    'timezone' => 'Africa/Accra',
                    ...$licenses->terms(1, 'years'),
                ],
            );

            $station = Station::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => 'HEAD-OFFICE',
                ],
                [
                    'station_number' => 'HO-0001',
                    'name' => 'Head Office',
                    'timezone' => 'Africa/Accra',
                    'is_active' => true,
                ],
            );

            $administrator = User::query()->create([
                'organization_id' => $organization->id,
                'name' => $adminName,
                'email' => $adminEmail,
                'phone' => $this->option('admin-phone') ?: null,
                'status' => UserStatus::PendingFirstLogin,
                'must_change_password' => true,
                'email_verified_at' => now(),
                'password' => $password,
            ]);
            $administrator->stations()->attach($station->id, ['is_primary' => true]);

            $role = Role::query()->where('slug', 'administrator')->firstOrFail();
            UserRoleAssignment::query()->create([
                'user_id' => $administrator->id,
                'role_id' => $role->id,
                'organization_id' => $organization->id,
                'station_id' => null,
            ]);

            $audit->record(
                'system.initial_administrator_created',
                $administrator,
                after: [
                    'email' => $administrator->email,
                    'status' => $administrator->status->value,
                    'station_id' => $station->id,
                ],
                stationId: $station->id,
                actor: $administrator,
            );
        });

        $this->info('Initial Administrator created successfully.');
        $this->line('The account must change its temporary password on first login.');

        return self::SUCCESS;
    }
}
