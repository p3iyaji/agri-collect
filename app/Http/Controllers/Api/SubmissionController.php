<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Read-side of submissions, scoped to the active workspace.
 * (Writes go through the idempotent SyncController.)
 */
class SubmissionController extends Controller
{
    /**
     * GET /api/submissions?form_id=&from=&to=&q=&page=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Submission::query()->orderByDesc('created_at');

        if ($request->filled('form_id')) {
            $query->where('form_id', (int) $request->input('form_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('q')) {
            $query->where('local_uuid', 'like', '%'.$request->input('q').'%');
        }

        $formNames = Form::query()->pluck('name', 'id')->all();

        $formCounts = (clone $query)
            ->reorder()
            ->selectRaw('form_id, COUNT(*) as aggregate')
            ->groupBy('form_id')
            ->pluck('aggregate', 'form_id')
            ->map(fn ($count, $formId) => [
                'form_id' => (int) $formId,
                'name' => $formNames[(int) $formId] ?? sprintf('Form #%d', $formId),
                'count' => (int) $count,
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $page = $query->with('attachments')->paginate((int) $request->input('per_page', 25));

        $userNames = User::query()->pluck('name', 'id')->all();

        $items = $page->getCollection()->map(function (Submission $submission) use ($formNames, $userNames) {
            $attributes = $this->attributes($submission, $formNames, $userNames);
            $attributes['attachment_fields'] = $submission->attachments
                ->pluck('field_name')
                ->values()
                ->all();

            return [
                'type' => 'submissions',
                'id' => (string) $submission->getKey(),
                'attributes' => $attributes,
            ];
        });

        return $this->collection(
            $items->all(),
            (int) $page->total(),
            $page->currentPage(),
            (int) $page->perPage(),
            ['form_counts' => $formCounts],
        );
    }

    /**
     * GET /api/submissions/{submission}
     */
    public function show(int $submission): JsonResponse
    {
        $model = Submission::query()->with('attachments')->findOrFail($submission);

        $formNames = Form::query()->pluck('name', 'id')->all();
        $userNames = User::query()->pluck('name', 'id')->all();

        $attributes = $this->attributes($model, $formNames, $userNames);
        $attributes['attachments'] = $model->attachments
            ->map(fn (SubmissionAttachment $attachment) => [
                'field_name' => $attachment->field_name,
                'url' => Storage::url($attachment->path),
                'mime_type' => $attachment->mime_type,
                'bytes' => $attachment->bytes,
            ])
            ->values();

        return $this->resource('submissions', (string) $model->getKey(), $attributes);
    }

    /**
     * @param  array<int, string>  $formNames
     * @param  array<int, string>  $userNames
     * @return array<string, mixed>
     */
    protected function attributes(Submission $submission, array $formNames, array $userNames): array
    {
        return [
            'local_uuid' => $submission->local_uuid,
            'form_id' => (int) $submission->form_id,
            'form_name' => $formNames[$submission->form_id] ?? null,
            'payload' => $submission->payload,
            'gps' => [
                'lat' => $submission->gps_lat,
                'lng' => $submission->gps_lng,
                'accuracy' => $submission->gps_accuracy,
                'source' => $submission->gps_source,
            ],
            'submitted_by' => $submission->submitted_by !== null
                ? ($userNames[$submission->submitted_by] ?? null)
                : null,
            'synced_at' => $submission->synced_at?->toIso8601String(),
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }
}
