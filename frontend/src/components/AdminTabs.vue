<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';

/** Sub-navigation shared by all administration views. */
const auth = useAuthStore();

const tabs = computed(() =>
    [
        { to: '/admin/members', label: 'Members', permission: 'members.manage' },
        { to: '/admin/roles', label: 'Roles & permissions', permission: 'roles.manage' },
        { to: '/admin/workspaces', label: 'Workspaces', permission: 'accounts.manage' },
        { to: '/admin/settings', label: 'Settings', permission: 'accounts.manage' },
        { to: '/admin/fields', label: 'Fields', permission: 'forms.manage' },
    ].filter((tab) => auth.hasPermission(tab.permission)),
);
</script>

<template>
    <nav class="mb-5 flex flex-wrap gap-1">
        <router-link
            v-for="tab in tabs"
            :key="tab.to"
            :to="tab.to"
            class="rounded-md px-3 py-1.5 text-sm transition hover:bg-slate-800"
            active-class="!bg-slate-800 !text-emerald-300 !font-medium"
        >
            {{ tab.label }}
        </router-link>
    </nav>
</template>
