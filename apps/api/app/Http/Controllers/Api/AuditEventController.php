<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $isAdministrator = $user->roleAssignments()
            ->whereHas(
                'role',
                fn ($query) => $query->where('slug', 'administrator'),
            )
            ->exists();

        abort_unless($isAdministrator, 403);

        $query = AuditEvent::query()
            ->where('organization_id', $user->organization_id)
            ->with('actor')
            ->latest('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->string('actor_id')->toString());
        }

        return AuditEventResource::collection($query->cursorPaginate(50));
    }
}
