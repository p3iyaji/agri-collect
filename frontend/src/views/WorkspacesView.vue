<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminTabs from '@/components/AdminTabs.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const workspaces = ref([]);
const loading = ref(true);
const error = ref(null);

const creating = reactive({ name: '', busy: false });
const editingId = ref(null);
const renameDraft = ref('');
const saving = ref(false);

const activeId = () => auth.activeAccount?.id;

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get('/workspaces');
        workspaces.value = response.data.data ?? [];
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

async function createWorkspace() {
    creating.busy = true;
    error.value = null;

    try {
        const response = await api.post('/workspaces', { name: creating.name });
        creating.name = '';
        await auth.switchAccount(response.data.data.id);
        await auth.refreshAccounts();
        await load();
    } catch (err) {
        error.value = err?.response?.data?.message ?? err?.response?.data?.error ?? err?.message;
    } finally {
        creating.busy = false;
    }
}

function startRename(workspace) {
    editingId.value = workspace.id;
    renameDraft.value = workspace.attributes.name;
}

async function saveRename(workspace) {
    saving.value = true;
    error.value = null;

    try {
        await api.patch(`/workspaces/${workspace.id}`, { name: renameDraft.value });
        editingId.value = null;
        await auth.refreshAccounts();
        await load();
    } catch (err) {
        error.value = err?.response?.data?.message ?? err?.response?.data?.error ?? err?.message;
    } finally {
        saving.value = false;
    }
}

async function switchTo(workspace) {
    error.value = null;

    try {
        await auth.switchAccount(workspace.id);
        await load();
    } catch (err) {
        error.value = err?.response?.data?.error ?? err?.message;
    }
}

async function deleteWorkspace(workspace) {
    const ok = window.confirm(
        `Permanently delete the workspace "${workspace.attributes.name}"?\n\n` +
            `This deletes ALL of its forms, submissions, photos, and memberships. This cannot be undone.`,
    );

    if (!ok) {
        return;
    }

    saving.value = true;
    error.value = null;

    try {
        await api.delete(`/workspaces/${workspace.id}?confirm=true`);
        await auth.refreshAccounts();

        if (activeId() === workspace.id) {
            // We just deleted the active workspace.
            if (auth.accounts.length > 0) {
                await auth.switchAccount(auth.accounts[0].id);
            } else {
                auth.logout();
                return;
            }
        }

        await load();
    } catch (err) {
        error.value = err?.response?.data?.error ?? err?.message;
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-bold">Workspaces</h1>
        <AdminTabs />

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <template v-else>
            <div class="card mb-4">
                <h2 class="mb-3 text-sm font-semibold">Create a workspace</h2>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Organization name</label>
                        <input v-model="creating.name" class="input !w-72" placeholder="Ministry of Agriculture" />
                    </div>
                    <button class="btn" :disabled="creating.busy || !creating.name" @click="createWorkspace">
                        {{ creating.busy ? 'Creating…' : 'Create' }}
                    </button>
                    <span class="text-xs text-slate-500">
                        You become Owner; default roles are provisioned automatically.
                    </span>
                </div>
            </div>

            <div class="card overflow-x-auto !p-0">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="th">Name</th>
                            <th class="th">Slug</th>
                            <th class="th">Your role</th>
                            <th class="th">Members</th>
                            <th class="th">Forms</th>
                            <th class="th">Submissions</th>
                            <th class="th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="workspace in workspaces"
                            :key="workspace.id"
                            class="border-b border-slate-800/60 last:border-0"
                        >
                            <td class="td font-medium">
                                <template v-if="editingId === workspace.id">
                                    <input v-model="renameDraft" class="input !w-56 !py-1" />
                                </template>
                                <template v-else>
                                    {{ workspace.attributes.name }}
                                    <span
                                        v-if="workspace.attributes.active"
                                        class="ml-2 rounded-full bg-emerald-900/60 px-2 py-0.5 text-xs text-emerald-300"
                                    >
                                        active
                                    </span>
                                </template>
                            </td>
                            <td class="td font-mono text-xs text-slate-400">{{ workspace.attributes.slug }}</td>
                            <td class="td">{{ workspace.attributes.role }}</td>
                            <td class="td">{{ workspace.attributes.counts.members }}</td>
                            <td class="td">{{ workspace.attributes.counts.forms }}</td>
                            <td class="td">{{ workspace.attributes.counts.submissions }}</td>
                            <td class="td text-right whitespace-nowrap">
                                <template v-if="workspace.attributes.active">
                                    <button
                                        v-if="editingId === workspace.id"
                                        class="mr-2 text-xs text-emerald-400 hover:underline"
                                        :disabled="saving || renameDraft === workspace.attributes.name"
                                        @click="saveRename(workspace)"
                                    >
                                        Save
                                    </button>
                                    <button
                                        v-if="editingId === workspace.id"
                                        class="mr-2 text-xs text-slate-400 hover:underline"
                                        @click="editingId = null"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        v-else
                                        class="mr-2 text-xs text-slate-300 hover:underline"
                                        @click="startRename(workspace)"
                                    >
                                        Rename
                                    </button>
                                    <button
                                        class="text-xs text-red-400 hover:underline"
                                        :disabled="saving"
                                        @click="deleteWorkspace(workspace)"
                                    >
                                        Delete
                                    </button>
                                </template>
                                <button
                                    v-else
                                    class="text-xs text-emerald-400 hover:underline"
                                    :disabled="saving"
                                    @click="switchTo(workspace)"
                                >
                                    Switch to manage
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
