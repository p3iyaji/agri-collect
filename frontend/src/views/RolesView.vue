<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminTabs from '@/components/AdminTabs.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const base = computed(() => `/workspaces/${auth.activeAccount.id}`);

const roles = ref([]);
const catalog = ref([]); // { key, description }[]
const loading = ref(true);
const error = ref(null);

const creating = ref(false);
const newRole = reactive({ name: '', permissions: [] });

const editingId = ref(null);
const editDraft = reactive({ name: '', permissions: [] });

const roleById = computed(() => Object.fromEntries(roles.value.map((r) => [r.id, r])));

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get(`${base.value}/roles`);
        roles.value = response.data.data ?? [];
        catalog.value = Object.entries(response.data.meta?.permissions ?? {}).map(([key, description]) => ({
            key,
            description,
        }));
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

function togglePermission(list, key) {
    const index = list.indexOf(key);

    if (index >= 0) {
        list.splice(index, 1);
    } else {
        list.push(key);
    }
}

async function createRole() {
    creating.value = true;
    error.value = null;

    try {
        await api.post(`${base.value}/roles`, {
            name: newRole.name,
            permissions: [...newRole.permissions],
        });

        newRole.name = '';
        newRole.permissions = [];
        await load();
    } catch (err) {
        error.value = err?.response?.data?.message ?? err?.response?.data?.error ?? err?.message;
    } finally {
        creating.value = false;
    }
}

function startEdit(role) {
    editingId.value = role.id;
    editDraft.name = role.attributes.name;
    editDraft.permissions = [...(role.attributes.permissions ?? [])];
}

function cancelEdit() {
    editingId.value = null;
    editDraft.name = '';
    editDraft.permissions = [];
}

async function saveEdit(role) {
    error.value = null;

    try {
        await api.patch(`${base.value}/roles/${role.id}`, {
            name: editDraft.name,
            permissions: [...editDraft.permissions],
        });
        cancelEdit();
        await load();
    } catch (err) {
        error.value = err?.response?.data?.message ?? err?.response?.data?.error ?? err?.message;
    }
}

async function deleteRole(role) {
    const ok = window.confirm(
        `Delete the role "${role.attributes.name}"? Members with this role will have no role.`,
    );

    if (!ok) {
        return;
    }

    error.value = null;

    try {
        await api.delete(`${base.value}/roles/${role.id}`);
        if (editingId.value === role.id) {
            cancelEdit();
        }
        await load();
    } catch (err) {
        error.value = err?.response?.data?.error ?? err?.message;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-bold">Roles &amp; permissions</h1>
        <AdminTabs />

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <template v-else>
            <p class="mb-4 text-xs text-slate-400">
                Permission changes take effect when tokens are re-issued — on the next login,
                refresh, or workspace switch.
            </p>

            <!-- Role list -->
            <div class="space-y-3">
                <div v-for="role in roles" :key="role.id" class="card">
                    <div v-if="editingId !== role.id" class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h2 class="font-semibold">{{ role.attributes.name }}</h2>
                                <span
                                    v-if="role.attributes.grants_admin"
                                    class="rounded-full bg-emerald-900/60 px-2 py-0.5 text-xs text-emerald-300"
                                >
                                    admin
                                </span>
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ role.attributes.member_count }} active member
                                {{ role.attributes.member_count === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <button class="btn-ghost text-xs" @click="startEdit(role)">Edit</button>
                        <button
                            class="text-xs text-red-400 hover:underline"
                            :disabled="role.attributes.member_count > 0"
                            :title="role.attributes.member_count > 0 ? 'Reassign its members first' : ''"
                            @click="deleteRole(role)"
                        >
                            Delete
                        </button>
                    </div>

                    <template v-else>
                        <div class="space-y-3">
                            <input v-model="editDraft.name" class="input !w-56" />
                            <div class="grid gap-1.5 sm:grid-cols-2">
                                <label
                                    v-for="entry in catalog"
                                    :key="entry.key"
                                    class="flex cursor-pointer items-start gap-2 rounded-md bg-slate-950/60 p-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 accent-emerald-500"
                                        :checked="editDraft.permissions.includes(entry.key)"
                                        @change="togglePermission(editDraft.permissions, entry.key)"
                                    />
                                    <span>
                                        <span class="font-mono text-xs">{{ entry.key }}</span>
                                        <span class="block text-xs text-slate-400">{{ entry.description }}</span>
                                    </span>
                                </label>
                            </div>
                            <div class="flex gap-2">
                                <button class="btn text-xs" @click="saveEdit(role)">Save changes</button>
                                <button class="btn-ghost text-xs" @click="cancelEdit">Cancel</button>
                            </div>
                        </div>
                    </template>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span
                            v-for="permission in role.attributes.permissions ?? []"
                            :key="permission"
                            class="rounded-full bg-slate-800 px-2 py-0.5 font-mono text-xs text-slate-300"
                        >
                            {{ permission }}
                        </span>
                        <span v-if="(role.attributes.permissions ?? []).length === 0" class="text-xs text-slate-500">
                            No permissions
                        </span>
                    </div>
                </div>
            </div>

            <!-- New role -->
            <div class="card mt-4">
                <h2 class="mb-3 text-sm font-semibold">Create a role</h2>
                <input v-model="newRole.name" class="input mb-3 !w-56" placeholder="Role name" />
                <div class="grid gap-1.5 sm:grid-cols-2">
                    <label
                        v-for="entry in catalog"
                        :key="entry.key"
                        class="flex cursor-pointer items-start gap-2 rounded-md bg-slate-950/60 p-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 accent-emerald-500"
                            :checked="newRole.permissions.includes(entry.key)"
                            @change="togglePermission(newRole.permissions, entry.key)"
                        />
                        <span>
                            <span class="font-mono text-xs">{{ entry.key }}</span>
                            <span class="block text-xs text-slate-400">{{ entry.description }}</span>
                        </span>
                    </label>
                </div>
                <button class="btn mt-3" :disabled="creating || !newRole.name" @click="createRole">
                    {{ creating ? 'Creating…' : 'Create role' }}
                </button>
            </div>
        </template>
    </div>
</template>
