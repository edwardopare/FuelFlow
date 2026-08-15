<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\Station;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasOrganizationWidePermission('users.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'station_ids' => ['required', 'array', 'min:1'],
            'station_ids.*' => ['required', 'string', 'distinct', Rule::exists('stations', 'id')],
            'role_assignments' => ['required', 'array', 'min:1'],
            'role_assignments.*.role_id' => [
                'required',
                'string',
                Rule::exists('roles', 'id'),
            ],
            'role_assignments.*.station_id' => [
                'nullable',
                'string',
                Rule::exists('stations', 'id'),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $organizationId = $this->user()?->organization_id;
            $stationIds = collect($this->input('station_ids', []));

            $validStationCount = Station::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $stationIds)
                ->count();

            if ($validStationCount !== $stationIds->unique()->count()) {
                $validator->errors()->add(
                    'station_ids',
                    'Every station must belong to your organization.',
                );
            }

            foreach ($this->input('role_assignments', []) as $index => $assignment) {
                $role = Role::query()->find($assignment['role_id'] ?? null);
                $stationId = $assignment['station_id'] ?? null;

                if ($role?->scope === 'station' && ! $stationId) {
                    $validator->errors()->add(
                        "role_assignments.$index.station_id",
                        'A station-scoped role requires a station.',
                    );
                }

                if ($role?->scope === 'organization' && $stationId) {
                    $validator->errors()->add(
                        "role_assignments.$index.station_id",
                        'An organization-scoped role cannot specify a station.',
                    );
                }

                if ($stationId && ! $stationIds->contains($stationId)) {
                    $validator->errors()->add(
                        "role_assignments.$index.station_id",
                        'The role station must be included in station_ids.',
                    );
                }
            }
        }];
    }
}
