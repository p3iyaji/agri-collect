<script setup>
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const forms = ref([]);
const loading = ref(true);
const error = ref(null);

const canManage = auth.hasPermission('forms.manage');
const canFill = auth.hasPermission('submissions.write');

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get('/forms');
        forms.value = (response.data.data ?? []).map((form) => ({
            id: form.id,
            name: form.attributes.name,
            slug: form.attributes.slug,
            version: form.attributes.version,
            status: form.attributes.status,
            fieldCount: (form.attributes.schema?.fields ?? []).length,
            updatedAt: form.attributes.updated_at,
        }));
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

function statusClass(status) {
    return {
        active: 'bg-emerald-900/60 text-emerald-300',
        draft: 'bg-amber-900/60 text-amber-300',
        archived: 'bg-slate-800 text-slate-400',
    }[status] ?? 'bg-slate-800 text-slate-300';
}

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-xl font-bold">Forms</h1>
            <router-link v-if="canManage" to="/new-form" class="btn">+ New form</router-link>
        </div>

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <div v-else class="card overflow-x-auto !p-0">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-800">
                        <th class="th">Name</th>
                        <th class="th">Slug</th>
                        <th class="th">Fields</th>
                        <th class="th">Version</th>
                        <th class="th">Status</th>
                        <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="forms.length === 0">
                        <td colspan="6" class="td text-slate-400">No forms yet.</td>
                    </tr>
                    <tr v-for="form in forms" :key="form.id" class="border-b border-slate-800/60 last:border-0">
                        <td class="td font-medium">{{ form.name }}</td>
                        <td class="td font-mono text-xs text-slate-400">{{ form.slug }}</td>
                        <td class="td">{{ form.fieldCount }}</td>
                        <td class="td">v{{ form.version }}</td>
                        <td class="td">
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClass(form.status)">
                                {{ form.status }}
                            </span>
                        </td>
                        <td class="td text-right">
                            <router-link
                                v-if="canFill && form.status === 'active'"
                                :to="`/fill/${form.slug}`"
                                class="mr-2 text-emerald-400 hover:underline"
                            >
                                Fill
                            </router-link>
                            <router-link
                                v-if="canManage"
                                :to="`/forms/${form.id}`"
                                class="text-slate-300 hover:underline"
                            >
                                Edit
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
