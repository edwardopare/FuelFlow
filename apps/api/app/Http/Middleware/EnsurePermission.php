<?php

namespace App\Http\Middleware;

use App\Models\Station;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $station = $request->route('station');
        $stationId = $station instanceof Station
            ? $station->getKey()
            : (is_string($station) ? $station : $request->query('station_id'));

        abort_unless(
            $request->user()?->hasPermission($permission, $stationId),
            403,
            'You do not have permission to perform this action.',
        );

        return $next($request);
    }
}
