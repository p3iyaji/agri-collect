<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace-scoped exports.
 *
 * The query is built under the BelongsToAccount global scope, so only the
 * active tenant's rows can ever be exported. Rows are streamed with a
 * cursor, keeping memory flat for arbitrarily large workspaces.
 */
class ExportController extends Controller
{
    /**
     * GET /api/workspaces/{account}/exports/submissions?format=csv
     *     &form_id=&date_from=&date_to=
     */
    public function submissions(Request $request, int $account): Response
    {
        $formId = $request->integer('form_id') ?: null;

        $form = $formId === null ? null : Form::query()->where('id', $formId)->first();

        if ($formId !== null && $form === null) {
            return response()->json(['error' => 'Form not found'], 404);
        }

        // Scope explicitly by the (validated) route parameter: the cursor
        // is iterated when the stream is sent, i.e. after the request
        // finished and Tenancy::forget() ran, so the global scope would
        // no longer apply here.
        $query = Submission::query()
            ->where('account_id', $account)
            ->when($formId !== null, fn ($q) => $q->where('form_id', $formId))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderBy('id');

        $columns = $this->formColumns($formId);

        $header = array_merge(
            ['id', 'form', 'local_uuid', 'submitted_by', 'gps_lat', 'gps_lng', 'gps_accuracy', 'gps_source', 'synced_at', 'created_at'],
            array_map(fn (string $column) => 'field:'.$column, $columns),
        );

        $formNames = Form::query()->pluck('name', 'id')->all();
        $userNames = User::query()->pluck('name', 'id')->all();

        $filename = sprintf(
            'submissions%s_%s.csv',
            $form !== null ? '-'.$form->slug : '',
            now()->format('Ymd_His'),
        );

        return response()->streamDownload(
            function () use ($query, $header, $columns, $formNames, $userNames): void {
                $handle = fopen('php://output', 'w');

                // UTF-8 BOM so Excel renders the file correctly.
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $header);

                $query->cursor()->each(
                    function (Submission $submission) use ($handle, $columns, $formNames, $userNames): void {
                        $row = [
                            $submission->id,
                            $formNames[$submission->form_id] ?? '',
                            $submission->local_uuid,
                            $submission->submitted_by !== null ? ($userNames[$submission->submitted_by] ?? '') : '',
                            $submission->gps_lat,
                            $submission->gps_lng,
                            $submission->gps_accuracy,
                            $submission->gps_source,
                            $submission->synced_at?->toIso8601String(),
                            $submission->created_at?->toIso8601String(),
                        ];

                        foreach ($columns as $column) {
                            $row[] = $submission->payload[$column] ?? '';
                        }

                        fputcsv($handle, $row);
                    },
                );

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Field names to export, taken from the active form schema(s).
     *
     * @return list<string>
     */
    protected function formColumns(?int $formId): array
    {
        $forms = Form::query()
            ->where('status', 'active')
            ->when($formId !== null, fn ($q) => $q->where('id', $formId))
            ->get();

        return $forms
            ->flatMap(fn (Form $form) => collect($form->schema['fields'] ?? [])->pluck('name'))
            ->filter()
            ->unique()
            ->values()
            ->take(60)
            ->all();
    }
}
