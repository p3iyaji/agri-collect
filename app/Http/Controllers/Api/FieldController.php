<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-form field overview for the administration UI.
 *
 * Fields live inside each form's JSON schema, so this endpoint flattens
 * every field of every workspace form into one list (with per-form
 * submission counts). Editing itself happens through the form builder
 * (PUT /api/forms/{form}).
 */
class FieldController extends Controller
{
    /**
     * GET /api/workspaces/{account}/fields[?form_id=]
     */
    public function index(Request $request, int $account): JsonResponse
    {
        $query = Form::query()->where('account_id', $account)->orderBy('name');

        if ($request->filled('form_id')) {
            $query->where('id', (int) $request->input('form_id'));
        }

        $forms = $query->get();

        $submissionCounts = Submission::query()
            ->where('account_id', $account)
            ->selectRaw('form_id, COUNT(*) as count')
            ->groupBy('form_id')
            ->pluck('count', 'form_id');

        $fields = $forms->flatMap(function (Form $form) use ($submissionCounts) {
            return collect($form->schema['fields'] ?? [])->map(function (array $field) use ($form, $submissionCounts) {
                return [
                    'type' => 'fields',
                    'id' => $form->id.'.'.($field['name'] ?? ''),
                    'attributes' => [
                        'form_id' => (int) $form->id,
                        'form_name' => $form->name,
                        'form_status' => $form->status,
                        'name' => $field['name'] ?? null,
                        'label' => $field['label'] ?? null,
                        'type' => $field['type'] ?? null,
                        'required' => (bool) ($field['required'] ?? false),
                        'options' => $field['options'] ?? null,
                        'visible_if' => $field['visible_if'] ?? null,
                        'form_submissions' => (int) ($submissionCounts[$form->id] ?? 0),
                    ],
                ];
            });
        });

        return $this->collection($fields->all(), $fields->count());
    }
}
