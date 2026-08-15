<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Vendor\VendorChangePasswordRequest;
use App\Http\Resources\VendorUserResource;
use App\Services\VendorAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VendorAuthController extends Controller
{
    public function login(LoginRequest $request, VendorAuditService $audit): VendorUserResource
    {
        Auth::shouldUse('vendor');
        $credentials = $request->safe()->only(['email', 'password']);
        $guard = Auth::guard('vendor');

        if (! $guard->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        $user = $guard->user();

        if (! in_array($user->status, [UserStatus::Active, UserStatus::PendingFirstLogin], true)
            || $user->role !== 'super_user') {
            $guard->logout();

            throw ValidationException::withMessages([
                'email' => ['This vendor account is not active.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('vendor_auth_last_activity', now()->timestamp);
        $user->forceFill(['last_authenticated_at' => now()])->save();
        $audit->record('vendor.auth.login', $user, actor: $user);

        return new VendorUserResource($user);
    }

    public function me(): VendorUserResource
    {
        return new VendorUserResource(request()->user('vendor'));
    }

    public function changePassword(
        VendorChangePasswordRequest $request,
        VendorAuditService $audit,
    ): VendorUserResource {
        $user = $request->user('vendor');

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
            'status' => UserStatus::Active,
            'last_authenticated_at' => now(),
        ])->save();

        $request->session()->regenerate();
        $request->session()->put('vendor_auth_last_activity', now()->timestamp);
        $audit->record('vendor.auth.password_changed', $user, actor: $user);

        return new VendorUserResource($user);
    }

    public function logout(VendorAuditService $audit): JsonResponse
    {
        $user = request()->user('vendor');
        $audit->record('vendor.auth.logout', $user, actor: $user);

        Auth::guard('vendor')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
