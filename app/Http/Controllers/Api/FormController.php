<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFormRequest;
use App\Http\Resources\FormResource;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Form CRUD, globally scoped to the active workspace by the
 * BelongsToAccount trait — a form from another tenant is a 404 here.
 */
class FormController extends Controller
{
    /**
     * GET /api/forms?status=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Form::query()->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $forms = $query->get();

        return $this->collection(
            $forms->map(fn (Form $form) => FormResource::item($form))->all(),
        );
    }

    /**
     * POST /api/forms
     */
    public function store(SaveFormRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->validateSchemaFields($data['schema']);

        $form = Form::query()->create($this->attributes($data));

        return $this->resource('forms', (string) $form->getKey(), FormResource::attributes($form));
    }

    /**
     * GET /api/forms/{form}
     */
    public function show(int $form): JsonResponse
    {
        $formModel = Form::query()->findOrFail($form);

        return $this->resource('forms', (string) $formModel->getKey(), FormResource::attributes($formModel));
    }

    /**
     * PUT /api/forms/{form}
     *
     * Bumps the schema version whenever the schema itself changes so
     * offline clients can detect stale cached templates.
     */
    public function update(SaveFormRequest $request, int $form): JsonResponse
    {
        $formModel = Form::query()->findOrFail($form);
        $data = $request->validated();

        $this->validateSchemaFields($data['schema']);

        $formModel->fill($this->attributes($data));

        if (json_encode($formModel->schema) !== json_encode($data['schema'])) {
            $formModel->version = (int) $formModel->version + 1;
        }

        $formModel->save();

        return $this->resource('forms', (string) $formModel->getKey(), FormResource::attributes($formModel));
    }

    /**
     * DELETE /api/forms/{form}
     *
     * Archiving keeps historical submissions and exports intact.
     */
    public function destroy(int $form): JsonResponse
    {
        $formModel = Form::query()->findOrFail($form);
        $formModel->update(['status' => 'archived']);

        return $this->resource('forms', (string) $formModel->getKey(), FormResource::attributes($formModel));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return [
            'account_id' => $this->activeAccountId(),
            'name' => $data['name'],
            'slug' => $data['slug'] ?? (string) Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'schema' => $data['schema'],
            'status' => $data['status'] ?? 'draft',
        ];
    }

    /**
     * Validate skip-logic blocks that are too structural for rules().
     *
     * @param  array<string, mixed>  $schema
     */
    protected function validateSchemaFields(array $schema): void
    {
        $names = collect($schema['fields'] ?? [])
            ->map(fn (array $field) => $field['name'] ?? null)
            ->filter()
            ->values();

        if ($names->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['schema' => 'Field names must be unique']);
        }

        foreach ($schema['fields'] ?? [] as $field) {
            $visibleIf = $field['visible_if'] ?? null;

            if ($visibleIf === null) {
                continue;
            }

            if (blank($visibleIf['field'] ?? null) || blank($visibleIf['op'] ?? null)) {
                throw ValidationException::withMessages(['schema' => 'visible_if requires both "field" and "op"']);
            }

            if (! $names->contains($visibleIf['field'])) {
                throw ValidationException::withMessages([
                    'schema' => sprintf('visible_if references unknown field "%s"', $visibleIf['field']),
                ]);
            }
        }
    }
}
