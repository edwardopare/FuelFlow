<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendorSuperUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('vendor');

        abort_unless(
            $user?->status === UserStatus::Active
                && $user->role === 'super_user'
                && ! $user->must_change_password,
            403,
            'An active vendor Super User account is required.',
        );

        return $next($request);
    }
}
