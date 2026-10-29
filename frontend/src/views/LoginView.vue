<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "@/services/api";
import { useAuthStore } from "@/stores/auth";

/**
 * Login page. Two modes:
 *
 * - /login                 → generic platform login (first workspace).
 * - /login/{workspaceSlug} → workspace-branded login: shows the
 *   organization's logo and name and pins the session to THAT workspace
 *   (account_id is sent with the login), landing straight on its
 *   dashboard for field work.
 */
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const workspaceSlug = computed(() => route.params.workspaceSlug);

const workspace = ref(null); // public profile: { id, name, logo_url, organization, field }
const workspaceError = ref(null);
const loadingWorkspace = ref(false);

const email = ref("");
const password = ref("");
const busy = ref(false);
const error = ref(null);

const brandName = computed(() => workspace.value?.name ?? "DigitCapture");
const brandTagline = computed(
    () =>
        workspace.value?.field?.project_name ??
        "Offline-first field data collection",
);

const alreadyInWorkspace = computed(
    () =>
        auth.isAuthed &&
        workspace.value !== null &&
        auth.activeAccount?.id === workspace.value.id,
);

const canSwitchToWorkspace = computed(
    () =>
        auth.isAuthed &&
        workspace.value !== null &&
        !alreadyInWorkspace.value &&
        (auth.accounts ?? []).some(
            (account) => account.id === workspace.value.id,
        ),
);

const noAccessToWorkspace = computed(
    () =>
        auth.isAuthed &&
        workspace.value !== null &&
        !alreadyInWorkspace.value &&
        !canSwitchToWorkspace.value,
);

async function loadWorkspace() {
    if (!workspaceSlug.value) {
        return;
    }

    loadingWorkspace.value = true;
    workspaceError.value = null;

    try {
        const response = await api.get(
            `/workspaces/${workspaceSlug.value}/public-profile`,
        );
        workspace.value = response.data.data.attributes;
    } catch {
        workspaceError.value =
            "This workspace does not exist (or its login page was removed).";
    } finally {
        loadingWorkspace.value = false;
    }
}

async function submit() {
    busy.value = true;
    error.value = null;

    try {
        await auth.login(email.value, password.value, workspace.value?.id);
        router.push("/dashboard");
    } catch (loginError) {
        if (loginError?.response?.status === 403) {
            error.value = workspace.value
                ? "Your account is not a member of this workspace. Ask an administrator to add you."
                : "No active workspace membership found.";
        } else {
            error.value =
                loginError?.response?.data?.errors?.email?.[0] ??
                loginError?.response?.data?.detail ??
                loginError?.message ??
                "Login failed";
        }
    } finally {
        busy.value = false;
    }
}

async function switchToWorkspace() {
    busy.value = true;
    error.value = null;

    try {
        await auth.switchAccount(workspace.value.id);
        router.push("/dashboard");
    } catch (switchError) {
        error.value =
            switchError?.response?.data?.error ?? switchError?.message;
    } finally {
        busy.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <div class="flex min-h-[80vh] items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <form class="space-y-4" @submit.prevent="submit">
                <div class="mb-6 text-center">
                    <div
                        v-if="workspace?.logo_url"
                        class="mb-3 flex justify-center"
                    >
                        <img
                            :src="workspace.logo_url"
                            :alt="workspace.name"
                            class="h-20 w-20 rounded-xl object-cover shadow-lg"
                        />
                    </div>
                    <div v-else class="mb-1 text-4xl">🌾</div>
                    <h1 class="text-2xl font-bold">{{ brandName }}</h1>
                    <p class="text-sm text-slate-400">{{ brandTagline }}</p>
                </div>

                <p
                    v-if="workspaceError"
                    class="rounded-md border border-red-800 bg-red-950/40 p-3 text-sm text-red-300"
                >
                    {{ workspaceError }}
                </p>

                <!-- Already signed in: offer direct entry to this workspace -->
                <div v-if="alreadyInWorkspace" class="space-y-2">
                    <p class="text-sm text-emerald-300">
                        You are signed in to this workspace as
                        {{ auth.user?.name }}.
                    </p>
                    <button
                        type="button"
                        class="btn w-full"
                        @click="router.push('/dashboard')"
                    >
                        Go to dashboard
                    </button>
                </div>

                <div v-else-if="canSwitchToWorkspace" class="space-y-2">
                    <p class="text-sm text-slate-300">
                        You are signed in as
                        <strong>{{ auth.user?.name }}</strong
                        >.
                    </p>
                    <button
                        type="button"
                        class="btn w-full"
                        :disabled="busy"
                        @click="switchToWorkspace"
                    >
                        {{ busy ? "Switching…" : `Sign in as ${brandName}` }}
                    </button>
                </div>

                <div
                    v-else-if="noAccessToWorkspace"
                    class="rounded-md border border-amber-800 bg-amber-950/40 p-3 text-sm text-amber-300"
                >
                    You are signed in as
                    <strong>{{ auth.user?.name }}</strong> but are not a member
                    of this workspace.
                    <button
                        type="button"
                        class="mt-2 block w-full text-left text-xs underline"
                        @click="auth.logout()"
                    >
                        Sign out and try again
                    </button>
                </div>

                <template v-else>
                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-slate-400"
                            >Email</label
                        >
                        <input
                            v-model="email"
                            type="email"
                            required
                            class="input"
                            :placeholder="
                                workspace?.organization?.email ||
                                'you@organization.org'
                            "
                        />
                    </div>

                    <div>
                        <label
                            class="mb-1 block text-xs font-medium text-slate-400"
                            >Password</label
                        >
                        <input
                            v-model="password"
                            type="password"
                            required
                            class="input"
                            placeholder="••••••••"
                        />
                    </div>

                    <button
                        class="btn w-full"
                        :disabled="busy || loadingWorkspace"
                    >
                        {{ busy ? "Signing in…" : "Sign in" }}
                    </button>

                    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>

                    <!-- <p v-if="!workspaceSlug" class="text-center text-xs text-slate-500">
                        Demo: admin@example.org / password
                    </p> -->
                </template>
            </form>
        </div>
    </div>
</template>
