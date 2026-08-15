<?php

namespace App\Http\Requests\Vendor;

use App\Models\Organization;
use App\Models\Station;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreVendorOrganizationUserRequest extends FormRequest
{
    private const ORGANIZATION_ROLES = ['administrator', 'owner', 'accountant', 'auditor'];

    private const STATION_ROLES = ['station_manager', 'cashier_attendant'];

    public function authorize(): bool
    {
        return $this->user('vendor')?->role === 'super_user';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role_slug' => [
                'required',
                'string',
                Rule::in([...self::ORGANIZATION_ROLES, ...self::STATION_ROLES]),
            ],
            'station_id' => ['nullable', 'string', Rule::exists('stations', 'id')],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Organization|null $organization */
            $organization = $this->route('organization');
            $role = $this->string('role_slug')->toString();
            $stationId = $this->input('station_id');

            if (in_array($role, self::STATION_ROLES, true) && ! $stationId) {
                $validator->errors()->add('station_id', 'This role requires an operating station.');
            }

            if (in_array($role, self::ORGANIZATION_ROLES, true) && $stationId) {
                $validator->errors()->add('station_id', 'Head Office is assigned automatically for this role.');
            }

            if ($stationId && $organization && ! Station::query()
                ->whereKey($stationId)
                ->where('organization_id', $organization->id)
                ->where('station_number', '!=', 'HO-0001')
                ->exists()) {
                $validator->errors()->add('station_id', 'Select an operating station belonging to this company.');
            }
        }];
    }
}
