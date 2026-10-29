<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';

/**
 * Header dropdown for the administration section. Items appear only when
 * the user's active workspace grants the matching permission.
 */
const auth = useAuthStore();
const open = ref(false);
const wrapper = ref(null);

const items = computed(() =>
    [
        { to: '/admin/members', label: 'Members', permission: 'members.manage' },
        { to: '/admin/roles', label: 'Roles & permissions', permission: 'roles.manage' },
        { to: '/admin/workspaces', label: 'Workspaces', permission: 'accounts.manage' },
        { to: '/admin/settings', label: 'Workspace settings', permission: 'accounts.manage' },
        { to: '/admin/fields', label: 'Fields', permission: 'forms.manage' },
    ].filter((item) => auth.hasPermission(item.permission)),
);

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
</script>

<template>
    <div v-if="items.length > 0" ref="wrapper" class="relative">
        <button class="btn-ghost" @click="open = !open">
            Admin <span class="text-xs">▾</span>
        </button>

        <div
            v-if="open"
            class="absolute left-0 top-full z-30 mt-1 w-52 rounded-md border border-slate-700 bg-slate-900 p-1 shadow-xl"
        >
            <p class="px-2 py-1 text-xs uppercase tracking-wide text-slate-500">
                {{ auth.activeAccount?.name }}
            </p>
            <router-link
                v-for="item in items"
                :key="item.to"
                :to="item.to"
                class="block rounded px-2 py-1.5 text-sm transition hover:bg-slate-800"
                active-class="!bg-slate-800 !text-emerald-300"
                @click="close"
            >
                {{ item.label }}
            </router-link>
        </div>
    </div>
</template>
