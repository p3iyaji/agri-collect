<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DynamicField from '@/components/DynamicField.vue';
import { useMobileSync } from '@/composables/useMobileSync';
import { api } from '@/services/api';
import { compressImage, previewUrl } from '@/services/image';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const sync = useMobileSync();
const { queueSubmission, pending, syncing, gpsStatus } = sync;

const form = ref(null);
const loading = ref(true);
const loadError = ref(null);
const saving = ref(false);
const saveMessage = ref(null);

const orgProfile = ref(null); // workspace branding + field details

/**
 * Best-effort: fetch the workspace's public profile for the banner
 * (logo, project, instructions). Fails silently offline.
 */
async function loadOrg() {
    try {
        const response = await api.get(`/workspaces/${auth.activeAccount.id}/public-profile`);
        orgProfile.value = response.data.data.attributes;
    } catch {
        orgProfile.value = null;
    }
}

const gps = ref(null);
const gpsError = ref(null);

const values = reactive({});
const photos = reactive({}); // field_name -> { blob, url }
const isNative = ref(false);

const previews = computed(() =>
    Object.fromEntries(
        Object.entries(photos)
            .filter(([, value]) => value?.url)
            .map(([key, value]) => [key, value.url]),
    ),
);

/**
 * Load the form: live API first, then the IndexedDB template cache
 * (this is what makes offline form filling work).
 */
async function load() {
    loading.value = true;
    loadError.value = null;
    isNative.value = await sync.isNative();

    try {
        const response = await api.get(`/forms/${route.params.formKey}`);
        const data = response.data.data;

        form.value = {
            id: data.id,
            name: data.attributes.name,
            version: data.attributes.version,
            schema: data.attributes.schema,
        };

        loading.value = false;
        return;
    } catch {
        // Fall through to the local cache.
    }

    const cached = await sync.localForms(auth.activeAccount?.id);
    const found = cached.find(
        (template) =>
            String(template.id) === String(route.params.formKey) ||
            template.name === route.params.formKey,
    );

    if (found) {
        form.value = {
            id: found.id,
            name: found.name,
            version: found.version,
            schema: found.schema,
        };
    } else {
        loadError.value = 'Form not available — not found online and not in the offline cache.';
    }

    loading.value = false;
}

/**
 * Skip logic: a field is visible when its visible_if rule passes.
 */
function isFieldVisible(field) {
    const rule = field.visible_if;

    if (!rule || !rule.field) {
        return true;
    }

    const actual = values[rule.field];
    const expected = rule.value;

    switch (rule.op) {
        case 'eq':
            return String(actual ?? '') === String(expected);
        case 'neq':
            return String(actual ?? '') !== String(expected);
        case 'gt':
            return Number(actual ?? Number.MIN_VALUE) > Number(expected);
        case 'lt':
            return Number(actual ?? Number.MAX_VALUE) < Number(expected);
        case 'set':
            return actual !== undefined && actual !== null && actual !== '';
        default:
            return true;
    }
}

function missingRequired() {
    return (form.value?.schema?.fields ?? []).filter(
        (field) =>
            field.required &&
            isFieldVisible(field) &&
            (values[field.name] === undefined || values[field.name] === null || values[field.name] === ''),
    );
}

async function captureGps() {
    gpsError.value = null;

    try {
        gps.value = await sync.captureGps();
    } catch (captureError) {
        gpsError.value = captureError.message;
    }
}

async function onPhotoChange(field, event) {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }

    const blob = await compressImage(file, { quality: 0.7, maxDim: 1600 });
    photos[field] = { blob, url: previewUrl(blob) };
}

async function onNativePhoto(field) {
    const file = await sync.capturePhoto();
    if (!file) {
        return;
    }

    const blob = await compressImage(file, { quality: 0.7, maxDim: 1600 });
    photos[field] = { blob, url: previewUrl(blob) };
}

/**
 * Submit: write to the local outbox (IndexedDB) first; the sync engine
 * pushes it to /api/sync/submissions when connectivity allows.
 */
