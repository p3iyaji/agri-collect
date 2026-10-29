<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminTabs from '@/components/AdminTabs.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const base = computed(() => `/workspaces/${auth.activeAccount.id}`);

const fields = ref([]);
const forms = ref([]);
const formFilter = ref('');
const loading = ref(true);
const error = ref(null);

const visibleFields = computed(() =>
    formFilter.value
        ? fields.value.filter((field) => field.attributes.form_id === Number(formFilter.value))
        : fields.value,
);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const [fieldsRes, formsRes] = await Promise.all([
            api.get(`${base.value}/fields`),
            api.get('/forms'),
        ]);

        fields.value = fieldsRes.data.data ?? [];
        forms.value = (formsRes.data.data ?? []).map((form) => ({
            id: form.id,
            name: form.attributes.name,
            status: form.attributes.status,
        }));
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

function describeSkipLogic(visibleIf) {
    if (!visibleIf || !visibleIf.field) {
        return '';
    }

    const operator = { eq: 'is', neq: 'is not', gt: '>', lt: '<', set: 'is filled' }[visibleIf.op] ?? visibleIf.op;

    if (visibleIf.op === 'set') {
        return `when ${visibleIf.field} ${operator}`;
    }

    return `when ${visibleIf.field} ${operator} ${visibleIf.value ?? ''}`;
}

function describeOptions(options) {
    if (!options || options.length === 0) {
        return '';
    }

    const preview = options.slice(0, 4).join(', ');

    return options.length > 4 ? `${preview} +${options.length - 4}` : preview;
}

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold">Fields</h1>
            <select v-model="formFilter" class="input !w-56">
                <option value="">All forms</option>
                <option v-for="form in forms" :key="form.id" :value="form.id">
                    {{ form.name }} {{ form.status !== 'active' ? `(${form.status})` : '' }}
                </option>
            </select>
        </div>
        <AdminTabs />

        <p class="mb-4 text-xs text-slate-400">
            Fields are defined inside each form's schema. Use <router-link to="/new-form" class="text-emerald-400 hover:underline">New form</router-link>
            or a form's Edit link to add, change, and remove fields.
        </p>

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <div v-else class="card overflow-x-auto !p-0">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-800">
                            <th class="th">Form</th>
                            <th class="th">Label</th>
                            <th class="th">Name</th>
                            <th class="th">Type</th>
                            <th class="th">Required</th>
                            <th class="th">Options</th>
                            <th class="th">Skip logic</th>
                            <th class="th">Form submissions</th>
                            <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="visibleFields.length === 0">
                        <td colspan="9" class="td text-slate-400">No fields defined yet.</td>
                    </tr>
                    <tr
                        v-for="field in visibleFields"
                        :key="field.id"
                        class="border-b border-slate-800/60 last:border-0"
                    >
                        <td class="td text-slate-300">{{ field.attributes.form_name }}</td>
                        <td class="td font-medium">{{ field.attributes.label }}</td>
                        <td class="td font-mono text-xs text-slate-400">{{ field.attributes.name }}</td>
                        <td class="td">
                            <span class="rounded-full bg-slate-800 px-2 py-0.5 text-xs">
                                {{ field.attributes.type }}
                            </span>
                        </td>
                        <td class="td">{{ field.attributes.required ? 'Yes' : '—' }}</td>
                        <td class="td max-w-48 truncate text-xs text-slate-400" :title="describeOptions(field.attributes.options)">
                            {{ describeOptions(field.attributes.options) }}
                        </td>
                        <td class="td text-xs text-slate-400">
                            {{ describeSkipLogic(field.attributes.visible_if) || '—' }}
                        </td>
                        <td class="td">{{ field.attributes.form_submissions }}</td>
                        <td class="td text-right">
                            <router-link
                                :to="`/forms/${field.attributes.form_id}`"
                                class="text-xs text-emerald-400 hover:underline"
                            >
                                Edit form
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
