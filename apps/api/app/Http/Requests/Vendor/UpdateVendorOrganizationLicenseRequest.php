<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateVendorOrganizationLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('vendor')?->role === 'super_user';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'duration' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', Rule::in(['months', 'years'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $duration = $this->integer('duration');
            $unit = $this->string('unit')->toString();

            if (($unit === 'months' && $duration > 120)
                || ($unit === 'years' && $duration > 10)) {
                $validator->errors()->add(
                    'duration',
                    'The maximum license tenure is 120 months or 10 years.',
                );
            }
        }];
    }
}
