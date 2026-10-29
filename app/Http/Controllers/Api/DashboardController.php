<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregated, workspace-scoped dashboard data.
 *
 * All queries run through the BelongsToAccount global scope, so every
 * number is automatically limited to the workspace in the JWT. Field
 * aggregations use driver-aware JSON extraction (MySQL / SQLite).
 */
class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary
     * GET /api/workspaces/{account}/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $total = Submission::query()->count();
        $last7Days = Submission::query()
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->count();
        $today = Submission::query()->whereDate('created_at', today())->count();
        $enumerators = Submission::query()->distinct()->count('submitted_by');

        $formNames = Form::query()->pluck('name', 'id')->all();

        // groupBy example: submissions per form
        $byForm = Submission::query()
            ->selectRaw('form_id, COUNT(*) as count')
            ->groupBy('form_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'form_id' => (int) $row->form_id,
                'name' => $formNames[$row->form_id] ?? sprintf('Form #%d', $row->form_id),
                'count' => (int) $row->count,
            ])
            ->values();

        // groupBy example: submissions per day (last 30 days)
        $byDay = Submission::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'count' => (int) $row->count,
            ])
            ->values();

        return $this->resource('dashboard-summaries', (string) $this->activeAccountId(), [
            'submissions' => [
                'total' => $total,
                'last_7_days' => $last7Days,
                'today' => $today,
                'enumerators' => $enumerators,
            ],
            'forms' => [
                'active' => Form::query()->where('status', 'active')->count(),
            ],
            'by_form' => $byForm,
            'by_day' => $byDay,
        ]);
    }

    /**
     * GET /api/dashboard/field-stats?form_id=&field=
     *
     * avg / min / max / count for numeric fields, or value counts for
     * categorical (select/text) fields.
     */
    public function fieldStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'form_id' => ['required', 'integer'],
            'field' => ['required', 'string', 'max:100'],
        ]);

        $form = Form::query()->where('id', (int) $validated['form_id'])->first();

        if ($form === null) {
            return response()->json(['error' => 'Form not found'], 404);
        }

        $field = collect($form->schema['fields'] ?? [])
            ->firstWhere('name', $validated['field']);

        if ($field === null) {
            return response()->json([
                'error' => 'Field is not part of the form schema',
                'field' => $validated['field'],
            ], 422);
        }

        $path = '$.'.str_replace(['$', '`'], '', $validated['field']);
        $base = Submission::query()->where('form_id', $form->id);
        $isNumeric = ($field['type'] ?? 'text') === 'number';

        if ($isNumeric) {
            // avg example: one row with count/average/min/max.
            // Positional "?" bindings: PDO/SQLite cannot mix named and
            // positional placeholders in one statement.
            $row = $base
                ->selectRaw($this->countExpression(), [$path])
                ->selectRaw($this->numericExpression('AVG'), [$path])
                ->selectRaw($this->numericExpression('MIN'), [$path])
                ->selectRaw($this->numericExpression('MAX'), [$path])
                ->first();

            return $this->resource('field-stats', $this->statsId($form, $validated['field']), [
                'form_id' => (int) $form->getKey(),
                'field' => $validated['field'],
                'kind' => 'numeric',
                'count' => (int) $row->count,
                'average' => $row->avg !== null ? (float) $row->avg : null,
                'min' => $row->min !== null ? (float) $row->min : null,
                'max' => $row->max !== null ? (float) $row->max : null,
            ]);
        }

        // Categorical: groupBy example over the JSON field value.
        $rows = $base
            ->selectRaw($this->categoricalExpression(), [$path])
            ->selectRaw('COUNT(*) as count')
            ->groupBy('value')
            ->orderByDesc('count')
            ->limit(50)
            ->get();

        return $this->resource('field-stats', $this->statsId($form, $validated['field']), [
            'form_id' => (int) $form->getKey(),
            'field' => $validated['field'],
            'kind' => 'categorical',
            'values' => $rows
                ->map(fn ($row) => [
                    'value' => $row->value,
                    'count' => (int) $row->count,
                ])
                ->values(),
        ]);
    }

    protected function countExpression(): string
    {
        return $this->isMysql()
            ? 'COUNT(JSON_EXTRACT(payload, ?)) as count'
            : 'COUNT(json_extract(payload, ?)) as count';
    }

    protected function numericExpression(string $function): string
    {
        return $this->isMysql()
            ? sprintf('%s(CAST(JSON_EXTRACT(payload, ?) AS DECIMAL(20, 6))) as %s', $function, strtolower($function))
            : sprintf('%s(CAST(json_extract(payload, ?) AS REAL)) as %s', $function, strtolower($function));
    }

    protected function categoricalExpression(): string
    {
        return $this->isMysql()
            ? 'JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) as value'
            : 'json_extract(payload, ?) as value';
    }

    protected function isMysql(): bool
    {
        return in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    protected function statsId(Form $form, string $field): string
    {
        return $form->getKey().':'.$field;
    }
}
