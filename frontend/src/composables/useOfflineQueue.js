import { ref } from 'vue';
import { api } from '@/services/api';
import { compressImage } from '@/services/image';
import * as idb from '@/services/idb';

/**
 * useOfflineQueue — the web SPA outbox (also the backbone of the mobile
 * sync engine, see useMobileSync).
 *
 * Outbox pattern:
 *   1. queueSubmission() writes the record (and any compressed photos) to
 *      IndexedDB first — the local database is the source of truth.
 *   2. flush() batches everything that is due and POSTs it to
 *      /api/sync/submissions (idempotent: each row carries its
 *      local_uuid, so a lost response + retry never duplicates).
 *   3. Network failure → rows stay queued; each retry backs off
 *      exponentially (2s → 15 min cap, with jitter) until connectivity
 *      returns.
 *
 * Module-level state makes the queue a singleton: every component that
 * calls useOfflineQueue() observes and drives the same outbox.
 */
const pending = ref(0);
const syncing = ref(false);
const lastError = ref(null);
const lastSyncAt = ref(null);

let flushInFlight = false;
let timer = null;

function currentAccountId() {
    try {
        const raw = localStorage.getItem('dc.active');
        return raw ? (JSON.parse(raw)?.id ?? null) : null;
    } catch {
        return null;
    }
}

function errorMessage(error) {
    if (error?.response) {
        const data =
            typeof error.response.data === 'string'
                ? error.response.data
                : JSON.stringify(error.response.data ?? {});
        return `Server ${error.response.status}: ${data.slice(0, 200)}`;
    }

    if (error?.message === 'Network Error' || error?.code === 'ERR_NETWORK') {
        return 'No network connection';
    }

    return error?.message || 'Unknown error';
}

/**
 * Exponential backoff: 2s, 4s, 8s ... capped at 15 minutes, plus jitter.
 *
 * @param {number} attempts
 * @returns {number} delay in ms
 */
function backoffDelay(attempts) {
    const base = Math.min(15 * 60 * 1000, 2000 * 2 ** Math.min(Math.max(attempts - 1, 0), 8));
    return base + Math.floor(Math.random() * 2000);
}

async function refreshCount() {
    const all = await idb.idbAll('submissions');
    pending.value = all.filter(
        (record) => record.sync_status === 'pending' || record.sync_status === 'failed',
    ).length;
}

/**
 * Persist a completed form locally, then kick a sync attempt.
 *
 * @param {{
 *   form: { id: string | number, name: string, version?: number },
 *   payload: Record<string, unknown>,
 *   gps?: { lat?: number, lng?: number, accuracy?: number, source?: string } | null,
 *   attachments?: Array<{ field_name: string, file: File | Blob }>,
 * }} entry
 * @returns {Promise<object>} the queued outbox record
 */
async function queueSubmission({ form, payload, gps = null, attachments = [] }) {
    const local_uuid = crypto.randomUUID();

    const record = {
        local_uuid,
        account_id: currentAccountId(),
        form_id: form.id,
        form_name: form.name,
        form_version: form.version ?? null,
        payload,
        gps,
        created_at: new Date().toISOString(),
        sync_status: 'pending',
        attempts: 0,
        next_retry_at: 0,
        synced_at: null,
        server_id: null,
        last_error: null,
    };

    await idb.idbPut('submissions', record);

    for (const attachment of attachments) {
        if (!attachment.file) {
            continue;
        }

        const blob = attachment.file.type?.startsWith?.('image/')
            ? await compressImage(attachment.file, { quality: 0.7, maxDim: 1600 })
            : attachment.file;

        await idb.idbPut('attachments', {
            local_uuid: crypto.randomUUID(),
            submission_uuid: local_uuid,
            field_name: attachment.field_name,
            file_name: attachment.file.name || `${attachment.field_name}.jpg`,
            blob,
            upload_status: 'pending',
            attempts: 0,
            url: null,
            last_error: null,
        });
    }

    await refreshCount();
    flush(); // fire-and-forget; failures are handled inside

    return record;
}

/**
 * Upload all pending photos for a just-synced submission.
 *
 * @param {string} submissionUuid
 */
