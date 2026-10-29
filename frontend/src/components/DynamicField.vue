<script setup>
import { ref, watch } from 'vue';

/**
 * Renders a single schema-driven field (text, number, select, date).
 * Photo and GPS fields are handled by the parent view (FormFillView),
 * because they need camera / geolocation access.
 */
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean, null], default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const local = ref(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (value) => {
        local.value = value ?? '';
    },
);

watch(local, (value) => {
    emit('update:modelValue', value === '' ? null : value);
});
</script>

<template>
    <div class="mb-4">
        <label class="mb-1 block text-sm font-medium">
            {{ field.label }}
            <span v-if="field.required" class="text-red-400">*</span>
        </label>

        <select v-if="field.type === 'select'" v-model="local" class="input" :disabled="disabled">
            <option value="">— select —</option>
            <option v-for="option in field.options ?? []" :key="option" :value="option">
                {{ option }}
            </option>
        </select>

        <input
            v-else-if="field.type === 'number'"
            v-model.number="local"
            type="number"
            step="any"
            inputmode="decimal"
            class="input"
            :disabled="disabled"
        />

        <input
            v-else-if="field.type === 'date'"
            v-model="local"
            type="date"
            class="input"
            :disabled="disabled"
        />

        <input v-else v-model="local" type="text" class="input" :disabled="disabled" />

        <p v-if="field.help_text" class="mt-1 text-xs text-slate-400">{{ field.help_text }}</p>
    </div>
</template>
