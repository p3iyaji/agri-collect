<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Form;
use App\Models\Role;
use App\Models\Submission;
use App\Models\SubmissionAttachment;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV import into a workspace form (idempotent, per-row validation).
 */
class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Org', 'slug' => 'test-org']);

        $role = Role::create([
            'account_id' => $this->account->id,
            'name' => 'Owner',
            'permissions' => [
                'submissions.read',
                'submissions.write',
                'dashboard.read',
            ],
        ]);

        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret',
        ]);
        $this->owner->accounts()->attach($this->account->id, [
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->form = Form::create([
            'account_id' => $this->account->id,
            'name' => 'Survey',
            'slug' => 'survey',
            'schema' => [
                'fields' => [
                    ['name' => 'plot_code', 'label' => 'Plot code', 'type' => 'text', 'required' => true],
                    ['name' => 'yield', 'label' => 'Yield (kg/ha)', 'type' => 'number'],
                    ['name' => 'variety', 'label' => 'Variety', 'type' => 'select', 'options' => ['A', 'B']],
                ],
            ],
            'status' => 'active',
        ]);
    }

    private function tokenFor(array $permissions = []): string
    {
        return app(JwtService::class)->issue([
            'sub' => $this->owner->id,
            'email' => $this->owner->email,
            'name' => $this->owner->name,
            'account_id' => $this->account->id,
            'account_name' => $this->account->name,
            'role' => 'Owner',
            'permissions' => $permissions,
        ], 600, 'access');
    }

    private function headers(array $permissions = ['submissions.write']): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($permissions)];
    }

    private function import(string $csv, array $permissions = ['submissions.write'], ?int $formId = null)
    {
        return $this->post(
            "/api/workspaces/{$this->account->id}/imports/submissions",
            [
                'form_id' => $formId ?? $this->form->id,
                'csv' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ],
            $this->headers($permissions),
        );
    }

    public function test_import_creates_submissions_from_csv(): void
    {
        $response = $this->import("plot_code,yield\nWA-1,100\nWA-2,200\n");

        $response->assertOk()
            ->assertJsonPath('data.attributes.imported', 2)
            ->assertJsonPath('data.attributes.duplicates', 0)
            ->assertJsonPath('data.attributes.errors', 0);

        $this->assertSame(2, Submission::query()->count());

        $first = Submission::query()->where('payload', 'like', '%WA-1%')->first();
        $this->assertNotNull($first);
        // json storage drops zero fractions, so 100 may round-trip as int.
        $this->assertEquals(100.0, $first->payload['yield']);
    }

    public function test_imported_submissions_are_listed_with_form_name(): void
    {
        $this->import("plot_code,yield\nWA-1,100\n")->assertOk();

        $submission = Submission::query()->first();

        SubmissionAttachment::query()->create([
            'account_id' => $this->account->id,
            'submission_id' => $submission->getKey(),
            'field_name' => 'field_photo',
            'path' => 'photos/field.jpg',
        ]);

        $response = $this->getJson('/api/submissions', $this->headers(['submissions.read']));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'submissions')
            ->assertJsonPath('data.0.id', (string) $submission->getKey())
            ->assertJsonPath('data.0.attributes.form_name', 'Survey')
            ->assertJsonPath('data.0.attributes.payload.plot_code', 'WA-1')
            ->assertJsonPath('data.0.attributes.attachment_fields', ['field_photo']);
    }

    public function test_reimport_of_same_csv_is_idempotent(): void
    {
        $csv = "plot_code,yield\nWA-1,100\nWA-2,200\n";

        $this->import($csv)->assertOk()->assertJsonPath('data.attributes.imported', 2);

        $second = $this->import($csv);
        $second->assertOk()
            ->assertJsonPath('data.attributes.imported', 0)
            ->assertJsonPath('data.attributes.duplicates', 2);

        $this->assertSame(2, Submission::query()->count());
    }

    public function test_csv_local_uuid_column_is_honored(): void
    {
        $csv = "local_uuid,plot_code\nmy-uuid-1,WA-1\nmy-uuid-1,WA-2\n";

        $response = $this->import($csv);

        $response->assertOk()
            ->assertJsonPath('data.attributes.imported', 1)
            ->assertJsonPath('data.attributes.duplicates', 1);

        $this->assertSame(1, Submission::query()->count());
        $this->assertSame('WA-1', Submission::query()->first()->payload['plot_code']);
    }

    public function test_invalid_rows_are_reported_per_row(): void
    {
        $csv = "plot_code,yield,variety\nWA-1,abc,B\n,50,A\nWA-3,10,NOPE\nWA-4,55,A\n";

        $response = $this->import($csv);

        $response->assertOk()
            ->assertJsonPath('data.attributes.imported', 1)
            ->assertJsonPath('data.attributes.errors', 3);

        $rows = collect($response->json('meta.error_rows'));
        $this->assertSame([2, 3, 4], $rows->pluck('row')->all());
        $this->assertStringContainsString('number', $rows->firstWhere('row', 2)['error']);
        $this->assertStringContainsString('Plot code', $rows->firstWhere('row', 3)['error']);
        $this->assertStringContainsString('Variety', $rows->firstWhere('row', 4)['error']);
    }

    public function test_created_at_column_is_parsed(): void
    {
        $response = $this->import("plot_code,created_at\nWA-1,2024-05-01T08:30:00Z\n");

        $response->assertOk()->assertJsonPath('data.attributes.imported', 1);

        $submission = Submission::query()->first();
        $this->assertSame('2024-05-01', $submission->created_at->format('Y-m-d'));
    }

    public function test_unknown_form_is_rejected(): void
    {
        $response = $this->import("plot_code\nWA-1\n", formId: 99999);

        $response->assertUnprocessable();
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_permission_is_required(): void
    {
        $response = $this->import("plot_code\nWA-1\n", permissions: ['submissions.read']);

        $response->assertForbidden();
    }

    public function test_cannot_import_into_a_foreign_workspace(): void
    {
        $foreign = Account::create(['name' => 'Foreign', 'slug' => 'foreign']);

        $response = $this->post(
            "/api/workspaces/{$foreign->id}/imports/submissions",
            [
                'form_id' => $this->form->id,
                'csv' => UploadedFile::fake()->createWithContent('import.csv', "plot_code\nWA-1\n"),
            ],
            $this->headers(),
        );

        $response->assertForbidden();
    }

    public function test_blank_file_is_rejected(): void
    {
        $response = $this->import("   \n");

        $response->assertUnprocessable();
    }
}
