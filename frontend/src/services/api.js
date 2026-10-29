import axios from 'axios';
import { useAuthStore } from '@/stores/auth';

/**
 * Axios client for the Laravel API.
 *
 * - Attaches the Bearer access token to every request.
 * - On 401, refreshes the token pair once (deduplicated across parallel
 *   requests) and retries the original request transparently.
 * - If the refresh itself fails, the session is cleared.
 */
export const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api',
    headers: { Accept: 'application/json' },
});

let refreshInFlight = null;

api.interceptors.request.use((config) => {
    const auth = useAuthStore();

    if (auth.accessToken && !/\/auth\/refresh$/.test(config.url ?? '')) {
        config.headers.Authorization = `Bearer ${auth.accessToken}`;
    }

    return config;
});

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        const { response, config } = error;
        const isAuthCall = /\/auth\/(login|refresh)$/.test(config?.url ?? '');

        if (response?.status === 401 && config && !config._retry && !isAuthCall) {
            const auth = useAuthStore();

            if (auth.refreshToken) {
                config._retry = true;

                try {
                    // One shared refresh for all concurrent 401s.
                    refreshInFlight ??= auth.refresh().finally(() => {
                        refreshInFlight = null;
                    });

                    await refreshInFlight;
                    config.headers = {
                        ...config.headers,
                        Authorization: `Bearer ${auth.accessToken}`,
                    };

                    return api(config);
                } catch (refreshError) {
                    auth.logout();
                    return Promise.reject(refreshError);
                }
            }
        }

        return Promise.reject(error);
    },
);

export default api;
