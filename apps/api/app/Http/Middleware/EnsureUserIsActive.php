<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->status === UserStatus::Active
                && $user->organization()->first()?->isAccessible() === true,
            403,
            'The account and company must be active before this action is allowed.',
        );

        return $next($request);
    }
}
