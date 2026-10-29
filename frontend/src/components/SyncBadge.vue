<script setup>
import { useOfflineQueue } from '@/composables/useOfflineQueue';

const { pending, syncing, lastError, lastSyncAt } = useOfflineQueue();

function formatTimestamp(timestamp) {
    if (!timestamp) {
        return 'never';
    }

    return new Date(timestamp).toLocaleTimeString();
}
</script>

<template>
    <span
        class="flex items-center gap-1.5 rounded-full border border-slate-700 px-3 py-1 text-xs text-slate-300"
        :class="{
            'border-amber-600 text-amber-300': pending > 0 && !lastError,
            'border-red-700 text-red-300': lastError,
        }"
        :title="lastError || undefined"
    >
        <span
            class="inline-block h-2 w-2 rounded-full"
            :class="{
                'animate-pulse bg-amber-400': syncing,
                'bg-red-500': lastError,
                'bg-amber-400': !syncing && pending > 0,
                'bg-emerald-500': !syncing && pending === 0,
            }"
        ></span>
        <template v-if="syncing">Syncing…</template>
        <template v-else-if="pending > 0">{{ pending }} queued</template>
        <template v-else>Synced {{ formatTimestamp(lastSyncAt) }}</template>
    </span>
</template>
