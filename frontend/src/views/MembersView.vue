<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminTabs from '@/components/AdminTabs.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const base = computed(() => `/workspaces/${auth.activeAccount.id}`);

const members = ref([]);
const roles = ref([]);
const loading = ref(true);
const error = ref(null);
const savingId = ref(null);

const blankForm = () => ({
    email: '',
    name: '',
    role_id: '',
    phone: '',
    address: '',
    city: '',
    state: '',
    date_of_engagement: '',
});

const invite = reactive(blankForm());
const inviting = ref(false);
const inviteError = ref(null);
const newCredentials = ref(null);
const copied = ref(false);
const editingId = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const [membersRes, rolesRes] = await Promise.all([
            api.get(`${base.value}/members`),
            api.get(`${base.value}/roles`),
        ]);

        members.value = membersRes.data.data ?? [];
        roles.value = rolesRes.data.data ?? [];
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

function profilePayload() {
    return {
        phone: invite.phone || null,
        address: invite.address || null,
        city: invite.city || null,
        state: invite.state || null,
        date_of_engagement: invite.date_of_engagement || null,
    };
}

function resetForm() {
    Object.assign(invite, blankForm());
    editingId.value = null;
    inviteError.value = null;
}

function editMember(member) {
    editingId.value = member.id;
    invite.email = member.attributes.email ?? '';
    invite.name = member.attributes.name ?? '';
    invite.role_id = member.attributes.role_id ?? '';
    invite.phone = member.attributes.phone ?? '';
    invite.address = member.attributes.address ?? '';
    invite.city = member.attributes.city ?? '';
    invite.state = member.attributes.state ?? '';
    invite.date_of_engagement = member.attributes.date_of_engagement ?? '';
    inviteError.value = null;
    document.querySelector('[data-member-form]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function inviteMember() {
    inviting.value = true;
    inviteError.value = null;

    try {
        if (editingId.value) {
            await api.patch(`${base.value}/members/${editingId.value}`, profilePayload());
            resetForm();
            await load();

            return;
        }

        const response = await api.post(`${base.value}/members`, {
            email: invite.email,
            name: invite.name || undefined,
            role_id: invite.role_id ? Number(invite.role_id) : undefined,
            phone: invite.phone || undefined,
            address: invite.address || undefined,
            city: invite.city || undefined,
            state: invite.state || undefined,
            date_of_engagement: invite.date_of_engagement || undefined,
        });

        if (response.data.meta?.created_user) {
            newCredentials.value = {
                email: response.data.data.attributes.email,
                password: response.data.meta.temp_password,
            };
            copied.value = false;
        }

        resetForm();

        await load();
    } catch (err) {
        inviteError.value =
            err?.response?.data?.error ?? err?.response?.data?.message ?? err?.message;
    } finally {
        inviting.value = false;
    }
}

async function patchMember(member, payload) {
    savingId.value = member.id;

    try {
        await api.patch(`${base.value}/members/${member.id}`, payload);
        await load();
    } catch (err) {
        error.value = err?.response?.data?.error ?? err?.message;
        await load();
    } finally {
        savingId.value = null;
    }
}

function changeRole(member, roleId) {
    patchMember(member, { role_id: roleId === '' ? null : Number(roleId) });
}

function toggleStatus(member) {
    patchMember(member, {
        status: member.attributes.status === 'active' ? 'suspended' : 'active',
    });
}

async function removeMember(member) {
    const ok = window.confirm(
        `Remove ${member.attributes.name} from this workspace? They will lose access immediately.`,
    );

    if (!ok) {
        return;
    }

    savingId.value = member.id;

    try {
        await api.delete(`${base.value}/members/${member.id}`);
        await load();
    } catch (err) {
        error.value = err?.response?.data?.error ?? err?.message;
    } finally {
        savingId.value = null;
    }
}

async function copyPassword() {
    if (!newCredentials.value?.password) {
        return;
    }

    try {
        await navigator.clipboard.writeText(newCredentials.value.password);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-bold">Members</h1>
        <AdminTabs />

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <div v-else class="space-y-4">
            <!-- Invite -->
            <div class="card" data-member-form>
                <h2 class="mb-1 text-sm font-semibold">
                    {{ editingId ? 'Edit member' : 'Invite a member' }}
                </h2>
                <p class="mb-3 text-xs text-slate-500">
                    Phone number, address, city, state, and date of engagement are optional.
                </p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Email</label>
                        <input v-model="invite.email" type="email" required class="input disabled:cursor-not-allowed disabled:opacity-60" placeholder="person@example.org" :disabled="editingId !== null" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Name (new users only)</label>
                        <input v-model="invite.name" class="input disabled:cursor-not-allowed disabled:opacity-60" :disabled="editingId !== null" />
                    </div>
                    <div v-if="!editingId">
                        <label class="mb-1 block text-xs text-slate-400">Role</label>
                        <select v-model="invite.role_id" class="input">
                            <option value="">No role</option>
                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.attributes.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Phone number</label>
                        <input v-model="invite.phone" type="tel" class="input" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs text-slate-400">Address</label>
                        <input v-model="invite.address" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">City</label>
                        <input v-model="invite.city" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">State</label>
                        <input v-model="invite.state" class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-400">Date of engagement</label>
                        <input v-model="invite.date_of_engagement" type="date" class="input" />
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button class="btn" :disabled="inviting || !invite.email" @click="inviteMember">
                        {{ inviting ? 'Saving…' : editingId ? 'Save' : 'Invite' }}
                    </button>
                    <button v-if="editingId" class="btn-ghost" type="button" :disabled="inviting" @click="resetForm">
                        Cancel
                    </button>
                </div>
                <p v-if="inviteError" class="mt-2 text-sm text-red-400">{{ inviteError }}</p>
            </div>

            <!-- One-time password for a freshly created user -->
            <div v-if="newCredentials" class="card border-amber-700/70">
                <p class="text-sm">
                    Created <strong>{{ newCredentials.email }}</strong> — share this one-time
                    password (shown only once):
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code class="rounded bg-slate-950 px-3 py-1 font-mono text-amber-300">
                        {{ newCredentials.password }}
                    </code>
                    <button class="btn-ghost text-xs" @click="copyPassword">
                        {{ copied ? 'Copied ✓' : 'Copy' }}
                    </button>
                    <button class="btn-ghost text-xs" @click="newCredentials = null">Dismiss</button>
                </div>
            </div>

            <!-- Member list -->
            <div class="card overflow-x-auto !p-0">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th class="th">Name</th>
                            <th class="th">Email</th>
                            <th class="th">Phone</th>
                            <th class="th">Address</th>
                            <th class="th">City</th>
                            <th class="th">State</th>
                            <th class="th">Engaged</th>
                            <th class="th">Role</th>
                            <th class="th">Status</th>
                            <th class="th">Joined</th>
                            <th class="th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="member in members"
                            :key="member.id"
                            class="border-b border-slate-800/60 last:border-0"
                            :class="{ 'bg-slate-800/40': editingId === member.id }"
                        >
                            <td class="td font-medium">
                                {{ member.attributes.name }}
                                <span v-if="member.attributes.is_you" class="text-xs text-emerald-400">(you)</span>
                            </td>
                            <td class="td text-slate-400">{{ member.attributes.email }}</td>
                            <td class="td text-slate-400">{{ member.attributes.phone || '—' }}</td>
                            <td class="td max-w-48 truncate text-slate-400">{{ member.attributes.address || '—' }}</td>
                            <td class="td text-slate-400">{{ member.attributes.city || '—' }}</td>
                            <td class="td text-slate-400">{{ member.attributes.state || '—' }}</td>
                            <td class="td text-xs text-slate-400">{{ member.attributes.date_of_engagement || '—' }}</td>
                            <td class="td">
                                <select
                                    class="input !w-32 !py-1 text-xs"
                                    :value="member.attributes.role_id ?? ''"
                                    :disabled="savingId === member.id"
                                    @change="(e) => changeRole(member, e.target.value)"
                                >
                                    <option value="">No role</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id">
                                        {{ role.attributes.name }}
                                    </option>
                                </select>
                            </td>
                            <td class="td">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="{
                                        'bg-emerald-900/60 text-emerald-300': member.attributes.status === 'active',
                                        'bg-slate-800 text-slate-400': member.attributes.status !== 'active',
                                    }"
                                >
                                    {{ member.attributes.status }}
                                </span>
                            </td>
                            <td class="td text-xs text-slate-400">
                                {{ member.attributes.joined_at?.slice(0, 10) }}
                            </td>
                            <td class="td text-right whitespace-nowrap">
                                <button
                                    class="mr-2 text-xs text-emerald-300 hover:underline"
                                    :disabled="savingId === member.id"
                                    @click="editMember(member)"
                                >
                                    Edit
                                </button>
                                <button
                                    class="mr-2 text-xs text-amber-300 hover:underline"
                                    :disabled="savingId === member.id"
                                    @click="toggleStatus(member)"
                                >
                                    {{ member.attributes.status === 'active' ? 'Suspend' : 'Reactivate' }}
                                </button>
                                <button
                                    class="text-xs text-red-400 hover:underline"
                                    :disabled="savingId === member.id"
                                    @click="removeMember(member)"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
