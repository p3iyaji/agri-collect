<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncSubmissionsRequest;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAttachment;
use App\Services\SubmissionWriter;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Idempotent offline sync — the single write path for submissions,
 * shared by the web SPA outbox and the mobile field app.
 *
 * Each submission carries a device-generated local_uuid. A receipt row
 * (sync_receipts) plus a unique (account_id, local_uuid) index on
 * submissions guarantee exactly-once semantics: if the server processes
 * a batch but the response is lost, the retried batch returns the
 * existing rows with status "duplicate" instead of creating copies.
 */
class SyncController extends Controller
{
    public function __construct(protected SubmissionWriter $writer) {}

    /**
     * POST /api/sync/submissions
     *
     * Body: { "submissions": [ { local_uuid, form_id, payload, gps?, created_at? }, ... ] }
     *
     * Response: { "results": [ { local_uuid, status: created|duplicate|error, id, error }, ... ] }
     */
    public function submissions(SyncSubmissionsRequest $request): JsonResponse
    {
        $results = [];

        foreach ($request->input('submissions', []) as $item) {
            $results[] = $this->processSubmission($item);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * POST /api/sync/attachments (multipart)
     *
     * Photo / document upload for an already-synced submission. Idempotent
     * per (submission, field_name): re-uploads return the stored file.
     */
    public function attachments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'local_uuid' => ['required', 'string', 'min:8', 'max:36'],
            'field_name' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'max:25600', 'mimes:jpeg,jpg,png,webp,heic,mp4,pdf'],
        ]);

        $submission = Submission::query()->where('local_uuid', $data['local_uuid'])->first();

        if ($submission === null) {
            return response()->json([
                'error' => 'Unknown submission',
                'detail' => 'Sync the submission before uploading its attachments.',
            ], 404);
        }

        $existing = $submission->attachments()->where('field_name', $data['field_name'])->first();

        if ($existing !== null) {
            return $this->attachmentResponse($existing, true);
        }

        $file = $request->file('file');

        $path = $file->store(
            sprintf('attachments/%d/%d', $submission->account_id, $submission->getKey()),
            'public',
        );

        $attachment = $submission->attachments()->create([
            'account_id' => $submission->account_id,
            'field_name' => $data['field_name'],
            'mime_type' => $file->getClientMimeType(),
            'bytes' => $file->getSize(),
            'path' => $path,
        ]);

        return $this->attachmentResponse($attachment, false);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function processSubmission(array $item): array
    {
        $uuid = (string) $item['local_uuid'];
        $accountId = Tenancy::activeId();

        $form = Form::query()->where('id', (int) $item['form_id'])->first();

        if ($form === null) {
            return [
                'local_uuid' => $uuid,
                'status' => 'error',
                'id' => null,
                'error' => sprintf('Unknown form_id %s in this workspace', $item['form_id']),
            ];
        }

        $createdAt = isset($item['created_at']) ? Carbon::parse($item['created_at']) : null;

        return $this->writer->upsert(
            $accountId,
            $form,
            $uuid,
            $item['payload'] ?? [],
            $item['gps'] ?? [],
            $createdAt,
            $this->currentUser()?->getKey(),
        );
    }

    protected function attachmentResponse(SubmissionAttachment $attachment, bool $duplicate): JsonResponse
    {
        return response()->json([
            'data' => [
                'type' => 'submission-attachments',
                'id' => (string) $attachment->getKey(),
                'attributes' => [
                    'url' => Storage::url($attachment->path),
                    'field_name' => $attachment->field_name,
                    'mime_type' => $attachment->mime_type,
                    'bytes' => $attachment->bytes,
                ],
            ],
            'meta' => ['duplicate' => $duplicate],
        ]);
    }
}
