<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Form;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administration section: members, roles & permissions, workspaces, fields.
 */
class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $member;

    private Role $ownerRole;

    private Role $enumeratorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Org', 'slug' => 'test-org']);

        $this->ownerRole = Role::create([
            'account_id' => $this->account->id,
            'name' => 'Owner',
            'permissions' => config('tenancy.default_roles.Owner'),
        ]);

        $this->enumeratorRole = Role::create([
            'account_id' => $this->account->id,
            'name' => 'Enumerator',
            'permissions' => config('tenancy.default_roles.Enumerator'),
        ]);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
        $this->owner->accounts()->attach($this->account->id, [
            'role_id' => $this->ownerRole->id,
            'status' => 'active',
        ]);

        $this->member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'secret']);
        $this->member->accounts()->attach($this->account->id, [
            'role_id' => $this->enumeratorRole->id,
            'status' => 'active',
        ]);

        Form::create([
            'account_id' => $this->account->id,
            'name' => 'Survey',
            'slug' => 'survey',
            'schema' => [
                'fields' => [
                    ['name' => 'plot_code', 'label' => 'Plot code', 'type' => 'text', 'required' => true],
                    ['name' => 'yield', 'label' => 'Yield', 'type' => 'number'],
                ],
            ],
            'status' => 'active',
        ]);
    }

    private function tokenFor(User $user, array $permissions = []): string
    {
        return app(JwtService::class)->issue([
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'account_id' => $this->account->id,
            'account_name' => $this->account->name,
            'role' => 'Owner',
            'permissions' => $permissions,
        ], 600, 'access');
    }

    private function ownerHeaders(array $permissions = []): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($this->owner, $permissions)];
    }

    // -- Members -------------------------------------------------------------

    public function test_member_index_lists_workspace_members(): void
    {
        $response = $this->getJson(
            "/api/workspaces/{$this->account->id}/members",
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame(
            ['owner@example.com', 'member@example.com'],
            collect($response->json('data'))->map(fn ($row) => $row['attributes']['email'])->all(),
        );
    }

    public function test_invite_creates_user_and_returns_one_time_password(): void
    {
        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            ['email' => 'new@example.com', 'name' => 'New Person', 'role_id' => $this->enumeratorRole->id],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.email', 'new@example.com')
            ->assertJsonPath('meta.created_user', true);

        $tempPassword = $response->json('meta.temp_password');
        $this->assertNotNull($tempPassword);

        // The invited person can log in with the one-time password.
        $login = $this->postJson('/api/auth/login', [
            'email' => 'new@example.com',
            'password' => $tempPassword,
        ]);

        $login->assertOk()->assertJsonPath('active_account.id', $this->account->id);
    }

    public function test_invite_stores_optional_profile_fields(): void
    {
        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            [
                'email' => 'field@example.com',
                'name' => 'Field Person',
                'phone' => '08031234567',
                'address' => '12 Market Road',
                'city' => 'Kano',
                'state' => 'Kano',
                'date_of_engagement' => '2024-06-01',
            ],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.phone', '08031234567')
            ->assertJsonPath('data.attributes.address', '12 Market Road')
            ->assertJsonPath('data.attributes.city', 'Kano')
            ->assertJsonPath('data.attributes.state', 'Kano')
            ->assertJsonPath('data.attributes.date_of_engagement', '2024-06-01');

        $user = User::query()->where('email', 'field@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('08031234567', $user->phone);
        $this->assertSame('12 Market Road', $user->address);
        $this->assertSame('Kano', $user->city);
        $this->assertSame('Kano', $user->state);
        $this->assertSame('2024-06-01', $user->date_of_engagement?->toDateString());
    }

    public function test_invite_keeps_existing_profile_when_optional_fields_are_omitted(): void
    {
        $existing = User::query()->create([
            'name' => 'Elsewhere',
            'email' => 'elsewhere@example.com',
            'password' => 'secret',
            'phone' => '08090000000',
            'address' => '1 Central Area',
            'city' => 'Abuja',
            'state' => 'FCT',
            'date_of_engagement' => '2021-04-04',
        ]);

        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            ['email' => 'elsewhere@example.com'],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.phone', '08090000000')
            ->assertJsonPath('data.attributes.address', '1 Central Area')
            ->assertJsonPath('data.attributes.city', 'Abuja')
            ->assertJsonPath('data.attributes.state', 'FCT')
            ->assertJsonPath('data.attributes.date_of_engagement', '2021-04-04');

        $existing->refresh();

        $this->assertSame('08090000000', $existing->phone);
        $this->assertSame('1 Central Area', $existing->address);
        $this->assertSame('Abuja', $existing->city);
        $this->assertSame('FCT', $existing->state);
        $this->assertSame('2021-04-04', $existing->date_of_engagement?->toDateString());
    }

    public function test_rejected_invite_does_not_change_profile_fields(): void
    {
        $this->member->update([
            'phone' => '08011111111',
            'city' => 'Lagos',
        ]);

        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            [
                'email' => 'member@example.com',
                'phone' => '09999999999',
                'city' => 'Kano',
            ],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertUnprocessable();

        $this->member->refresh();

        $this->assertSame('08011111111', $this->member->phone);
        $this->assertSame('Lagos', $this->member->city);
    }

    public function test_member_index_includes_optional_profile_fields(): void
    {
        $this->member->update([
            'phone' => '08031234567',
            'address' => '12 Market Road',
            'city' => 'Kano',
            'state' => 'Kano',
            'date_of_engagement' => '2024-06-01',
        ]);

        $response = $this->getJson(
            "/api/workspaces/{$this->account->id}/members",
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk();

        $rows = collect($response->json('data'));
        $member = $rows->firstWhere('attributes.email', 'member@example.com');
        $owner = $rows->firstWhere('attributes.email', 'owner@example.com');

        $this->assertSame('08031234567', $member['attributes']['phone']);
        $this->assertSame('12 Market Road', $member['attributes']['address']);
        $this->assertSame('Kano', $member['attributes']['city']);
        $this->assertSame('Kano', $member['attributes']['state']);
        $this->assertSame('2024-06-01', $member['attributes']['date_of_engagement']);
        $this->assertNull($owner['attributes']['phone']);
        $this->assertNull($owner['attributes']['address']);
        $this->assertNull($owner['attributes']['city']);
        $this->assertNull($owner['attributes']['state']);
        $this->assertNull($owner['attributes']['date_of_engagement']);
    }

    public function test_member_profile_fields_can_be_updated(): void
    {
        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->member->id}",
            [
                'phone' => '08022222222',
                'address' => '4 Ring Road',
                'city' => 'Ibadan',
                'state' => 'Oyo',
                'date_of_engagement' => '2022-11-02',
            ],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.phone', '08022222222')
            ->assertJsonPath('data.attributes.address', '4 Ring Road')
            ->assertJsonPath('data.attributes.city', 'Ibadan')
            ->assertJsonPath('data.attributes.state', 'Oyo')
            ->assertJsonPath('data.attributes.date_of_engagement', '2022-11-02');

        $this->member->refresh();

        $this->assertSame('08022222222', $this->member->phone);
        $this->assertSame('4 Ring Road', $this->member->address);
        $this->assertSame('Ibadan', $this->member->city);
        $this->assertSame('Oyo', $this->member->state);
        $this->assertSame('2022-11-02', $this->member->date_of_engagement?->toDateString());
    }

    public function test_member_profile_fields_can_be_cleared(): void
    {
        $this->member->update([
            'phone' => '08022222222',
            'address' => '4 Ring Road',
            'city' => 'Ibadan',
            'state' => 'Oyo',
            'date_of_engagement' => '2022-11-02',
        ]);

        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->member->id}",
            [
                'phone' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'date_of_engagement' => null,
            ],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.phone', null)
            ->assertJsonPath('data.attributes.address', null)
            ->assertJsonPath('data.attributes.city', null)
            ->assertJsonPath('data.attributes.state', null)
            ->assertJsonPath('data.attributes.date_of_engagement', null);

        $this->member->refresh();

        $this->assertNull($this->member->phone);
        $this->assertNull($this->member->address);
        $this->assertNull($this->member->city);
        $this->assertNull($this->member->state);
        $this->assertNull($this->member->date_of_engagement);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidProfileFields(): array
    {
        return [
            'phone too long' => ['phone', str_repeat('1', 41), 'The phone field must not be greater than 40 characters.'],
            'address too long' => ['address', str_repeat('a', 256), 'The address field must not be greater than 255 characters.'],
            'city too long' => ['city', str_repeat('a', 121), 'The city field must not be greater than 120 characters.'],
            'state too long' => ['state', str_repeat('a', 121), 'The state field must not be greater than 120 characters.'],
            'engagement date invalid' => ['date_of_engagement', 'yesterday', 'The date of engagement field must be a valid date.'],
        ];
    }

    #[DataProvider('invalidProfileFields')]
    public function test_invite_rejects_invalid_profile_field(string $field, string $value, string $message): void
    {
        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            ['email' => 'invalid-profile@example.com', $field => $value],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $message);

        $this->assertNull(User::query()->where('email', 'invalid-profile@example.com')->first());
    }

    public function test_profile_update_rejects_an_invalid_engagement_date(): void
    {
        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->member->id}",
            ['date_of_engagement' => 'yesterday'],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertUnprocessable()
            ->assertJsonPath('errors.date_of_engagement.0', 'The date of engagement field must be a valid date.');

        $this->member->refresh();

        $this->assertNull($this->member->date_of_engagement);
    }

    public function test_invite_of_existing_member_is_rejected(): void
    {
        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/members",
            ['email' => 'member@example.com'],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertUnprocessable();

        $this->assertSame(2, Membership::query()->count());
    }

    public function test_member_role_and_status_update(): void
    {
        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->member->id}",
            ['role_id' => $this->ownerRole->id, 'status' => 'suspended'],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk()
            ->assertJsonPath('data.attributes.role', 'Owner')
            ->assertJsonPath('data.attributes.status', 'suspended');
    }

    public function test_last_admin_cannot_be_demoted_suspended_or_removed(): void
    {
        $demote = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->owner->id}",
            ['role_id' => $this->enumeratorRole->id],
            $this->ownerHeaders(['members.manage']),
        );
        $demote->assertUnprocessable();

        $suspend = $this->patchJson(
            "/api/workspaces/{$this->account->id}/members/{$this->owner->id}",
            ['status' => 'suspended'],
            $this->ownerHeaders(['members.manage']),
        );
        $suspend->assertUnprocessable();

        $remove = $this->deleteJson(
            "/api/workspaces/{$this->account->id}/members/{$this->owner->id}",
            [],
            $this->ownerHeaders(['members.manage']),
        );
        $remove->assertUnprocessable();

        $this->assertSame(2, Membership::query()->count());
    }

    public function test_non_admin_member_can_be_removed(): void
    {
        $response = $this->deleteJson(
            "/api/workspaces/{$this->account->id}/members/{$this->member->id}",
            [],
            $this->ownerHeaders(['members.manage']),
        );

        $response->assertOk();
        $this->assertSame(1, Membership::query()->count());
    }

    // -- Roles & permissions ---------------------------------------------------

    public function test_role_index_includes_catalog_and_member_counts(): void
    {
        $response = $this->getJson(
            "/api/workspaces/{$this->account->id}/roles",
            $this->ownerHeaders(['roles.manage']),
        );

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['meta' => ['permissions']]);

        $this->assertArrayHasKey('forms.manage', $response->json('meta.permissions'));
    }

    public function test_role_crud(): void
    {
        $create = $this->postJson(
            "/api/workspaces/{$this->account->id}/roles",
            ['name' => 'Reviewer', 'permissions' => ['submissions.read', 'dashboard.read']],
            $this->ownerHeaders(['roles.manage']),
        );
        $create->assertOk()->assertJsonPath('data.attributes.name', 'Reviewer');

        $roleId = $create->json('data.id');

        $update = $this->patchJson(
            "/api/workspaces/{$this->account->id}/roles/{$roleId}",
            ['name' => 'Senior Reviewer', 'permissions' => ['submissions.read']],
            $this->ownerHeaders(['roles.manage']),
        );
        $update->assertOk()->assertJsonPath('data.attributes.name', 'Senior Reviewer');

        $delete = $this->deleteJson(
            "/api/workspaces/{$this->account->id}/roles/{$roleId}",
            [],
            $this->ownerHeaders(['roles.manage']),
        );
        $delete->assertOk();
    }

    public function test_role_with_unknown_permission_is_rejected(): void
    {
        $response = $this->postJson(
            "/api/workspaces/{$this->account->id}/roles",
            ['name' => 'Bad', 'permissions' => ['superuser']],
            $this->ownerHeaders(['roles.manage']),
        );

        $response->assertUnprocessable();
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $response = $this->deleteJson(
            "/api/workspaces/{$this->account->id}/roles/{$this->ownerRole->id}",
            [],
            $this->ownerHeaders(['roles.manage']),
        );

        $response->assertUnprocessable();
        $this->assertNotNull(Role::query()->find($this->ownerRole->id));
    }

    public function test_only_admin_role_cannot_be_stripped_of_admin(): void
    {
        $permissions = array_values(array_diff(config('tenancy.default_roles.Owner'), ['accounts.manage']));

        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}/roles/{$this->ownerRole->id}",
            ['name' => 'Owner', 'permissions' => $permissions],
            $this->ownerHeaders(['roles.manage']),
        );

        $response->assertUnprocessable();
    }

    // -- Workspaces ------------------------------------------------------------

    public function test_workspace_list_shows_all_memberships_with_counts(): void
    {
        $response = $this->getJson('/api/workspaces', $this->ownerHeaders([]));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, $response->json('data.0.attributes.counts.members'));
        $this->assertTrue($response->json('data.0.attributes.active'));
    }

    public function test_workspace_creation_provisions_roles_and_owner(): void
    {
        $response = $this->postJson('/api/workspaces', ['name' => 'Fresh Org'], $this->ownerHeaders([]));

        $response->assertOk();

        $newAccount = Account::query()->where('name', 'Fresh Org')->first();
        $this->assertNotNull($newAccount);

        $this->assertCount(4, Role::query()->where('account_id', $newAccount->id)->get());

        $membership = Membership::query()
            ->where('account_id', $newAccount->id)
            ->where('user_id', $this->owner->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('Owner', $membership->role->name);
    }

    public function test_workspace_rename_and_delete(): void
    {
        $rename = $this->patchJson(
            "/api/workspaces/{$this->account->id}",
            ['name' => 'Renamed Org'],
            $this->ownerHeaders(['accounts.manage']),
        );
        $rename->assertOk()->assertJsonPath('data.attributes.name', 'Renamed Org');

        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => Form::query()->first()->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'X'],
        ]);

        // Without confirmation the delete is refused.
        $this->deleteJson(
            "/api/workspaces/{$this->account->id}",
            [],
            $this->ownerHeaders(['accounts.manage']),
        )->assertUnprocessable();

        $delete = $this->deleteJson(
            "/api/workspaces/{$this->account->id}?confirm=true",
            [],
            $this->ownerHeaders(['accounts.manage']),
        );
        $delete->assertOk();

        $this->assertNull(Account::query()->find($this->account->id));
        $this->assertSame(0, Submission::query()->count()); // cascaded
    }

    public function test_cannot_manage_a_workspace_that_is_not_the_token_workspace(): void
    {
        $foreign = Account::create(['name' => 'Foreign', 'slug' => 'foreign']);

        $rename = $this->patchJson(
            "/api/workspaces/{$foreign->id}",
            ['name' => 'Hacked'],
            $this->ownerHeaders(['accounts.manage']),
        );
        $rename->assertForbidden();

        $members = $this->getJson(
            "/api/workspaces/{$foreign->id}/members",
            $this->ownerHeaders(['members.manage']),
        );
        $members->assertForbidden();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    // -- Workspace customization: profile, logo, settings, branded login ------

    public function test_public_profile_returns_organization_details_without_internal_paths(): void
    {
        $this->account->update(['settings' => [
            'logo_path' => 'workspaces/1/secret.png',
            'organization' => ['name' => 'Test Org', 'email' => 'info@test.org', 'phone' => '123'],
            'field' => ['project_name' => 'Pilot', 'region' => 'Delta'],
        ]]);

        $response = $this->getJson('/api/workspaces/test-org/public-profile');

        $response->assertOk()
            ->assertJsonPath('data.attributes.name', 'Test Org')
            ->assertJsonPath('data.attributes.organization.email', 'info@test.org')
            ->assertJsonPath('data.attributes.field.region', 'Delta')
            ->assertJsonPath('data.attributes.logo_url', config('app.url').'/storage/workspaces/1/secret.png');

        // The internal storage path key must never leak publicly.
        $this->assertStringNotContainsString('logo_path', $response->getContent());
    }

    public function test_logo_upload_replaces_previous_and_can_be_removed(): void
    {
        Storage::fake('public');

        $first = $this->post(
            "/api/workspaces/{$this->account->id}/logo",
            ['logo' => UploadedFile::fake()->create('old.png', 5, 'image/png')],
            $this->ownerHeaders(['accounts.manage']),
        );
        $first->assertOk();

        $firstPath = $this->account->fresh()->settings['logo_path'];
        $this->assertNotNull($firstPath);
        $this->assertTrue(Storage::disk('public')->exists($firstPath));

        // Re-uploading replaces the stored file.
        $second = $this->post(
            "/api/workspaces/{$this->account->id}/logo",
            ['logo' => UploadedFile::fake()->create('new.png', 5, 'image/png')],
            $this->ownerHeaders(['accounts.manage']),
        );
        $second->assertOk();

        // One stable filename per workspace: re-upload replaces in place.
        $secondPath = $this->account->fresh()->settings['logo_path'];
        $this->assertSame($firstPath, $secondPath);
        $this->assertTrue(Storage::disk('public')->exists($secondPath));

        // Removing the logo deletes the file and clears the URL.
        $remove = $this->deleteJson(
            "/api/workspaces/{$this->account->id}/logo",
            [],
            $this->ownerHeaders(['accounts.manage']),
        );
        $remove->assertOk()->assertJsonPath('data.attributes.logo_url', null);
        $this->assertFalse(Storage::disk('public')->exists($secondPath));
    }

    public function test_settings_update_merges_and_preserves_logo_path(): void
    {
        $this->account->update(['settings' => ['logo_path' => 'workspaces/9/keep.png']]);

        $response = $this->patchJson(
            "/api/workspaces/{$this->account->id}",
            [
                'settings' => [
                    'organization' => ['name' => 'Renamed Org', 'phone' => '555'],
                    'field' => ['region' => 'North'],
                ],
            ],
            $this->ownerHeaders(['accounts.manage']),
        );

        $response->assertOk();

        $settings = $this->account->fresh()->settings;
        $this->assertSame('workspaces/9/keep.png', $settings['logo_path']);
        $this->assertSame('555', $settings['organization']['phone']);
        $this->assertSame('North', $settings['field']['region']);
    }

    public function test_branded_login_scopes_session_to_that_workspace(): void
    {
        $second = Account::create(['name' => 'Second Org', 'slug' => 'second-org']);
        $role = Role::create([
            'account_id' => $second->id,
            'name' => 'Enumerator',
            'permissions' => config('tenancy.default_roles.Enumerator'),
        ]);
        $this->member->accounts()->attach($second->id, [
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        // Member of the workspace: login pinned to it lands there.
        $ok = $this->postJson('/api/auth/login', [
            'email' => 'member@example.com',
            'password' => 'secret',
            'account_id' => $second->id,
        ]);
        $ok->assertOk()->assertJsonPath('active_account.id', $second->id);
        $this->assertArrayHasKey('logo_url', $ok->json('active_account'));

        // Non-member of the workspace: rejected.
        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret']);
        $rejected = $this->postJson('/api/auth/login', [
            'email' => 'stranger@example.com',
            'password' => 'secret',
            'account_id' => $second->id,
        ]);
        $rejected->assertForbidden();
    }

    // -- Fields ------------------------------------------------------------------

    public function test_fields_index_flattens_all_form_fields(): void
    {
        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => Form::query()->first()->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'WA-1'],
        ]);

        $response = $this->getJson(
            "/api/workspaces/{$this->account->id}/fields",
            $this->ownerHeaders(['forms.manage']),
        );

        $response->assertOk()->assertJsonCount(2, 'data');

        $plot = collect($response->json('data'))
            ->firstWhere('attributes.name', 'plot_code');

        $this->assertNotNull($plot);
        $this->assertSame('Survey', $plot['attributes']['form_name']);
        $this->assertSame(1, $plot['attributes']['form_submissions']);
    }
}
