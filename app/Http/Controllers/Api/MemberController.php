<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Workspace membership management: invite, reassign roles, suspend,
 * remove. All operations act on the workspace named in the URL, which
 * must equal the token's active workspace (EnsureWorkspaceMatches).
 *
 * A workspace must always keep at least one active administrator
 * (a role granting accounts.manage), so the last one cannot be
 * demoted, suspended, or removed.
 */
class MemberController extends Controller
{
    /**
     * GET /api/workspaces/{account}/members
     */
    public function index(Request $request, int $account): JsonResponse
    {
        $currentUserId = $this->currentUser()->getKey();

        $members = Membership::query()
            ->where('account_id', $account)
            ->with(['user', 'role'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Membership $membership) => [
                'type' => 'members',
                'id' => (string) $membership->user_id,
                'attributes' => [
                    'user_id' => (int) $membership->user_id,
                    'name' => $membership->user?->name,
                    'email' => $membership->user?->email,
                    ...$this->profileAttributes($membership->user),
                    'role_id' => $membership->role_id !== null ? (int) $membership->role_id : null,
                    'role' => $membership->role?->name ?? 'Member',
                    'status' => $membership->status,
                    'joined_at' => $membership->created_at?->toIso8601String(),
                    'is_you' => $membership->user_id === $currentUserId,
                ],
            ]);

        return $this->collection($members->all());
    }

    /**
     * POST /api/workspaces/{account}/members
     *
     * Attaches an existing user, or creates one with a generated password
     * that is returned exactly once (meta.temp_password) for sharing.
     */
    public function store(InviteMemberRequest $request, int $account): JsonResponse
    {
        $data = $request->validated();
        $email = mb_strtolower(trim((string) $data['email']));

        $role = null;

        if (! empty($data['role_id'])) {
            $role = Role::query()
                ->where('account_id', $account)
                ->where('id', (int) $data['role_id'])
                ->first();

            if ($role === null) {
                return response()->json(['error' => 'Unknown role for this workspace'], 422);
            }
        }

        $user = User::query()->where('email', $email)->first();
        $profile = $this->profileInput($data, ignoreEmpty: true);

        $createdUser = false;
        $tempPassword = null;

        if ($user === null) {
            $createdUser = true;
            $tempPassword = Str::password(12, symbols: false);

            $user = User::create([
                'name' => $data['name'] ?? Str::beforeLast($email, '@'),
                'email' => $email,
                'password' => $tempPassword,
                ...$profile,
            ]);
        }

        $alreadyMember = Membership::query()
            ->where('account_id', $account)
            ->where('user_id', $user->id)
            ->first();

        if ($alreadyMember !== null) {
            return response()->json([
                'error' => 'User is already a member of this workspace',
            ], 422);
        }

        if (! $createdUser && $profile !== []) {
            $user->fill($profile)->save();
        }

        $membership = Membership::query()->create([
            'account_id' => $account,
            'user_id' => $user->id,
            'role_id' => $role?->getKey(),
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'data' => [
                'type' => 'members',
                'id' => (string) $user->id,
                'attributes' => [
                    'user_id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    ...$this->profileAttributes($user),
                    'role_id' => $role?->getKey(),
                    'role' => $role?->name ?? 'Member',
                    'status' => $membership->status,
                    'joined_at' => $membership->created_at?->toIso8601String(),
                ],
            ],
            'meta' => [
                'created_user' => $createdUser,
                'temp_password' => $tempPassword,
            ],
        ]);
    }

