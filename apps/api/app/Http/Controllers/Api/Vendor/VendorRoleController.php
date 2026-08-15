<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class VendorRoleController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()
                ->with('permissions:id,name,description')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'scope' => $role->scope,
                    'description' => $role->description,
                    'permissions' => $role->permissions->map(fn ($permission) => [
                        'name' => $permission->name,
                        'description' => $permission->description,
                    ])->values(),
                ])->values(),
        ]);
    }
}
