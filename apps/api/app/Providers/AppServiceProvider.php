<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute((int) config('app.api_rate_limit_per_minute', 120))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        RateLimiter::for('vendor-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                'vendor|'.strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        ResetPassword::createUrlUsing(
            fn (User $user, string $token): string => sprintf(
                '%s/reset-password/%s?email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                $token,
                urlencode($user->getEmailForPasswordReset()),
            ),
        );
    }
}
