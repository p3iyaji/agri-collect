import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

import DashboardView from '@/views/DashboardView.vue';
import FieldsView from '@/views/FieldsView.vue';
import FormFillView from '@/views/FormFillView.vue';
import FormBuilderView from '@/views/FormBuilderView.vue';
import FormsView from '@/views/FormsView.vue';
import LoginView from '@/views/LoginView.vue';
import MembersView from '@/views/MembersView.vue';
import RolesView from '@/views/RolesView.vue';
import SubmissionsView from '@/views/SubmissionsView.vue';
import WorkspaceSettingsView from '@/views/WorkspaceSettingsView.vue';
import WorkspacesView from '@/views/WorkspacesView.vue';

/** Administration section: first tab the current user may see. */
const ADMIN_TABS = [
    ['members.manage', '/admin/members'],
    ['roles.manage', '/admin/roles'],
    ['accounts.manage', '/admin/workspaces'],
    ['accounts.manage', '/admin/settings'],
    ['forms.manage', '/admin/fields'],
];

/**
 * Per-organization builds: set VITE_WORKSPACE_SLUG when building the SPA
 * (or the Capacitor app) for one workspace and /login always lands on
 * that organization's branded login page.
 */
const DEFAULT_WORKSPACE_SLUG = import.meta.env.VITE_WORKSPACE_SLUG || null;

const routes = [
    {
        path: '/login/:workspaceSlug?',
        name: 'login',
        component: LoginView,
        meta: { guestOnly: true },
    },
    { path: '/', redirect: '/dashboard' },
    {
        path: '/dashboard',
        component: DashboardView,
        meta: { requiresAuth: true, permission: 'dashboard.read' },
    },
    {
        path: '/forms',
        component: FormsView,
        meta: { requiresAuth: true, permission: 'submissions.read' },
    },
    {
        path: '/new-form',
        component: FormBuilderView,
        meta: { requiresAuth: true, permission: 'forms.manage' },
    },
    {
        path: '/forms/:id',
        component: FormBuilderView,
        meta: { requiresAuth: true, permission: 'forms.manage' },
    },
    {
        path: '/fill/:formKey',
        component: FormFillView,
        meta: { requiresAuth: true, permission: 'submissions.write' },
    },
    {
        path: '/submissions',
        component: SubmissionsView,
        meta: { requiresAuth: true, permission: 'submissions.read' },
    },

    // Administration
    {
        path: '/admin',
        redirect: () => {
            const auth = useAuthStore();
            const found = ADMIN_TABS.find(([permission]) => auth.hasPermission(permission));
            return found ? found[1] : '/dashboard';
        },
    },
    {
        path: '/admin/members',
        component: MembersView,
        meta: { requiresAuth: true, permission: 'members.manage' },
    },
    {
        path: '/admin/roles',
        component: RolesView,
        meta: { requiresAuth: true, permission: 'roles.manage' },
    },
    {
        path: '/admin/workspaces',
        component: WorkspacesView,
        meta: { requiresAuth: true, permission: 'accounts.manage' },
    },
    {
        path: '/admin/settings',
        component: WorkspaceSettingsView,
        meta: { requiresAuth: true, permission: 'accounts.manage' },
    },
    {
        path: '/admin/fields',
        component: FieldsView,
        meta: { requiresAuth: true, permission: 'forms.manage' },
    },

    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to) => {
    const auth = useAuthStore();

    // Per-organization builds always land on the branded login page.
    if (DEFAULT_WORKSPACE_SLUG && to.path === '/login' && !to.params.workspaceSlug) {
        return `/login/${DEFAULT_WORKSPACE_SLUG}`;
    }

    if (to.meta.requiresAuth && !auth.isAuthed) {
        return '/login';
    }

    if (to.meta.guestOnly && auth.isAuthed) {
        return '/dashboard';
    }

    if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
        return '/dashboard';
    }

    return true;
});