async function submit() {
    saveMessage.value = null;

    const missing = missingRequired();

    if (missing.length > 0) {
        saveMessage.value = {
            error: `Missing required: ${missing.map((field) => field.label).join(', ')}`,
        };
        return;
    }

    saving.value = true;

    try {
        const attachments = Object.entries(photos)
            .filter(([, value]) => value?.blob)
            .map(([field_name, value]) => ({ field_name, file: value.blob }));

        await queueSubmission({
            form: form.value,
            payload: { ...values },
            gps: gps.value,
            attachments,
        });

        saveMessage.value = {
            ok: navigator.onLine
                ? 'Submitted — syncing…'
                : 'Saved offline. It will sync automatically when you are back online.',
        };

        setTimeout(() => router.push('/submissions'), 1800);
    } catch (submitError) {
        saveMessage.value = { error: submitError.message };
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    load();
    loadOrg();
});
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <div v-if="loading" class="py-12 text-center text-slate-400">Loading form…</div>
        <div v-else-if="loadError" class="card text-red-300">{{ loadError }}</div>

        <template v-else-if="form">
            <div v-if="orgProfile" class="card mb-4 flex items-start gap-3">
                <img
                    v-if="orgProfile.logo_url"
                    :src="orgProfile.logo_url"
                    :alt="orgProfile.name"
                    class="h-10 w-10 rounded-md object-cover"
                />
                <div class="min-w-0 text-sm">
                    <p class="font-semibold">{{ orgProfile.organization?.name || orgProfile.name }}</p>
                    <p v-if="orgProfile.field?.project_name" class="text-xs text-slate-400">
                        {{ orgProfile.field.project_name }}
                        <template v-if="orgProfile.field?.region"> · {{ orgProfile.field.region }}</template>
                    </p>
                    <p v-if="orgProfile.field?.notes" class="mt-1 text-xs text-slate-400">
                        {{ orgProfile.field.notes }}
                    </p>
                </div>
            </div>

            <h1 class="mb-1 text-xl font-bold">
                {{ form.name }}
                <span class="text-xs text-slate-500">v{{ form.version }}</span>
            </h1>
            <p class="mb-6 text-sm text-slate-400">
                Entries are saved to this device first, then synced when a connection is available.
            </p>

            <form @submit.prevent="submit">
                <template v-for="field in form.schema.fields ?? []" :key="field.name">
                    <div v-if="isFieldVisible(field)">
                        <DynamicField
                            v-if="field.type !== 'photo' && field.type !== 'gps'"
                            :field="field"
                            v-model="values[field.name]"
                        />

                        <div v-else-if="field.type === 'gps'" class="card mb-4 flex flex-wrap items-center gap-3">
                            <label class="text-sm font-medium">
                                Location
                                <span v-if="field.required" class="text-red-400">*</span>
                            </label>
                            <button
                                type="button"
                                class="btn"
                                :disabled="gpsStatus === 'waiting'"
                                @click="captureGps"
                            >
                                📍 {{ gpsStatus === 'waiting' ? 'Waiting for GPS…' : 'Capture GPS' }}
                            </button>
                            <span v-if="gps" class="text-sm text-emerald-300">
                                {{ gps.lat?.toFixed(5) }}, {{ gps.lng?.toFixed(5) }}
                                <span class="text-slate-400">(±{{ Math.round(gps.accuracy ?? 0) }} m)</span>
                            </span>
                            <span v-else-if="gpsError" class="text-sm text-red-400">{{ gpsError }}</span>
                        </div>

                        <div v-else class="card mb-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="text-sm font-medium">
                                    {{ field.label }}
                                    <span v-if="field.required" class="text-red-400">*</span>
                                </label>
                                <button
                                    v-if="isNative"
                                    type="button"
                                    class="btn-ghost text-xs"
                                    @click="onNativePhoto(field.name)"
                                >
                                    📷 Camera
                                </button>
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    class="max-w-full text-xs"
                                    @change="onPhotoChange(field.name, $event)"
                                />
                            </div>
                            <p v-if="field.help_text" class="text-xs text-slate-400">{{ field.help_text }}</p>
                            <img
                                v-if="previews[field.name]"
                                :src="previews[field.name]"
                                alt=""
                                class="h-28 rounded-md object-cover"
                            />
                        </div>
                    </div>
                </template>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <button class="btn" :disabled="saving || syncing">
                        {{ saving ? 'Saving…' : 'Submit' }}
                    </button>
                    <span v-if="syncing" class="animate-pulse text-xs text-amber-300">syncing…</span>
                    <span v-else-if="pending > 0" class="text-xs text-amber-300">{{ pending }} in queue</span>
                </div>

                <p v-if="saveMessage?.ok" class="mt-3 text-sm text-emerald-400">{{ saveMessage.ok }}</p>
                <p v-if="saveMessage?.error" class="mt-3 text-sm text-red-400">{{ saveMessage.error }}</p>
            </form>
        </template>
    </div>
</template>
