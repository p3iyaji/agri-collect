<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

/**
 * Tenant switching UI. Choosing a workspace calls
 * POST /api/auth/switch-account, which re-issues the JWT pair with the
 * new account_id embedded; the SPA then reloads data for the new tenant.
 */
const auth = useAuthStore();
const router = useRouter();

const open = ref(false);
const switching = ref(false);
const wrapper = ref(null);

function toggle() {
    open.value = !open.value;
}

function close() {
    open.value = false;
}

function onClickOutside(event) {
    if (wrapper.value && !wrapper.value.contains(event.target)) {
        close();
    }
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));

async function switchTo(accountId) {
    if (accountId === auth.activeAccount?.id) {
        close();
        return;
    }

    switching.value = true;

    try {
        await auth.switchAccount(accountId);
        router.push('/dashboard');
    } finally {
        switching.value = false;
        close();
    }
}
</script>

<template>
    <div ref="wrapper" class="relative">
        <button class="btn-ghost flex items-center gap-2" :disabled="switching" @click="toggle">
            <img
                v-if="auth.activeAccount?.logo_url"
                :src="auth.activeAccount.logo_url"
                :alt="auth.activeAccount.name"
                class="h-5 w-5 rounded object-cover"
            />
            <span v-else class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span class="max-w-40 truncate">{{ auth.activeAccount?.name ?? 'No workspace' }}</span>
            <span class="text-xs text-slate-400">{{ auth.activeAccount?.role }}</span>
            <span class="text-xs">▾</span>
        </button>

        <div
            v-if="open"
            class="absolute left-0 top-full z-30 mt-1 w-64 rounded-md border border-slate-700 bg-slate-900 p-1 shadow-xl"
        >
            <p class="px-2 py-1 text-xs uppercase tracking-wide text-slate-500">Switch workspace</p>
            <button
                v-for="account in auth.accounts"
                :key="account.id"
                class="flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm transition hover:bg-slate-800"
                :class="{ 'cursor-default opacity-50 hover:bg-transparent': account.id === auth.activeAccount?.id }"
                @click="switchTo(account.id)"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <img v-if="account.logo_url" :src="account.logo_url" :alt="account.name" class="h-5 w-5 shrink-0 rounded object-cover" />
                    <span class="truncate">{{ account.name }}</span>
                </span>
                <span class="ml-2 shrink-0 text-xs text-slate-400">{{ account.role }}</span>
            </button>
        </div>
    </div>
</template>
