<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import AdminMenu from '@/components/AdminMenu.vue';
import SyncBadge from '@/components/SyncBadge.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import { useMobileSync } from '@/composables/useMobileSync';
import { useAuthStore } from '@/stores/auth';
import { useThemeStore } from '@/stores/theme';

const auth = useAuthStore();
const theme = useThemeStore();
const sync = useMobileSync({ intervalMs: 30_000 });

// Run the sync engine for the whole session (web + Capacitor webview):
// periodic outbox flushes, online/offline triggers, connectivity state.
// Watched so the engine starts after a fresh login, not just restored sessions.
watch(
    () => auth.isAuthed,
    (isAuthed) => {
        if (isAuthed) {
            sync.start();
        } else {
            sync.stop();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    sync.stop();
});
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <header class="sticky top-0 z-20 border-b border-slate-800 bg-slate-900/95 backdrop-blur">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3">
                <router-link to="/dashboard" class="flex items-center gap-2 text-lg font-bold tracking-tight">
                    <img
                        v-if="auth.activeAccount?.logo_url"
                        :src="auth.activeAccount.logo_url"
                        :alt="auth.activeAccount.name"
                        class="h-8 w-8 rounded-md object-cover"
                    />
                    <span v-else class="text-emerald-400">🌾</span>
                    <span>{{ auth.activeAccount?.logo_url ? auth.activeAccount.name : 'DigitCapture' }}</span>
                </router-link>

                <template v-if="auth.isAuthed">
                    <WorkspaceSwitcher />
                    <SyncBadge />
                    <AdminMenu />
                </template>
                <div class="ml-auto flex items-center gap-3">
                    <span v-if="auth.isAuthed" class="hidden text-sm text-slate-400 sm:inline">{{ auth.user?.name }}</span>
                    <button
                        type="button"
                        class="btn-ghost cursor-pointer !px-2"
                        :aria-label="theme.isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        :title="theme.isDark ? 'Light mode' : 'Dark mode'"
                        @click="theme.toggle()"
                    >
                        <svg
                            v-if="theme.isDark"
                            viewBox="0 0 20 20"
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                        >
                            <circle cx="10" cy="10" r="3.25" />
                            <path
                                stroke-linecap="round"
                                d="M10 2.5v1.5M10 16v1.5M2.5 10H4M16 10h1.5M4.4 4.4l1.1 1.1M14.5 14.5l1.1 1.1M15.6 4.4l-1.1 1.1M5.5 14.5l-1.1 1.1"
                            />
                        </svg>
                        <svg
                            v-else
                            viewBox="0 0 20 20"
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M14.5 12.2A5.5 5.5 0 0 1 8 5.6 5.5 5.5 0 1 0 14.5 12.2Z"
                            />
                        </svg>
                    </button>
                    <button v-if="auth.isAuthed" class="btn-ghost" @click="auth.logout()">Log out</button>
                </div>
            </div>

            <nav v-if="auth.isAuthed" class="mx-auto flex max-w-6xl gap-1 px-4 pb-2 text-sm">
                <router-link
                    to="/dashboard"
                    class="rounded-md px-3 py-1.5 transition hover:bg-slate-800"
                    active-class="!bg-slate-800 !text-emerald-300"
                >
                    Dashboard
                </router-link>
                <router-link
                    to="/forms"
                    class="rounded-md px-3 py-1.5 transition hover:bg-slate-800"
                    active-class="!bg-slate-800 !text-emerald-300"
                >
                    Forms
                </router-link>
                <router-link
                    to="/submissions"
                    class="rounded-md px-3 py-1.5 transition hover:bg-slate-800"
                    active-class="!bg-slate-800 !text-emerald-300"
                >
                    Submissions
                </router-link>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6">
            <router-view />
        </main>
    </div>
</template>
