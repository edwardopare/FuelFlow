<?php

namespace App\Http\Controllers;

use App\Models\Station;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * @return list<string>
     */
    protected function authorizedStationIds(Request $request): array
    {
        $user = $request->user();

        return $user->hasOrganizationWidePermission('stations.view')
            ? Station::query()
                ->where('organization_id', $user->organization_id)
                ->pluck('id')
                ->all()
            : $user->stations()->pluck('stations.id')->all();
    }

    protected function requirePermission(
        Request $request,
        string $permission,
        ?string $stationId = null,
    ): void {
        abort_unless(
            $request->user()?->hasPermission($permission, $stationId),
            403,
            'You do not have permission to perform this action.',
        );
    }

    protected function requireStationAccess(Request $request, string $stationId): void
    {
        abort_unless(
            in_array($stationId, $this->authorizedStationIds($request), true),
            404,
        );
    }
}