async function uploadAttachmentsFor(submissionUuid) {
    const attachments = await idb.idbAllByIndex('attachments', 'submission_uuid', submissionUuid);

    for (const attachment of attachments) {
        if (attachment.upload_status === 'synced') {
            continue;
        }

        try {
            const formData = new FormData();
            formData.append('local_uuid', submissionUuid);
            formData.append('field_name', attachment.field_name);
            formData.append('file', attachment.blob, attachment.file_name);

            const response = await api.post('/sync/attachments', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
                timeout: 120_000,
            });

            await idb.idbPut('attachments', {
                ...attachment,
                upload_status: 'synced',
                url: response.data.data.attributes.url,
                last_error: null,
            });
        } catch (error) {
            // The submission itself is synced; only the photo will retry
            // on the next flush cycle (next due submission batch).
            await idb.idbPut('attachments', {
                ...attachment,
                upload_status: 'failed',
                attempts: (attachment.attempts ?? 0) + 1,
                last_error: errorMessage(error),
            });
        }
    }
}

/**
 * Push all due outbox rows to the server.
 *
 * @param {{ force?: boolean }} [options]
 */
async function flush({ force = false } = {}) {
    if (flushInFlight) {
        return;
    }

    if (!force && typeof navigator !== 'undefined' && !navigator.onLine) {
        return;
    }

    const accountId = currentAccountId();
    const now = Date.now();
    const all = await idb.idbAll('submissions');

    const due = all.filter(
        (record) =>
            (record.sync_status === 'pending' || record.sync_status === 'failed') &&
            (record.account_id ?? null) === accountId &&
            (record.next_retry_at ?? 0) <= now,
    );

    if (due.length === 0) {
        return;
    }

    flushInFlight = true;
    syncing.value = true;
    lastError.value = null;

    try {
        const response = await api.post(
            '/sync/submissions',
            {
                submissions: due.map((record) => ({
                    local_uuid: record.local_uuid,
                    form_id: record.form_id,
                    payload: record.payload,
                    gps: record.gps,
                    created_at: record.created_at,
                })),
            },
            { timeout: 60_000 },
        );

        const resultsByUuid = Object.fromEntries(
            (response.data.results ?? []).map((result) => [result.local_uuid, result]),
        );

        for (const record of due) {
            const result = resultsByUuid[record.local_uuid];
            const next = { ...record };

            if (!result) {
                next.attempts = record.attempts + 1;
                next.sync_status = 'failed';
                next.next_retry_at = Date.now() + backoffDelay(next.attempts);
                next.last_error = 'Server returned no result for this item';
            } else if (result.status === 'error') {
                // Server-side validation problem (e.g. form no longer
                // exists in this workspace) — retry with backoff in case
                // it is transient, keep the error visible.
                next.attempts = record.attempts + 1;
                next.sync_status = 'failed';
                next.next_retry_at = Date.now() + backoffDelay(next.attempts);
                next.last_error = result.error;
            } else {
                next.sync_status = 'synced';
                next.synced_at = new Date().toISOString();
                next.server_id = result.id;
                next.last_error = null;

                await uploadAttachmentsFor(record.local_uuid);
            }

            await idb.idbPut('submissions', next);
        }

        lastSyncAt.value = new Date().toISOString();
    } catch (error) {
        // Whole batch failed (network down, 5xx, ...): schedule retries
        // with per-row exponential backoff.
        const nowTs = Date.now();

        for (const record of due) {
            await idb.idbPut('submissions', {
                ...record,
                attempts: record.attempts + 1,
                sync_status: 'failed',
                next_retry_at: nowTs + backoffDelay(record.attempts + 1),
                last_error: errorMessage(error),
            });
        }

        lastError.value = errorMessage(error);
    } finally {
        flushInFlight = false;
        syncing.value = false;
        await refreshCount();
    }
}

/**
 * Start periodic flushing (call once, e.g. in App.vue on mount).
 *
 * @param {number} [intervalMs]
 */
function start(intervalMs = 30_000) {
    stop();
    timer = setInterval(() => flush(), intervalMs);
    flush();
}

function stop() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

export function useOfflineQueue() {
    refreshCount();

    return {
        pending,
        syncing,
        lastError,
        lastSyncAt,
        queueSubmission,
        flush,
        start,
        stop,
        refreshCount,
    };
}
