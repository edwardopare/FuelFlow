<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\VendorUser;
use App\Services\VendorAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapVendorSuperUser extends Command
{
    protected $signature = 'fuelflow:bootstrap-vendor
        {--name= : Vendor Super User full name}
        {--email= : Vendor Super User email address}
        {--password= : Temporary password; prefer INITIAL_VENDOR_PASSWORD instead}
        {--phone= : Optional phone number}';

    protected $description = 'Create the first vendor-managed Super User account';

    public function handle(VendorAuditService $audit): int
    {
        $name = trim((string) $this->option('name'));
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) ($this->option('password') ?: env('INITIAL_VENDOR_PASSWORD', ''));

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Temporary vendor Super User password');
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'phone' => $this->option('phone'),
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (VendorUser::query()->where('email', $email)->exists()) {
            $this->error('A vendor user with this email address already exists. No changes were made.');

            return self::FAILURE;
        }

        $user = VendorUser::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $this->option('phone') ?: null,
            'role' => 'super_user',
            'status' => UserStatus::PendingFirstLogin,
            'must_change_password' => true,
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        $audit->record(
            'vendor.initial_super_user_created',
            $user,
            after: ['email' => $user->email, 'role' => $user->role],
            actor: $user,
        );

        $this->info('Vendor Super User created successfully.');
        $this->line('The account must change its temporary password on first login.');

        return self::SUCCESS;
    }
}
