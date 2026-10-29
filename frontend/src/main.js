import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { useThemeStore } from './stores/theme';
import './style.css';
import { registerSW } from 'virtual:pwa-register';

// PWA: register the service worker (app shell precache, auto updates).
registerSW({ immediate: true });

const app = createApp(App);

app.use(createPinia());
useThemeStore().init();
app.use(router);

app.mount('#app');
