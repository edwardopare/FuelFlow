<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $target->organization_id === $this->user()?->organization_id
            && $this->user()?->hasOrganizationWidePermission('users.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => [
                'sometimes',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'station_ids' => ['sometimes', 'array', 'min:1'],
            'station_ids.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('stations', 'id'),
            ],
            'role_assignments' => ['sometimes', 'array', 'min:1'],
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
            if (! $this->hasAny(['station_ids', 'role_assignments'])) {
                return;
            }

            if (! $this->has('station_ids') || ! $this->has('role_assignments')) {
                $validator->errors()->add(
                    'role_assignments',
                    'Station and role assignments must be updated together.',
                );

                return;
            }

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