    /**
     * PATCH /api/workspaces/{account}/members/{user}
     */
    public function update(UpdateMemberRequest $request, int $account, int $user): JsonResponse
    {
        $membership = Membership::query()
            ->where('account_id', $account)
            ->where('user_id', $user)
            ->with('role')
            ->firstOrFail();

        $data = $request->validated();
        $profile = $this->profileInput($data);

        if ($profile === [] && ! array_key_exists('role_id', $data) && ! array_key_exists('status', $data)) {
            return response()->json(['error' => 'Provide role_id and/or status'], 422);
        }

        if (array_key_exists('role_id', $data) && $data['role_id'] !== null) {
            $role = Role::query()
                ->where('account_id', $account)
                ->where('id', (int) $data['role_id'])
                ->first();

            if ($role === null) {
                return response()->json(['error' => 'Unknown role for this workspace'], 422);
            }
        }

        if ($this->wouldRemoveLastAdmin($account, $membership, $data)) {
            return response()->json([
                'error' => 'A workspace must keep at least one active administrator',
            ], 422);
        }

        if (array_key_exists('role_id', $data)) {
            $membership->role_id = $data['role_id'] !== null ? (int) $data['role_id'] : null;
        }

        if (array_key_exists('status', $data)) {
            $membership->status = $data['status'];
        }

        $membership->save();

        if ($profile !== []) {
            $membership->user->fill($profile)->save();
        }

        $membership->refresh(['role', 'user']);

        return $this->resource('members', (string) $membership->user_id, [
            'role_id' => $membership->role_id !== null ? (int) $membership->role_id : null,
            'role' => $membership->role?->name ?? 'Member',
            'status' => $membership->status,
            ...$this->profileAttributes($membership->user),
        ]);
    }

    /**
     * DELETE /api/workspaces/{account}/members/{user}
     */
    public function destroy(Request $request, int $account, int $user): JsonResponse
    {
        $membership = Membership::query()
            ->where('account_id', $account)
            ->where('user_id', $user)
            ->with('role')
            ->firstOrFail();

        if ($this->wouldRemoveLastAdmin($account, $membership, ['status' => 'suspended'])) {
            return response()->json([
                'error' => 'A workspace must keep at least one active administrator',
            ], 422);
        }

        $membership->delete();

        return response()->json(['data' => null]);
    }

    /**
     * True when the change would leave the workspace without an active
     * administrator (role granting accounts.manage).
     *
     * @param  array<string, mixed>  $data
     */
    protected function wouldRemoveLastAdmin(int $account, Membership $target, array $data): bool
    {
        if (! $this->roleGrantsAdmin($target->role_id, $account)) {
            return false;
        }

        $newStatus = $data['status'] ?? $target->status;

        if (array_key_exists('role_id', $data)) {
            $keepsAdminRole = $data['role_id'] !== null
                && $this->roleGrantsAdmin((int) $data['role_id'], $account);
        } else {
            $keepsAdminRole = $this->roleGrantsAdmin($target->role_id, $account);
        }

        $becomesNonAdmin = $newStatus !== 'active' || ! $keepsAdminRole;

        if (! $becomesNonAdmin) {
            return false;
        }

        $otherAdmins = Membership::query()
            ->where('account_id', $account)
            ->where('status', 'active')
            ->where('user_id', '!=', $target->user_id)
            ->with('role')
            ->get()
            ->filter(fn (Membership $membership) => in_array(
                'accounts.manage',
                $membership->role?->permissions ?? [],
                true,
            ))
            ->isNotEmpty();

        return ! $otherAdmins;
    }

    /**
     * Optional profile fields. Empty values are stored as null. When
     * inviting, omitted blanks are skipped so an existing user keeps
     * the profile they already have.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    protected function profileInput(array $data, bool $ignoreEmpty = false): array
    {
        $profile = [];

        foreach (['phone', 'address', 'city', 'state', 'date_of_engagement'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            $value = ($value === null || $value === '') ? null : $value;

            if ($ignoreEmpty && $value === null) {
                continue;
            }

            $profile[$field] = $value;
        }

        return $profile;
    }

    /**
     * @return array{phone: string|null, address: string|null, city: string|null, state: string|null, date_of_engagement: string|null}
     */
    protected function profileAttributes(?User $user): array
    {
        return [
            'phone' => $user?->phone,
            'address' => $user?->address,
            'city' => $user?->city,
            'state' => $user?->state,
            'date_of_engagement' => $user?->date_of_engagement?->toDateString(),
        ];
    }

    protected function roleGrantsAdmin(?int $roleId, int $account): bool
    {
        if ($roleId === null) {
            return false;
        }

        $role = Role::query()->where('account_id', $account)->where('id', $roleId)->first();

        return $role !== null && in_array('accounts.manage', $role->permissions ?? [], true);
    }
}
