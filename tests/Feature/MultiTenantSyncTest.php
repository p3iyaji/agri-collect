<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Form;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the core guarantees of the platform:
 * - JWT login / refresh / workspace switching
 * - idempotent offline sync (no duplicates on retry)
 * - tenant isolation via the BelongsToAccount global scope
 * - workspace-scoped dashboard aggregation and CSV export
 */
class MultiTenantSyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Form $form;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Org', 'slug' => 'test-org']);

        $this->role = Role::create([
            'account_id' => $this->account->id,
            'name' => 'Enumerator',
            'permissions' => ['submissions.read', 'submissions.write', 'dashboard.read', 'exports.read'],
        ]);

        $this->user = User::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'secret',
        ]);

        $this->user->accounts()->attach($this->account->id, [
            'role_id' => $this->role->id,
            'status' => 'active',
        ]);

        $this->form = Form::create([
            'account_id' => $this->account->id,
            'name' => 'Test Form',
            'slug' => 'test-form',
            'schema' => [
                'fields' => [
                    ['name' => 'plot_code', 'label' => 'Plot code', 'type' => 'text', 'required' => true],
                    ['name' => 'yield', 'label' => 'Yield (kg/ha)', 'type' => 'number'],
                ],
            ],
            'status' => 'active',
        ]);
    }

    /**
     * Mint an access token the same way AuthController does.
     */
    private function tokenFor(User $user, Account $account, array $permissions = []): string
    {
        return app(JwtService::class)->issue([
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'account_id' => $account->id,
            'account_name' => $account->name,
            'role' => $this->role->name,
            'permissions' => $permissions,
        ], 600, 'access');
    }

    private function otherWorkspace(): array
    {
        $other = Account::create(['name' => 'Other Org', 'slug' => 'other-org']);
        $intruder = User::create([
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'secret',
        ]);
        $role = Role::create([
            'account_id' => $other->id,
            'name' => 'Enumerator',
            'permissions' => ['submissions.write', 'submissions.read', 'exports.read', 'dashboard.read'],
        ]);
        $intruder->accounts()->attach($other->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$other, $intruder];
    }

    public function test_login_returns_token_pair_embedded_with_workspace(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'tester@example.com',
            'password' => 'secret',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'user', 'active_account', 'accounts']);

        $this->assertSame($this->account->id, $response->json('active_account.id'));
        $this->assertContains('submissions.write', $response->json('active_account.permissions'));
    }

    public function test_sync_is_idempotent(): void
    {
        $token = $this->tokenFor($this->user, $this->account, ['submissions.write']);
        $uuid = (string) Str::uuid();

        $batch = [
            'submissions' => [[
                'local_uuid' => $uuid,
                'form_id' => $this->form->id,
                'payload' => ['plot_code' => 'WA-001', 'yield' => 320.5],
                'gps' => ['lat' => 9.1, 'lng' => 7.5, 'accuracy' => 12, 'source' => 'gps'],
                'created_at' => now()->toIso8601String(),
            ]],
        ];

        $first = $this->postJson('/api/sync/submissions', $batch, ['Authorization' => "Bearer $token"]);
        $first->assertOk()->assertJsonPath('results.0.status', 'created');

        // Retry of the same batch (e.g. after a lost response).
        $second = $this->postJson('/api/sync/submissions', $batch, ['Authorization' => "Bearer $token"]);
        $second->assertOk()->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, Submission::where('local_uuid', $uuid)->count());
        $this->assertSame($first->json('results.0.id'), $second->json('results.0.id'));
    }

    public function test_unknown_form_is_reported_per_item(): void
    {
        $token = $this->tokenFor($this->user, $this->account, ['submissions.write']);

        $response = $this->postJson('/api/sync/submissions', [
            'submissions' => [[
                'local_uuid' => (string) Str::uuid(),
                'form_id' => 99999,
                'payload' => [],
            ]],
        ], ['Authorization' => "Bearer $token"]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.id', null);

        $this->assertSame(0, Submission::count());
    }

    public function test_submission_cannot_reference_another_workspaces_form(): void
    {
        [$other, $intruder] = $this->otherWorkspace();
        $token = $this->tokenFor($intruder, $other, ['submissions.write']);

        // form_id belongs to THIS account; for the intruder's tenant it
        // simply does not exist.
        $response = $this->postJson('/api/sync/submissions', [
            'submissions' => [[
                'local_uuid' => (string) Str::uuid(),
                'form_id' => $this->form->id,
                'payload' => ['plot_code' => 'X'],
            ]],
        ], ['Authorization' => "Bearer $token"]);

        $response->assertOk()->assertJsonPath('results.0.status', 'error');

        $this->assertSame(0, Submission::count());
    }

    public function test_dashboard_aggregates_only_active_workspace(): void
    {
        foreach (['WA-1' => 100.0, 'WA-2' => 200.0] as $code => $yield) {
            Submission::create([
                'account_id' => $this->account->id,
                'form_id' => $this->form->id,
                'local_uuid' => (string) Str::uuid(),
                'payload' => ['plot_code' => $code, 'yield' => $yield],
                'submitted_by' => $this->user->id,
            ]);
        }

        $token = $this->tokenFor($this->user, $this->account, ['dashboard.read']);

        $summary = $this->getJson('/api/dashboard/summary', ['Authorization' => "Bearer $token"]);
        $summary->assertOk()->assertJsonPath('data.attributes.submissions.total', 2);

        $stats = $this->getJson(
            "/api/dashboard/field-stats?form_id={$this->form->id}&field=yield",
            ['Authorization' => "Bearer $token"],
        );

        $stats->assertOk()
            ->assertJsonPath('data.attributes.kind', 'numeric')
            ->assertJsonPath('data.attributes.count', 2)
            ->assertJsonPath('data.attributes.average', 150); // json_encode drops zero fractions
    }

    public function test_switch_account_reissues_token_with_new_tenant(): void
    {
        $second = Account::create(['name' => 'Second Org', 'slug' => 'second-org']);
        $ownerRole = Role::create([
            'account_id' => $second->id,
            'name' => 'Owner',
            'permissions' => ['dashboard.read', 'forms.manage', 'exports.read'],
        ]);
        $this->user->accounts()->attach($second->id, [
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        $token = $this->tokenFor($this->user, $this->account, ['submissions.write']);

        $response = $this->postJson('/api/auth/switch-account', [
            'account_id' => $second->id,
        ], ['Authorization' => "Bearer $token"]);

        $response->assertOk()
            ->assertJsonPath('active_account.id', $second->id)
            ->assertJsonPath('active_account.role', 'Owner')
            ->assertJsonPath('accounts.1.active', true);
    }

    public function test_cannot_switch_to_a_workspace_the_user_does_not_belong_to(): void
    {
        $token = $this->tokenFor($this->user, $this->account, []);
        $foreign = Account::create(['name' => 'Foreign', 'slug' => 'foreign']);

        $response = $this->postJson('/api/auth/switch-account', [
            'account_id' => $foreign->id,
        ], ['Authorization' => "Bearer $token"]);

        $response->assertForbidden();
    }

    public function test_submission_list_counts_each_form_for_the_active_filters(): void
    {
        $second = Form::create([
            'account_id' => $this->account->id,
            'name' => 'Second Form',
            'slug' => 'second-form',
            'schema' => ['fields' => [
                ['name' => 'plot_code', 'label' => 'Plot', 'type' => 'text'],
            ]],
            'status' => 'active',
        ]);

        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $this->form->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'OLD'],
            'created_at' => now()->subDays(10),
        ]);
        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $this->form->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'NEW-A'],
            'created_at' => now(),
        ]);
        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $second->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'NEW-B'],
            'created_at' => now(),
        ]);

        $token = $this->tokenFor($this->user, $this->account, ['submissions.read']);

        $response = $this->getJson(
            '/api/submissions?per_page=1&from='.now()->toDateString(),
            ['Authorization' => "Bearer $token"],
        );

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');

        $counts = collect($response->json('meta.form_counts'))->pluck('count', 'form_id');

        $this->assertSame(1, $counts[$this->form->id]);
        $this->assertSame(1, $counts[$second->id]);
    }

    public function test_export_csv_for_one_form_omits_other_forms(): void
    {
        $second = Form::create([
            'account_id' => $this->account->id,
            'name' => 'Second Form',
            'slug' => 'second-form',
            'schema' => ['fields' => [
                ['name' => 'plot_code', 'label' => 'Plot', 'type' => 'text'],
            ]],
            'status' => 'active',
        ]);

        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $this->form->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'KEEP-OUT'],
        ]);
        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $second->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'KEEP-IN'],
        ]);

        $token = $this->tokenFor($this->user, $this->account, ['exports.read']);

        $response = $this->get(
            "/api/workspaces/{$this->account->id}/exports/submissions?form_id={$second->id}",
            ['Authorization' => "Bearer $token"],
        );

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('KEEP-IN', $content);
        $this->assertStringNotContainsString('KEEP-OUT', $content);
        $this->assertStringContainsString(
            'second-form',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_export_csv_respects_workspace_scope(): void
    {
        Submission::create([
            'account_id' => $this->account->id,
            'form_id' => $this->form->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'MINE-001', 'yield' => 10],
        ]);

        [$other, $intruder] = $this->otherWorkspace();
        $otherForm = Form::create([
            'account_id' => $other->id,
            'name' => 'Other Form',
            'slug' => 'other-form',
            'schema' => ['fields' => [
                ['name' => 'plot_code', 'label' => 'Plot', 'type' => 'text'],
            ]],
            'status' => 'active',
        ]);
        Submission::create([
            'account_id' => $other->id,
            'form_id' => $otherForm->id,
            'local_uuid' => (string) Str::uuid(),
            'payload' => ['plot_code' => 'THEIRS-001'],
        ]);

        $token = $this->tokenFor($this->user, $this->account, ['exports.read']);

        $response = $this->get(
            "/api/workspaces/{$this->account->id}/exports/submissions",
            ['Authorization' => "Bearer $token"],
        );

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('MINE-001', $content);
        $this->assertStringNotContainsString('THEIRS-001', $content);
    }

    public function test_export_rejects_url_workspace_that_differs_from_token(): void
    {
        [$other] = $this->otherWorkspace();
        $token = $this->tokenFor($this->user, $this->account, ['exports.read']);

        $response = $this->get(
            "/api/workspaces/{$other->id}/exports/submissions",
            ['Authorization' => "Bearer $token"],
        );

        $response->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/forms')->assertUnauthorized();
        $this->postJson('/api/sync/submissions', ['submissions' => []])->assertUnauthorized();
    }
}
