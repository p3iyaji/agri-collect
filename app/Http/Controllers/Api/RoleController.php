<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRoleRequest;
use App\Models\Membership;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Role and permission management, scoped to the workspace in the URL.
 *
 * Permissions are drawn from the closed catalog in
 * config/tenancy.php (permission_catalog). Changes take effect when
 * tokens are re-issued (login / refresh / workspace switch), matching
 * the stateless design.
 */
class RoleController extends Controller
{
    /**
     * GET /api/workspaces/{account}/roles
     *
     * meta.permissions carries the permission catalog for the UI.
     */
    public function index(Request $request, int $account): JsonResponse
    {
        $roles = Role::query()
            ->where('account_id', $account)
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'type' => 'roles',
                'id' => (string) $role->getKey(),
                'attributes' => $this->attributes($role, $account),
            ]);

        return response()->json([
            'data' => $roles->all(),
            'meta' => ['permissions' => config('tenancy.permission_catalog')],
        ]);
    }

    /**
     * POST /api/workspaces/{account}/roles
     */
    public function store(SaveRoleRequest $request, int $account): JsonResponse
    {
        $data = $request->validated();

        $this->assertNameUnique($account, $data['name']);

        $role = Role::query()->create([
            'account_id' => $account,
            'name' => $data['name'],
            'permissions' => array_values($data['permissions']),
        ]);

        return $this->resource('roles', (string) $role->getKey(), $this->attributes($role, $account));
    }

    /**
     * PATCH /api/workspaces/{account}/roles/{role}
     */
    public function update(SaveRoleRequest $request, int $account, int $role): JsonResponse
    {
        $roleModel = Role::query()
            ->where('account_id', $account)
            ->where('id', $role)
            ->firstOrFail();

        $data = $request->validated();

        $this->assertNameUnique($account, $data['name'], $roleModel->getKey());
        $this->assertKeepsAdministrator($account, $roleModel, $data['permissions']);

        $roleModel->update([
            'name' => $data['name'],
            'permissions' => array_values($data['permissions']),
        ]);

        return $this->resource('roles', (string) $roleModel->getKey(), $this->attributes($roleModel, $account));
    }

    /**
     * DELETE /api/workspaces/{account}/roles/{role}
     *
     * Refused while any active member still holds the role.
     */
    public function destroy(Request $request, int $account, int $role): JsonResponse
    {
        $roleModel = Role::query()
            ->where('account_id', $account)
            ->where('id', $role)
            ->firstOrFail();

        $inUse = Membership::query()
            ->where('account_id', $account)
            ->where('role_id', $roleModel->getKey())
            ->where('status', 'active')
            ->exists();

        if ($inUse) {
            return response()->json([
                'error' => 'Role is assigned to active members. Reassign them first.',
            ], 422);
        }

        $roleModel->delete();

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(Role $role, int $account): array
    {
        return [
            'name' => $role->name,
            'permissions' => $role->permissions ?? [],
            'member_count' => Membership::query()
                ->where('account_id', $account)
                ->where('role_id', $role->getKey())
                ->where('status', 'active')
                ->count(),
            'grants_admin' => in_array('accounts.manage', $role->permissions ?? [], true),
        ];
    }

    protected function assertNameUnique(int $account, string $name, ?int $ignoreId = null): void
    {
        $query = Role::query()->where('account_id', $account)->where('name', $name);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A role with this name already exists in this workspace',
            ]);
        }
    }

    /**
     * Block removing the administrator permission from the only admin role
     * that is actually in use.
     *
     * @param  list<string>  $newPermissions
     */
    protected function assertKeepsAdministrator(int $account, Role $role, array $newPermissions): void
    {
        $wasAdmin = in_array('accounts.manage', $role->permissions ?? [], true);
        $staysAdmin = in_array('accounts.manage', $newPermissions, true);

        if (! $wasAdmin || $staysAdmin) {
            return;
        }

        $inUse = Membership::query()
            ->where('account_id', $account)
            ->where('role_id', $role->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $inUse) {
            return;
        }

        $anotherAdminRole = Role::query()
            ->where('account_id', $account)
            ->where('id', '!=', $role->getKey())
            ->get()
            ->filter(fn (Role $candidate) => in_array('accounts.manage', $candidate->permissions ?? [], true))
            ->isNotEmpty();

        if (! $anotherAdminRole) {
            throw ValidationException::withMessages([
                'permissions' => 'The workspace must keep at least one role that can administer it',
            ]);
        }
    }
}
