<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Account;
use App\Models\Form;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workspace (account) administration.
 *
 * Listing and creation are self-serve (any authenticated user); the
 * creator becomes Owner of a freshly provisioned workspace with the
 * default roles. Rename / delete act on the workspace in the URL, which
 * must equal the token's active workspace.
 */
class WorkspaceController extends Controller
{
    /**
     * GET /api/workspaces
     *
     * Every workspace the current user belongs to, with usage counts.
     * Uses withoutAccountScope() because this lists across tenants.
     */
    public function index(Request $request): JsonResponse
    {
        $claims = $request->attributes->get('jwt_claims') ?? [];
        $activeId = array_key_exists('account_id', $claims) ? (int) $claims['account_id'] : null;

        $rows = $this->currentUser()
            ->memberships()
            ->where('status', 'active')
            ->with(['account', 'role'])
            ->get()
            ->map(function (Membership $membership) use ($activeId) {
                $account = $membership->account;

                return [
                    'type' => 'workspaces',
                    'id' => (string) $account->id,
                    'attributes' => [
                        'name' => $account->name,
                        'slug' => $account->slug,
                        'role' => $membership->role?->name ?? 'Member',
                        'active' => $account->id === $activeId,
                        'logo_url' => $account->logoUrl(),
                        'settings' => $account->settings,
                        'counts' => [
                            'members' => Membership::query()->where('account_id', $account->id)->count(),
                            'forms' => Form::query()->withoutAccountScope()
                                ->where('account_id', $account->id)
                                ->count(),
                            'submissions' => Submission::query()->withoutAccountScope()
                                ->where('account_id', $account->id)
                                ->count(),
                        ],
                    ],
                ];
            });

        return $this->collection($rows->all());
    }

    /**
     * POST /api/workspaces
     *
     * Creates the workspace, provisions the default roles, and makes the
     * creator its Owner.
     */
    public function store(CreateWorkspaceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->currentUser();

        $slug = $data['slug'] ?? (string) Str::slug($data['name']);

        if (Account::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'A workspace with this slug already exists',
            ]);
        }

        $account = DB::transaction(function () use ($data, $slug, $user): Account {
            $account = Account::create([
                'name' => $data['name'],
                'slug' => $slug,
            ]);

            foreach (config('tenancy.default_roles') as $name => $permissions) {
                Role::create([
                    'account_id' => $account->id,
                    'name' => $name,
                    'permissions' => $permissions,
                ]);
            }

            $owner = Role::query()
                ->where('account_id', $account->id)
                ->where('name', 'Owner')
                ->first();

            Membership::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'role_id' => $owner?->getKey(),
                'status' => 'active',
            ]);

            return $account;
        });

        return response()->json([
            'data' => [
                'type' => 'workspaces',
                'id' => (string) $account->id,
                'attributes' => [
                    'name' => $account->name,
                    'slug' => $account->slug,
                ],
            ],
            'meta' => [
                'hint' => 'Call POST /api/auth/switch-account with this workspace id to start using it.',
            ],
        ]);
    }

    /**
     * PATCH /api/workspaces/{account}
     */
    public function update(UpdateWorkspaceRequest $request, int $account): JsonResponse
    {
        $workspace = Account::query()->where('id', $account)->firstOrFail();
        $data = $request->validated();

        if (isset($data['name']) && $data['name'] !== $workspace->name) {
            $duplicate = Account::query()
                ->where('name', $data['name'])
                ->where('id', '!=', $account)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => 'Another workspace already has this name',
                ]);
            }
        }

        if (array_key_exists('name', $data)) {
            $workspace->name = $data['name'];
        }

        if (array_key_exists('settings', $data)) {
            // Merge, never replace: internal keys such as logo_path must
            // survive a client that only manages organization / field.
            $settings = $workspace->settings ?? [];

            foreach ($data['settings'] as $key => $value) {
                if ($key === 'logo_path') {
                    continue;
                }

                if ($value === null) {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
            }

            $workspace->settings = $settings;
        }

        $workspace->save();

        return $this->resource('workspaces', (string) $workspace->id, [
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'logo_url' => $workspace->logoUrl(),
            'settings' => $workspace->settings,
        ]);
    }

    /**
     * GET /api/workspaces/{slug}/public-profile
     *
     * Public — powers the workspace-branded login page. Returns only
     * presentation data: name, logo, organization and field details.
     * No users, no submissions, no internal storage paths.
     */
    public function publicProfile(string $slug): JsonResponse
    {
        $account = Account::query()->where('slug', $slug)->firstOrFail();

        return $this->resource('workspaces', (string) $account->id, [
            'name' => $account->name,
            'slug' => $account->slug,
            'logo_url' => $account->logoUrl(),
            'organization' => $account->organizationDetails(),
            'field' => $account->fieldDetails(),
        ]);
    }

    /**
     * POST /api/workspaces/{account}/logo (multipart)
     *
     * Uploads the workspace logo (public disk) and records its path in
     * settings; a previously uploaded logo file is removed.
     */
    public function storeLogo(Request $request, int $account): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
        ]);

        $workspace = Account::query()->where('id', $account)->firstOrFail();

        $file = $request->file('logo');

        $path = $file->storeAs(
            sprintf('workspaces/%d', $workspace->id),
            sprintf('logo.%s', $file->getClientOriginalExtension() ?: 'png'),
            'public',
        );

        $settings = $workspace->settings ?? [];

        if (! empty($settings['logo_path']) && $settings['logo_path'] !== $path) {
            Storage::disk('public')->delete($settings['logo_path']);
        }

        $settings['logo_path'] = $path;
        $workspace->settings = $settings;
        $workspace->save();

        return $this->resource('workspaces', (string) $workspace->id, [
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'logo_url' => $workspace->logoUrl(),
        ]);
    }

    /**
     * DELETE /api/workspaces/{account}/logo
     */
    public function destroyLogo(Request $request, int $account): JsonResponse
    {
        $workspace = Account::query()->where('id', $account)->firstOrFail();

        $settings = $workspace->settings ?? [];

        if (! empty($settings['logo_path'])) {
            Storage::disk('public')->delete($settings['logo_path']);
            unset($settings['logo_path']);
            $workspace->settings = $settings;
            $workspace->save();
        }

        return $this->resource('workspaces', (string) $workspace->id, [
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'logo_url' => null,
        ]);
    }

    /**
     * DELETE /api/workspaces/{account}?confirm=true
     *
     * Destructive: cascades to roles, memberships, forms, and submissions.
     */
    public function destroy(Request $request, int $account): JsonResponse
    {
        if ($request->boolean('confirm') !== true) {
            return response()->json([
                'error' => 'Pass ?confirm=true to acknowledge that all data in this workspace will be permanently deleted',
            ], 422);
        }

        $workspace = Account::query()->where('id', $account)->firstOrFail();
        $workspace->delete();

        return response()->json(['data' => null]);
    }
}
