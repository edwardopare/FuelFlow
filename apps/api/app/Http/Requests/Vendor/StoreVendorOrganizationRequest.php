<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreVendorOrganizationRequest extends FormRequest
{
    private const ROLE_SLUGS = [
        'administrator',
        'owner',
        'station_manager',
        'cashier_attendant',
        'accountant',
        'auditor',
    ];

    public function authorize(): bool
    {
        return $this->user('vendor')?->role === 'super_user';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company.name' => ['required', 'string', 'max:255'],
            'company.slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:organizations,slug',
            ],
            'company.registration_number' => ['nullable', 'string', 'max:100'],
            'company.contact_email' => ['required', 'email', 'max:255'],
            'company.phone' => ['required', 'string', 'max:40'],
            'company.address' => ['required', 'string', 'max:1000'],
            'company.timezone' => ['sometimes', 'timezone'],
            'license.duration' => ['required', 'integer', 'min:1'],
            'license.unit' => ['required', 'string', Rule::in(['months', 'years'])],
            'station.name' => ['required', 'string', 'max:255'],
            'station.code' => [
                'required',
                'string',
                'max:32',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::notIn(['HEAD-OFFICE']),
            ],
            'station.station_number' => ['required', 'string', 'max:80', Rule::notIn(['HO-0001'])],
            'station.registration_number' => ['nullable', 'string', 'max:100'],
            'station.phone' => ['nullable', 'string', 'max:40'],
            'station.address' => ['required', 'string', 'max:1000'],
            'accounts' => ['required', 'array', 'min:1', 'max:30'],
            'accounts.*.name' => ['required', 'string', 'max:120'],
            'accounts.*.email' => [
                'required',
                'email',
                'max:255',
                'distinct:ignore_case',
                'unique:users,email',
            ],
            'accounts.*.phone' => ['nullable', 'string', 'max:40'],
            'accounts.*.role_slug' => ['required', 'string', Rule::in(self::ROLE_SLUGS)],
            'accounts.*.password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $roles = collect($this->input('accounts', []))->pluck('role_slug');

            if (! $roles->contains('administrator')) {
                $validator->errors()->add(
                    'accounts',
                    'At least one Administrator account is required.',
                );
            }

            $duration = $this->integer('license.duration');
            $unit = $this->string('license.unit')->toString();

            if (($unit === 'months' && $duration > 120)
                || ($unit === 'years' && $duration > 10)) {
                $validator->errors()->add(
                    'license.duration',
                    'The maximum license tenure is 120 months or 10 years.',
                );
            }
        }];
    }
}
