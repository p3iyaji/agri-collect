import { fileURLToPath, URL } from 'node:url';

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

// Standalone Vue 3 SPA (no Inertia). In dev, /api is proxied to the
// Laravel backend; in production set VITE_API_URL to the API origin
// (also required for the Capacitor build, which ships without a proxy).
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    plugins: [
        vue(),
        tailwindcss(),
        VitePWA({
            registerType: 'autoUpdate',
            includeAssets: ['favicon.svg', 'pwa.svg'],
            manifest: {
                name: 'DigitCapture',
                short_name: 'DigitCap',
                description: 'Offline-first field data collection for agricultural research',
                theme_color: '#0f172a',
                background_color: '#0f172a',
                display: 'standalone',
                icons: [
                    {
                        src: 'pwa.svg',
                        sizes: 'any',
                        type: 'image/svg+xml',
                        purpose: 'any',
                    },
                ],
            },
            // Only the app shell is precached. Form templates and
            // submissions are cached explicitly in IndexedDB (keyed by
            // workspace), never in the service worker — a SW cache cannot
            // distinguish which tenant a /api/forms response belongs to.
            workbox: {
                navigateFallback: '/index.html',
            },
        }),
    ],
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
});
