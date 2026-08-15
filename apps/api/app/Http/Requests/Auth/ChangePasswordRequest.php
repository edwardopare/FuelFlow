<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isRequiredFirstLogin = $this->user()?->must_change_password === true;

        return [
            'current_password' => $isRequiredFirstLogin
                ? ['nullable', 'string']
                : ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'terminal_pin' => ['nullable', 'digits:6'],
        ];
    }
}
