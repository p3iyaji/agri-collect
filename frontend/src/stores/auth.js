import { defineStore } from 'pinia';
import { api } from '@/services/api';

function read(key, fallback) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : fallback;
    } catch {
        return fallback;
    }
}

/**
 * Session state: user, the workspaces the user belongs to, the active
 * workspace, and the JWT pair. Persisted to localStorage so a page reload
 * (or app relaunch on mobile) restores the session; the refresh token
 * re-issues short-lived access tokens.
 *
 * Tenant switching = POST /auth/switch-account, which re-issues the token
 * pair with the new account_id embedded — no other state changes.
 */
export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: read('dc.user', null),
        accounts: read('dc.accounts', []),
        activeAccount: read('dc.active', null),
        accessToken: localStorage.getItem('dc.access') || null,
        refreshToken: localStorage.getItem('dc.refresh') || null,
    }),

    getters: {
        isAuthed: (state) => Boolean(state.accessToken),

        hasPermission: (state) => (permission) =>
            (state.activeAccount?.permissions ?? []).includes(permission),
    },

    actions: {
        applySession(data) {
            this.user = data.user;
            this.accounts = data.accounts ?? [];
            this.activeAccount =
                data.active_account ?? this.accounts.find((account) => account.active) ?? null;
            this.accessToken = data.access_token;
            this.refreshToken = data.refresh_token;

            localStorage.setItem('dc.user', JSON.stringify(this.user));
            localStorage.setItem('dc.accounts', JSON.stringify(this.accounts));
            localStorage.setItem('dc.active', JSON.stringify(this.activeAccount));
            localStorage.setItem('dc.access', this.accessToken);
            localStorage.setItem('dc.refresh', this.refreshToken);
        },

        /**
         * @param {string} email
         * @param {string} password
         * @param {number} [accountId] pin the session to one workspace
         *                                (workspace-branded login pages)
         */
        async login(email, password, accountId = undefined) {
            const { data } = await api.post('/auth/login', {
                email,
                password,
                account_id: accountId,
            });
            this.applySession(data);
        },

        async switchAccount(accountId) {
            const { data } = await api.post('/auth/switch-account', {
                account_id: accountId,
            });
            this.applySession(data);
        },

        async refresh() {
            const { data } = await api.post('/auth/refresh', {
                refresh_token: this.refreshToken,
            });
            this.applySession(data);
        },

        /**
         * Re-fetch the workspace list from /auth/me (e.g. after creating or
         * deleting a workspace so the switcher and admin pages stay current).
         */
        async refreshAccounts() {
            const { data } = await api.get('/auth/me');
            this.accounts = data.meta?.accounts ?? [];
            this.activeAccount = this.accounts.find((account) => account.active) ?? null;

            localStorage.setItem('dc.accounts', JSON.stringify(this.accounts));
            localStorage.setItem('dc.active', JSON.stringify(this.activeAccount));
        },

        logout() {
            this.user = null;
            this.accounts = [];
            this.activeAccount = null;
            this.accessToken = null;
            this.refreshToken = null;

            ['dc.user', 'dc.accounts', 'dc.active', 'dc.access', 'dc.refresh'].forEach((key) =>
                localStorage.removeItem(key),
            );

            window.location.href = '/login';
        },
    },
});
