<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminTabs from '@/components/AdminTabs.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const workspace = ref(null);
const loading = ref(true);
const error = ref(null);
const notice = ref(null);
const saving = ref(false);
const uploadingLogo = ref(false);

const org = reactive({ name: '', contact_person: '', phone: '', email: '', address: '', website: '' });
const field = reactive({ project_name: '', region: '', notes: '' });

const id = () => auth.activeAccount.id;

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get('/workspaces');
        workspace.value = (response.data.data ?? []).find((row) => row.id === String(id())) ?? null;

        Object.assign(org, { name: '', contact_person: '', phone: '', email: '', address: '', website: '' },
            workspace.value?.settings?.organization ?? {});
        Object.assign(field, { project_name: '', region: '', notes: '' },
            workspace.value?.settings?.field ?? {});
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

async function saveDetails() {
    saving.value = true;
    error.value = null;
    notice.value = null;

    try {
        await api.patch(`/workspaces/${id()}`, {
            name: org.name,
            settings: { organization: { ...org }, field: { ...field } },
        });

        notice.value = 'Workspace details saved.';
        await auth.refreshAccounts();
        await load();
    } catch (saveError) {
        error.value = saveError?.response?.data?.message ?? saveError?.response?.data?.error ?? saveError?.message;
    } finally {
        saving.value = false;
    }
}

async function uploadLogo(event) {
    const file = event.target.files?.[0];
    event.target.value = '';

    if (!file) {
        return;
    }

    uploadingLogo.value = true;
    error.value = null;
    notice.value = null;

    try {
        const formData = new FormData();
        formData.append('logo', file);

        await api.post(`/workspaces/${id()}/logo`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        notice.value = 'Logo updated.';
        await load();
    } catch (uploadError) {
        error.value = uploadError?.response?.data?.error ?? uploadError?.message;
    } finally {
        uploadingLogo.value = false;
    }
}

async function removeLogo() {
    if (!window.confirm('Remove this workspace logo?')) {
        return;
    }

    uploadingLogo.value = true;
    error.value = null;
    notice.value = null;

    try {
        await api.delete(`/workspaces/${id()}/logo`);
        notice.value = 'Logo removed.';
        await load();
    } catch (removeError) {
        error.value = removeError?.response?.data?.error ?? removeError?.message;
    } finally {
        uploadingLogo.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-bold">Workspace settings</h1>
        <AdminTabs />

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-if="notice" class="card mb-4 border-emerald-800 text-emerald-300">{{ notice }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <div v-else-if="workspace" class="space-y-4">
            <div class="card">
                <h2 class="mb-3 text-sm font-semibold">Logo</h2>
                <div class="flex flex-wrap items-center gap-4">
                    <img
                        v-if="workspace.attributes.logo_url"
                        :src="workspace.attributes.logo_url"
                        alt="Workspace logo"
                        class="h-20 w-20 rounded-xl object-cover shadow"
                    />
                    <div v-else class="flex h-20 w-20 items-center justify-center rounded-xl border border-dashed border-slate-700 text-3xl">
                        🌾
                    </div>
                    <div class="space-y-2">
                        <label class="btn-ghost inline-block cursor-pointer text-xs">
                            {{ uploadingLogo ? 'Uploading…' : 'Upload logo' }}
                            <input type="file" accept="image/png, image/jpeg, image/webp" class="hidden" :disabled="uploadingLogo" @change="uploadLogo" />
                        </label>
                        <p class="text-xs text-slate-500">
                            PNG, JPEG or WebP, max 2 MB. Shown on this workspace's login page.
                        </p>
                        <button v-if="workspace.attributes.logo_url" class="text-xs text-red-400 hover:underline" :disabled="uploadingLogo" @click="removeLogo">
                            Remove logo
                        </button>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="mb-3 text-sm font-semibold">Organization &amp; contact details</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Organization name</label>
                        <input v-model="org.name" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Contact person</label>
                        <input v-model="org.contact_person" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Phone</label>
                        <input v-model="org.phone" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Email</label>
                        <input v-model="org.email" type="email" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Address</label>
                        <input v-model="org.address" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Website</label>
                        <input v-model="org.website" class="input" />
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="mb-1 text-sm font-semibold">Field details</h2>
                <p class="mb-3 text-xs text-slate-500">
                    Shown to enumerators at the top of every form in the field app.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Project name</label>
                        <input v-model="field.project_name" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Region / area</label>
                        <input v-model="field.region" class="input" />
                    </div>
                </div>
                <div class="mt-3">
                    <label class="mb-1 block text-xs text-slate-400">Field instructions</label>
                    <textarea v-model="field.notes" rows="4" class="input"></textarea>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button class="btn" :disabled="saving || !org.name" @click="saveDetails">
                    {{ saving ? 'Saving…' : 'Save details' }}
                </button>
                <p class="text-xs text-slate-500">
                    Branded login URL: <code class="font-mono text-slate-400">/login/{{ workspace.attributes.slug }}</code>
                </p>
            </div>
        </div>

        <p v-else class="text-slate-400">No active workspace to configure.</p>
    </div>
</template>
