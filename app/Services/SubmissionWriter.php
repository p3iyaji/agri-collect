<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SyncReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for submissions: an idempotent upsert keyed by
 * (account_id, local_uuid), with a receipt row tracking processing.
 *
 * Shared by the offline sync endpoint (web outbox + mobile app) and the
 * CSV importer, so both get exactly-once semantics for free: a retried
 * or re-run write returns the existing row as "duplicate" instead of
 * creating a copy.
 */
class SubmissionWriter
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $gps
     * @return array{local_uuid: string, status: 'created'|'duplicate', id: ?int, error: null}
     */
    public function upsert(
        int $accountId,
        Form $form,
        string $localUuid,
        array $payload,
        array $gps = [],
        ?Carbon $createdAt = null,
        ?int $submittedBy = null,
    ): array {
        $receipt = $this->receiptFor($accountId, $localUuid);

        try {
            $submission = DB::transaction(function () use ($accountId, $form, $localUuid, $payload, $gps, $createdAt, $submittedBy, $receipt): Submission {
                $submission = Submission::query()->create([
                    'account_id' => $accountId,
                    'form_id' => $form->id,
                    'local_uuid' => $localUuid,
                    'payload' => $payload,
                    'gps_lat' => $gps['lat'] ?? null,
                    'gps_lng' => $gps['lng'] ?? null,
                    'gps_accuracy' => $gps['accuracy'] ?? null,
                    'gps_source' => $gps['source'] ?? 'none',
                    'submitted_by' => $submittedBy,
                    'created_at' => $createdAt ?? Carbon::now(),
                    'synced_at' => Carbon::now(),
                ]);

                $receipt->update([
                    'status' => 'processed',
                    'response' => ['id' => $submission->getKey(), 'status' => 'created'],
                ]);

                return $submission;
            });

            return [
                'local_uuid' => $localUuid,
                'status' => 'created',
                'id' => $submission->getKey(),
                'error' => null,
            ];
        } catch (UniqueConstraintViolationException) {
            // A concurrent write — or a retry / re-import — already
            // created this UUID. Return the existing row.
            $existing = Submission::query()->where('local_uuid', $localUuid)->first();

            $receipt->update([
                'status' => 'processed',
                'response' => ['id' => $existing?->getKey(), 'status' => 'duplicate'],
            ]);

            return [
                'local_uuid' => $localUuid,
                'status' => 'duplicate',
                'id' => $existing?->getKey(),
                'error' => null,
            ];
        }
    }

    /**
     * Find or create the receipt for a local_uuid (race-safe).
     */
    protected function receiptFor(int $accountId, string $uuid): SyncReceipt
    {
        try {
            return SyncReceipt::query()->firstOrCreate(
                ['account_id' => $accountId, 'local_uuid' => $uuid],
                ['status' => 'pending', 'attempts' => 1],
            );
        } catch (UniqueConstraintViolationException) {
            return SyncReceipt::query()
                ->where('account_id', $accountId)
                ->where('local_uuid', $uuid)
                ->firstOrFail();
        }
    }
}
