/**
 * Tiny promise wrapper around IndexedDB — the local persistence layer for
 * the offline-first design (form template cache, submission outbox,
 * attachment queue). Used by both the web SPA and the Capacitor app
 * (IndexedDB works inside the webview).
 *
 * Stores:
 *   formTemplates — cached form schemas, keyed by form id, indexed by
 *                   account_id (per-workspace cache).
 *   submissions   — the outbox. Keyed by local_uuid (device-generated),
 *                   indexed by sync_status.
 *   attachments   — compressed photos waiting for upload, keyed by their
 *                   own uuid, indexed by submission_uuid.
 *   meta          — key/value (e.g. last sync timestamps).
 */
const DB_NAME = 'digit-capture';
const DB_VERSION = 1;

export const Stores = {
    formTemplates: {
        keyPath: 'id',
        indexes: ['account_id'],
    },
    submissions: {
        keyPath: 'local_uuid',
        indexes: ['sync_status', 'form_id'],
    },
    attachments: {
        keyPath: 'local_uuid',
        indexes: ['submission_uuid', 'upload_status'],
    },
    meta: {
        keyPath: 'key',
        indexes: [],
    },
};

let dbPromise = null;

/**
 * Open (and upgrade if needed) the database.
 *
 * @returns {Promise<IDBDatabase>}
 */
export function openDB() {
    if (!dbPromise) {
        dbPromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = request.result;

                for (const [name, spec] of Object.entries(Stores)) {
                    let store;

                    if (!db.objectStoreNames.contains(name)) {
                        store = db.createObjectStore(name, { keyPath: spec.keyPath });
                    } else {
                        store = event.target.result.transaction.objectStore(name);
                    }

                    for (const index of spec.indexes) {
                        if (!store.indexNames.contains(index)) {
                            store.createIndex(index, index);
                        }
                    }
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    return dbPromise;
}

/**
 * Run a single-store transaction and resolve with the request result.
 *
 * @template T
 * @param {keyof typeof Stores} storeName
 * @param {'readonly' | 'readwrite'} mode
 * @param {(store: IDBObjectStore) => IDBRequest<T>} fn
 * @returns {Promise<T | undefined>}
 */
async function withStore(storeName, mode, fn) {
    const db = await openDB();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction(storeName, mode);
        const store = transaction.objectStore(storeName);

        let request;

        try {
            request = fn(store);
        } catch (error) {
            transaction.abort();
            reject(error);
            return;
        }

        transaction.oncomplete = () => resolve(request?.result);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    });
}

export const idbPut = (store, value) => withStore(store, 'readwrite', (s) => s.put(value));
export const idbGet = (store, key) => withStore(store, 'readonly', (s) => s.get(key));
export const idbDelete = (store, key) => withStore(store, 'readwrite', (s) => s.delete(key));
export const idbAll = (store) => withStore(store, 'readonly', (s) => s.getAll());
export const idbAllByIndex = (store, index, range) =>
    withStore(store, 'readonly', (s) => s.index(index).getAll(range));
