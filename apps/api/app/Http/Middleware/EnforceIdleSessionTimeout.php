<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleSessionTimeout
{
    private const SESSION_KEY = 'auth_last_activity';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $organization = $user->organization()->first();

        if ($organization?->isAccessible() !== true) {
            app(AuditService::class)->record(
                'auth.company_access_revoked',
                $user,
                actor: $user,
            );

            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return new JsonResponse([
                'message' => match ($organization?->accessStatus()) {
                    'expired' => 'The company license has expired. Contact the vendor.',
                    'license_deactivated' => 'The company license has been deactivated by the vendor.',
                    default => 'Access to this company has been suspended by the vendor.',
                },
            ], 401);
        }

        $timeoutMinutes = $this->timeoutMinutes($user->organization_id);
        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if (is_numeric($lastActivity)
            && now()->timestamp - (int) $lastActivity > $timeoutMinutes * 60) {
            app(AuditService::class)->record(
                'auth.session_expired',
                $user,
                metadata: ['idle_timeout_minutes' => $timeoutMinutes],
                actor: $user,
            );

            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return new JsonResponse([
                'message' => 'Your session expired due to inactivity. Please sign in again.',
            ], 401);
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return $next($request);
    }

    private function timeoutMinutes(string $organizationId): int
    {
        $configured = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->where('key', 'session_timeout_minutes')
            ->first()
            ?->value;

        return is_numeric($configured)
            ? max(5, min(10080, (int) $configured))
            : (int) config('session.lifetime', 120);
    }
}
