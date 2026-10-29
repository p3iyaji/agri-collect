<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/services/api';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.params.id !== undefined);
const formId = ref(null);

const meta = reactive({
    name: '',
    slug: '',
    description: '',
    active: false,
});

const fields = ref([]);
const saving = ref(false);
const error = ref(null);

function slugify(value) {
    return (value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

async function loadExisting() {
    const response = await api.get(`/forms/${route.params.id}`);
    const data = response.data.data;

    formId.value = data.id;
    meta.name = data.attributes.name;
    meta.slug = data.attributes.slug;
    meta.description = data.attributes.description ?? '';
    meta.active = data.attributes.status === 'active';

    fields.value = (data.attributes.schema?.fields ?? []).map((field) => ({
        name: field.name,
        label: field.label,
        type: field.type,
        required: Boolean(field.required),
        help_text: field.help_text ?? '',
        optionsText: (field.options ?? []).join(', '),
        visible_if_field: field.visible_if?.field ?? '',
        visible_if_op: field.visible_if?.op ?? 'eq',
        visible_if_value: field.visible_if?.value ?? '',
    }));
}

function addField() {
    fields.value.push({
        name: '',
        label: '',
        type: 'text',
        required: false,
        help_text: '',
        optionsText: '',
        visible_if_field: '',
        visible_if_op: 'eq',
        visible_if_value: '',
    });
}

function removeField(index) {
    fields.value.splice(index, 1);
}

/**
 * Shape a field row into the API schema format.
 */
function normalize(field) {
    const name = field.name || slugify(field.label);

    const out = {
        name,
        label: field.label,
        type: field.type,
    };

    if (field.required) {
        out.required = true;
    }
    if (field.help_text) {
        out.help_text = field.help_text;
    }
    if (field.type === 'select') {
        out.options = field.optionsText
            .split(',')
            .map((option) => option.trim())
            .filter(Boolean);
    }
    if (field.visible_if_field) {
        out.visible_if = {
            field: field.visible_if_field,
            op: field.visible_if_op,
            value: field.visible_if_value || undefined,
        };
    }

    return out;
}

async function save() {
    error.value = null;

    const payload = {
        name: meta.name,
        slug: meta.slug || undefined,
        description: meta.description || undefined,
        status: meta.active ? 'active' : 'draft',
        schema: {
            fields: fields.value
                .map(normalize)
                .filter((field) => field.name && field.label),
        },
    };

    saving.value = true;

    try {
        if (formId.value) {
            await api.put(`/forms/${formId.value}`, payload);
        } else {
            const response = await api.post('/forms', payload);
            formId.value = response.data.data.id;
        }

        router.push('/forms');
    } catch (saveError) {
        const data = saveError?.response?.data;
        error.value =
            data?.message ??
            (data?.errors ? Object.values(data.errors).flat().join(' ') : saveError?.message);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    if (isEdit.value) {
        try {
            await loadExisting();
        } catch (loadError) {
            error.value = loadError?.response?.data?.error ?? 'Form not found';
        }
    }
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <h1 class="mb-6 text-xl font-bold">{{ formId ? 'Edit form' : 'New form' }}</h1>

        <div class="card mb-4 space-y-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-400">Name</label>
                    <input v-model="meta.name" class="input" placeholder="Crop Health Survey" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-400">Slug (optional)</label>
                    <input v-model="meta.slug" class="input" placeholder="auto-generated if blank" />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-400">Description</label>
                <input v-model="meta.description" class="input" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="meta.active" type="checkbox" class="accent-emerald-500" />
                Active (available in the field app)
            </label>
        </div>

        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-semibold">Fields</h2>
            <button class="btn-ghost" @click="addField">+ Add field</button>
        </div>

        <div v-if="fields.length === 0" class="card text-sm text-slate-400">
            No fields yet — add at least one.
        </div>

        <div v-for="(field, index) in fields" :key="index" class="card mb-3 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <input v-model="field.label" class="input !w-40" placeholder="Label" />
                <input v-model="field.name" class="input !w-36 font-mono" placeholder="name" />
                <select v-model="field.type" class="input !w-28">
                    <option value="text">text</option>
                    <option value="number">number</option>
                    <option value="select">select</option>
                    <option value="date">date</option>
                    <option value="photo">photo</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-400">
                    <input v-model="field.required" type="checkbox" class="accent-emerald-500" />
                    required
                </label>
                <button class="btn-ghost ml-auto !px-2" @click="removeField(index)">Remove</button>
            </div>

            <input
                v-if="field.type === 'photo'"
                v-model="field.help_text"
                class="input"
                placeholder="Help text (e.g. Photo of the crop / pest damage)"
            />

            <div v-if="field.type === 'select'" class="flex items-center gap-2">
                <label class="text-xs text-slate-400">Options (comma-separated)</label>
                <input v-model="field.optionsText" class="input" placeholder="yes, no" />
            </div>

            <div
                v-if="field.type !== 'select'"
                class="flex flex-wrap items-center gap-2 rounded-md bg-slate-950/60 p-2"
            >
                <label class="text-xs text-slate-400">Show only when</label>
                <select v-model="field.visible_if_field" class="input !w-36 !py-1 text-xs">
                    <option value="">— always —</option>
                    <option
                        v-for="other in fields"
                        :key="other.label"
                        :value="other.name || slugify(other.label)"
                        :disabled="!other.name && !other.label || (other.name || slugify(other.label)) === (field.name || slugify(field.label))"
                    >
                        {{ other.label || other.name || '(unnamed)' }}
                    </option>
                </select>
                <select v-if="field.visible_if_field" v-model="field.visible_if_op" class="input !w-28 !py-1 text-xs">
                    <option value="eq">equals</option>
                    <option value="neq">not equals</option>
                    <option value="gt">greater than</option>
                    <option value="lt">less than</option>
                    <option value="set">is filled</option>
                </select>
                <input
                    v-if="field.visible_if_field && field.visible_if_op !== 'set'"
                    v-model="field.visible_if_value"
                    class="input !w-28 !py-1 text-xs"
                    placeholder="value"
                />
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button class="btn" :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : 'Save form' }}
            </button>
            <router-link to="/forms" class="btn-ghost">Cancel</router-link>
            <span v-if="error" class="text-sm text-red-400">{{ error }}</span>
        </div>
    </div>
</template>
