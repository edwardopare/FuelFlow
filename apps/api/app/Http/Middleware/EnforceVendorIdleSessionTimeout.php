<?php

namespace App\Http\Middleware;

use App\Services\VendorAuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceVendorIdleSessionTimeout
{
    private const SESSION_KEY = 'vendor_auth_last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('vendor');

        if (! $user) {
            return $next($request);
        }

        $timeoutMinutes = max(5, min(1440, (int) config('session.vendor_lifetime', 30)));
        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if (is_numeric($lastActivity)
            && now()->timestamp - (int) $lastActivity > $timeoutMinutes * 60) {
            app(VendorAuditService::class)->record(
                'vendor.auth.session_expired',
                $user,
                metadata: ['idle_timeout_minutes' => $timeoutMinutes],
                actor: $user,
            );

            Auth::guard('vendor')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return new JsonResponse([
                'message' => 'Your vendor session expired due to inactivity. Please sign in again.',
            ], 401);
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return $next($request);
    }
}
