import { ref } from 'vue';
import { api } from '@/services/api';
import * as idb from '@/services/idb';
import { useOfflineQueue } from './useOfflineQueue';

/**
 * useMobileSync — the Capacitor/Ionic field-app layer.
 *
 * Reuses the exact same IndexedDB outbox as the web SPA
 * (useOfflineQueue) and adds:
 *   - connectivity detection (online/offline events) that triggers syncs
 *   - form template caching in IndexedDB, keyed by workspace
 *   - native GPS capture (@capacitor/geolocation, web geolocation fallback)
 *   - native camera capture (@capacitor/camera, web <input capture> fallback)
 *
 * Everything degrades gracefully in a plain browser, so one codebase
 * serves both the SPA and the Android/iOS app.
 */
let corePromise = null;

async function isNative() {
    if (!corePromise) {
        corePromise = import('@capacitor/core');
    }

    const { Capacitor } = await corePromise;
    return Boolean(Capacitor?.isNativePlatform?.());
}

/**
 * @param {{ intervalMs?: number, gpsTimeoutMs?: number }} [options]
 */
export function useMobileSync(options = {}) {
    const { intervalMs = 60_000, gpsTimeoutMs = 20_000 } = options;

    const queue = useOfflineQueue();
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    const gpsStatus = ref('idle'); // idle | waiting | ready | error
    const formsSyncedAt = ref(null);

    let timer = null;
    let onOnline = null;
    let onOffline = null;

    /**
     * Download active form schemas and cache them in IndexedDB.
     * Offline (or recently synced): resolves from the local cache.
     *
     * @param {number} accountId
     * @param {{ maxAgeHours?: number }} [options]
     */
    async function syncFormTemplates(accountId, { maxAgeHours = 24 } = {}) {
        const cached = await idb.idbAllByIndex('formTemplates', 'account_id', accountId);
        const newest = cached.reduce(
            (max, form) => Math.max(max, Date.parse(form.last_synced_at ?? '') || 0),
            0,
        );

        if (cached.length > 0 && Date.now() - newest < maxAgeHours * 3_600_000) {
            return { cached: cached.length, fetched: 0, offline: false };
        }

        try {
            const response = await api.get('/forms', { params: { status: 'active' } });
            const now = new Date().toISOString();

            for (const item of response.data.data ?? []) {
                await idb.idbPut('formTemplates', {
                    id: item.id,
                    account_id: accountId,
                    name: item.attributes.name,
                    version: item.attributes.version,
                    schema: item.attributes.schema,
                    last_synced_at: now,
                });
            }

            formsSyncedAt.value = now;
            return { cached: cached.length, fetched: response.data.data?.length ?? 0, offline: false };
        } catch (error) {
            return {
                cached: cached.length,
                fetched: 0,
                offline: true,
                error: error?.message ?? 'offline',
            };
        }
    }

    /**
     * Locally cached form templates for a workspace (works fully offline).
     *
     * @param {number} accountId
     */
    async function localForms(accountId) {
        const forms = await idb.idbAllByIndex('formTemplates', 'account_id', accountId);
        return forms.sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    /**
     * High-accuracy GPS capture.
     * Native: @capacitor/geolocation (permission requested by the plugin).
     * Web fallback: navigator.geolocation.
     *
     * @returns {Promise<{ lat: number, lng: number, accuracy: number, source: string }>}
     */
    async function captureGps() {
        gpsStatus.value = 'waiting';

        try {
            if (await isNative()) {
                const { Geolocation } = await import('@capacitor/geolocation');
                const coords = await Geolocation.getCurrentPosition({
                    enableHighAccuracy: true,
                    timeout: gpsTimeoutMs,
                });
                gpsStatus.value = 'ready';
                return {
                    lat: coords.coords.latitude,
                    lng: coords.coords.longitude,
                    accuracy: coords.coords.accuracy,
                    source: 'gps',
                };
            }

            return await new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    gpsStatus.value = 'error';
                    reject(new Error('Geolocation is not available'));
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        gpsStatus.value = 'ready';
                        resolve({
                            lat: position.coords.latitude,
                            lng: position.coords.longitude,
                            accuracy: position.coords.accuracy,
                            source: 'gps',
                        });
                    },
                    (geolocationError) => {
                        gpsStatus.value = 'error';
                        reject(new Error(`GPS capture failed (code ${geolocationError.code})`));
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: gpsTimeoutMs,
                        maximumAge: 30_000,
                    },
                );
            });
        } catch (error) {
            gpsStatus.value = 'error';
            throw error;
        }
    }

    /**
     * Capture a photo with the native camera (native builds only;
     * the web form uses <input type="file" capture> instead).
     *
     * @returns {Promise<File | null>}
     */
    async function capturePhoto() {
        if (!(await isNative())) {
            return null;
        }

        const { Camera } = await import('@capacitor/camera');
        const result = await Camera.getPhoto({
            quality: 70,
            targetWidth: 1600,
            saveToGallery: false,
        });

        const { Filesystem } = await import('@capacitor/filesystem');
        const path = result.path ?? result.webPath;

        if (!path) {
            return null;
        }

        const read = await Filesystem.readFile({ path });
        const blob = await (await fetch(`data:image/jpeg;base64,${read.data}`)).blob();

        return new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
    }

    /**
     * Start the sync engine: periodic flushes + connectivity triggers +
     * an immediate first pass.
     */
    function start() {
        stop();
        online.value = typeof navigator === 'undefined' ? true : navigator.onLine;

        timer = setInterval(() => queue.flush(), intervalMs);

        onOnline = () => {
            online.value = true;
            queue.flush({ force: true });
        };
        onOffline = () => {
            online.value = false;
        };

        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);

        queue.flush();
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }

        if (onOnline) {
            window.removeEventListener('online', onOnline);
        }
        if (onOffline) {
            window.removeEventListener('offline', onOffline);
        }
        onOnline = null;
        onOffline = null;
    }

    return {
        ...queue,
        online,
        gpsStatus,
        formsSyncedAt,
        isNative: () => isNative(),
        syncFormTemplates,
        localForms,
        captureGps,
        capturePhoto,
        start,
        stop,
    };
}
