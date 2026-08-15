<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink(['email' => strtolower($validated['email'])]);

        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(
        Request $request,
        AuditService $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);
        $resetUser = null;
        $status = Password::reset(
            [
                ...$validated,
                'email' => strtolower($validated['email']),
            ],
            function ($user, string $password) use (&$resetUser): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                $resetUser = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $resetUser) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $audit->record('auth.password_reset', $resetUser);

        return response()->json([
            'message' => 'Your password has been reset successfully.',
        ]);
    }

    public function login(LoginRequest $request, AuditService $audit): UserResource
    {
        Auth::shouldUse('web');
        $credentials = $request->safe()->only(['email', 'password']);
        $guard = Auth::guard('web');

        if (! $guard->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        $user = $guard->user();

        if (! in_array(
            $user->status,
            [UserStatus::Active, UserStatus::PendingFirstLogin],
            true,
        ) || $user->organization()->first()?->isAccessible() !== true) {
            $guard->logout();

            throw ValidationException::withMessages([
                'email' => ['This account or company is not active.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('auth_last_activity', now()->timestamp);
        $user->forceFill(['last_authenticated_at' => now()])->save();
        $audit->record('auth.login', $user, actor: $user);

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function me(): UserResource
    {
        return new UserResource(
            request()->user()->load([
                'organization',
                'stations',
                'roleAssignments.role',
            ]),
        );
    }

    public function changePassword(
        ChangePasswordRequest $request,
        AuditService $audit,
    ): UserResource {
        $user = $request->user();
        $isAttendant = $user->roleAssignments()
            ->whereHas('role', fn ($query) => $query->where('slug', 'cashier_attendant'))
            ->exists();

        if ($isAttendant && ! $request->filled('terminal_pin')) {
            throw ValidationException::withMessages([
                'terminal_pin' => ['A six-digit terminal PIN is required for this role.'],
            ]);
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'terminal_pin' => $request->filled('terminal_pin')
                ? Hash::make($request->string('terminal_pin')->toString())
                : $user->terminal_pin,
            'must_change_password' => false,
            'status' => UserStatus::Active,
            'last_authenticated_at' => now(),
        ])->save();

        $request->session()->regenerate();
        $request->session()->put('auth_last_activity', now()->timestamp);
        $audit->record('auth.password_changed', $user, actor: $user);

        return new UserResource(
            $user->load(['organization', 'stations', 'roleAssignments.role']),
        );
    }

    public function logout(AuditService $audit): JsonResponse
    {
        $user = request()->user();
        $audit->record('auth.logout', $user, actor: $user);

        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
