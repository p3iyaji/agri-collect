import { defineStore } from 'pinia';

const STORAGE_KEY = 'dc.theme';

function readPreference() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

function systemPrefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

/**
 * Light / dark appearance. An explicit choice is stored in localStorage;
 * otherwise the OS preference is followed. The same key is read by the
 * inline script in index.html so the first paint matches.
 */
export const useThemeStore = defineStore('theme', {
    state: () => ({
        preference: readPreference(),
        systemDark: systemPrefersDark(),
    }),

    getters: {
        resolved(state) {
            if (state.preference === 'system') {
                return state.systemDark ? 'dark' : 'light';
            }

            return state.preference;
        },

        isDark() {
            return this.resolved === 'dark';
        },
    },

    actions: {
        apply() {
            const theme = this.resolved;
            const root = document.documentElement;

            root.classList.remove('light', 'dark');
            root.classList.add(theme);
            root.style.colorScheme = theme;

            const meta = document.querySelector('meta[name="theme-color"]');

            if (meta) {
                meta.setAttribute('content', theme === 'light' ? '#ffffff' : '#0f172a');
            }
        },

        toggle() {
            this.preference = this.isDark ? 'light' : 'dark';

            try {
                localStorage.setItem(STORAGE_KEY, this.preference);
            } catch {
                // Private mode can reject storage; the class still updates.
            }

            this.apply();
        },

        init() {
            this.apply();

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
                this.systemDark = event.matches;

                if (this.preference === 'system') {
                    this.apply();
                }
            });
        },
    },
});
