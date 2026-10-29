<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\SubmissionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Import submissions from a CSV file into a workspace form.
 *
 * The first row is a header row. Columns are matched to form fields by
 * field name or label (case-insensitive). Two reserved columns are
 * understood:
 *
 *   - local_uuid  idempotency key. When the column is absent a
 *                 deterministic key is derived from form + row content,
 *                 so re-importing the identical file creates no copies.
 *   - created_at  submission date (anything Carbon can parse).
 *
 * Rows are written through the same SubmissionWriter used by the
 * offline sync path, so the receipt + unique(account_id, local_uuid)
 * index guarantees exactly-once semantics per row.
 */
class ImportController extends Controller
{
    public const MAX_ROWS = 5000;

    public const MAX_ERROR_DETAIL = 100;

    public function __construct(protected SubmissionWriter $writer) {}

    /**
     * POST /api/workspaces/{account}/imports/submissions (multipart)
     *
     * Fields: form_id, csv
     */
    public function submissions(Request $request, int $account): JsonResponse
    {
        $data = $request->validate([
            'form_id' => ['required', 'integer'],
            'csv' => ['required', 'file', 'mimes:csv,txt,text', 'max:5120'],
        ]);

        $form = Form::query()->where('id', (int) $data['form_id'])->first();

        if ($form === null) {
            return response()->json(['error' => 'Unknown form in this workspace'], 422);
        }

        $handle = fopen($request->file('csv')->getRealPath(), 'rb');

        if ($handle === false) {
            return response()->json(['error' => 'Unable to read the uploaded file'], 422);
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return response()->json(['error' => 'CSV file is empty'], 422);
            }

            $header = array_map(fn ($cell) => trim((string) ($cell ?? '')), $header);
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            if (count(array_filter($header, fn ($cell) => $cell !== '')) === 0) {
                return response()->json(['error' => 'CSV file is empty'], 422);
            }

            $fields = collect($form->schema['fields'] ?? []);
            $mapping = $this->mapColumns($fields, $header);

            $imported = 0;
            $duplicates = 0;
            $errors = [];
            $processed = 0;
            // Row 1 is the header, so data rows start at 2 (as the user
            // sees them in the file).
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->isBlankRow($row)) {
                    continue;
                }

                if ($processed >= self::MAX_ROWS) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'error' => sprintf('Import limit of %d rows reached; remaining rows were skipped', self::MAX_ROWS),
                    ];
                    break;
                }

                $processed++;
                $result = $this->importRow($row, $header, $mapping, $fields, $form, $account);

                match ($result['status']) {
                    'created' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $errors[] = ['row' => $rowNumber, 'error' => $result['error']],
                };
            }
        } finally {
            fclose($handle);
        }

        return response()->json([
            'data' => [
                'type' => 'import-results',
                'id' => sprintf('%d:%d', $account, $form->id),
                'attributes' => [
                    'form_id' => (int) $form->id,
                    'form_name' => $form->name,
                    'total_rows' => $processed,
                    'imported' => $imported,
                    'duplicates' => $duplicates,
                    'errors' => count($errors),
                    'column_mapping' => $mapping,
                ],
            ],
            'meta' => [
                'error_rows' => array_slice($errors, 0, self::MAX_ERROR_DETAIL),
                'error_rows_skipped' => max(0, count($errors) - self::MAX_ERROR_DETAIL),
            ],
        ]);
    }

    /**
     * Map CSV header cells to form field names (by name or label,
     * case-insensitive). Unmatched columns are simply ignored.
     *
     * @param  list<string>  $header
     * @return array<string, string> csv column => form field name
     */
    protected function mapColumns(Collection $fields, array $header): array
    {
        $mapping = [];

        foreach ($header as $column) {
            if ($column === '' || in_array($column, ['local_uuid', 'created_at'], true)) {
                continue;
            }

            $field = $fields->first(function ($candidate) use ($column): bool {
                return strcasecmp((string) ($candidate['name'] ?? ''), $column) === 0
                    || strcasecmp((string) ($candidate['label'] ?? ''), $column) === 0;
            });

            if ($field !== null) {
                $mapping[$column] = (string) $field['name'];
            }
        }

        return $mapping;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  list<string>  $header
     * @param  array<string, string>  $mapping
     * @return array{status: string, id: ?int, error: ?string}
     */
    protected function importRow(array $row, array $header, array $mapping, Collection $fields, Form $form, int $accountId): array
    {
        $cells = [];

        foreach ($header as $index => $column) {
            $cells[$column] = trim((string) ($row[$index] ?? ''));
        }

        $payload = [];
        $problems = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? '');
            $type = (string) ($field['type'] ?? 'text');

            $column = null;

            foreach ($mapping as $csvColumn => $fieldName) {
                if ($fieldName === $name) {
                    $column = $csvColumn;
                    break;
                }
            }

            $value = $column !== null && $cells[$column] !== '' ? $cells[$column] : null;

            if ($value === null) {
                if (! empty($field['required'])) {
                    $problems[] = sprintf('missing required field "%s"', $label ?: $name);
                }

                continue;
            }

            if ($type === 'number' && ! is_numeric($value)) {
                $problems[] = sprintf('field "%s" must be a number (got "%s")', $label ?: $name, $value);

                continue;
            }

            $options = $field['options'] ?? null;

            if (is_array($options) && $options !== [] && ! in_array($value, $options, true)) {
                $problems[] = sprintf('field "%s" value "%s" is not one of the allowed options', $label ?: $name, $value);

                continue;
            }

            $payload[$name] = $type === 'number' ? (float) $value : $value;
        }

        if ($problems !== []) {
            return ['status' => 'error', 'id' => null, 'error' => implode('; ', $problems)];
        }

        $localUuid = isset($cells['local_uuid']) && $cells['local_uuid'] !== ''
            ? $cells['local_uuid']
            : $this->deterministicUuid((int) $form->id, $payload);

        $createdAt = null;

        if (isset($cells['created_at']) && $cells['created_at'] !== '') {
            try {
                $createdAt = Carbon::parse($cells['created_at']);
            } catch (\Throwable) {
                return [
                    'status' => 'error',
                    'id' => null,
                    'error' => sprintf('unparseable created_at "%s"', $cells['created_at']),
                ];
            }
        }

        $result = $this->writer->upsert(
            $accountId,
            $form,
            mb_substr($localUuid, 0, 36),
            $payload,
            [],
            $createdAt,
            $this->currentUser()?->getKey(),
        );

        return ['status' => $result['status'], 'id' => $result['id'], 'error' => null];
    }

    /**
     * Stable, UUID-shaped key (fits the char(36) column) derived from the
     * form and the row content — re-importing identical rows is a no-op.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deterministicUuid(int $formId, array $payload): string
    {
        $hash = hash('sha256', $formId.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /**
     * @param  array<int, mixed>  $row
     */
    protected function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
