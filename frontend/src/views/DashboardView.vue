<script setup>
import { Chart, registerables } from 'chart.js';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useThemeStore } from '@/stores/theme';

Chart.register(...registerables);

const auth = useAuthStore();
const theme = useThemeStore();

const summary = ref(null);
const error = ref(null);

const forms = ref([]);
const selectedFormId = ref('');
const formFields = ref([]);
const selectedField = ref('');
const fieldStats = ref(null);
const loadingStats = ref(false);

const byFormEl = ref(null);
const byDayEl = ref(null);
const statsEl = ref(null);
const charts = [];

async function load() {
    error.value = null;

    try {
        const response = await api.get(`/workspaces/${auth.activeAccount.id}/dashboard/summary`);
        summary.value = response.data.data.attributes;
        await nextTick();
        renderSummaryCharts();

        const formsResponse = await api.get('/forms', { params: { status: 'active' } });
        forms.value = (formsResponse.data.data ?? []).map((form) => ({
            id: form.id,
            name: form.attributes.name,
            fields: form.attributes.schema?.fields ?? [],
        }));
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    }
}

function chartTheme() {
    const light = theme.resolved === 'light';

    return {
        tick: light ? '#475569' : '#94a3b8',
        grid: light ? 'rgba(15, 23, 42, 0.08)' : 'rgba(148, 163, 184, 0.18)',
        line: light ? '#059669' : '#34d399',
        lineFill: light ? 'rgba(5, 150, 105, 0.12)' : 'rgba(52, 211, 153, 0.15)',
        stats: light ? '#2563eb' : '#60a5fa',
    };
}

function themedScale(options = {}) {
    const colors = chartTheme();

    return {
        ...options,
        ticks: { color: colors.tick, ...(options.ticks ?? {}) },
        grid: { color: colors.grid, ...(options.grid ?? {}) },
        border: { color: colors.grid, ...(options.border ?? {}) },
    };
}

function renderSummaryCharts() {
    charts.forEach((chart) => chart.destroy());
    charts.length = 0;

    if (!summary.value) {
        return;
    }

    const byForm = summary.value.by_form ?? [];

    if (byFormEl.value) {
        charts.push(
            new Chart(byFormEl.value, {
                type: 'bar',
                data: {
                    labels: byForm.map((row) => row.name),
                    datasets: [
                        {
                            label: 'Submissions',
                            data: byForm.map((row) => row.count),
                            backgroundColor: '#10b981',
                        },
                    ],
                },
                options: {
                    color: chartTheme().tick,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: themedScale(),
                        y: themedScale({ beginAtZero: true, ticks: { precision: 0 } }),
                    },
                },
            }),
        );
    }

    if (byDayEl.value) {
        const byDay = fillDayGaps(summary.value.by_day ?? []);

        charts.push(
            new Chart(byDayEl.value, {
                type: 'line',
                data: {
                    labels: byDay.map((row) => row.day.slice(5)),
                    datasets: [
                        {
                            label: 'Submissions / day',
                            data: byDay.map((row) => row.count),
                            borderColor: chartTheme().line,
                            backgroundColor: chartTheme().lineFill,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 0,
                        },
                    ],
                },
                options: {
                    color: chartTheme().tick,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: themedScale(),
                        y: themedScale({ beginAtZero: true, ticks: { precision: 0 } }),
                    },
                },
            }),
        );
    }
}

function fillDayGaps(rows) {
    const byDay = Object.fromEntries(rows.map((row) => [row.day, row.count]));
    const out = [];

    for (let i = 29; i >= 0; i--) {
        const date = new Date(Date.now() - i * 86_400_000);
        const day = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(
            date.getDate(),
        ).padStart(2, '0')}`;
        out.push({ day, count: byDay[day] ?? 0 });
    }

    return out;
}

function onFormChange() {
    selectedField.value = '';
    fieldStats.value = null;

    const form = forms.value.find((item) => item.id === Number(selectedFormId.value));
    formFields.value = form?.fields ?? [];
}

async function onFieldChange() {
    fieldStats.value = null;

    if (!selectedFormId.value || !selectedField.value) {
        return;
    }

    loadingStats.value = true;

    try {
        const response = await api.get(`/workspaces/${auth.activeAccount.id}/dashboard/field-stats`, {
            params: { form_id: selectedFormId.value, field: selectedField.value },
        });
        fieldStats.value = response.data.data.attributes;
        await nextTick();
        renderStatsChart();
    } finally {
        loadingStats.value = false;
    }
}

function renderStatsChart() {
    if (statsEl.value) {
        const existing = charts.find((chart) => chart.canvas === statsEl.value);
        if (existing) {
            existing.destroy();
        }
    }

    if (!fieldStats.value || fieldStats.value.kind !== 'categorical' || !statsEl.value) {
        return;
    }

    const values = fieldStats.value.values ?? [];

    charts.push(
        new Chart(statsEl.value, {
            type: 'bar',
            data: {
                labels: values.map((row) => row.value ?? '(blank)'),
                datasets: [
                    {
                        label: 'Count',
                        data: values.map((row) => row.count),
                        backgroundColor: chartTheme().stats,
                    },
                ],
            },
            options: {
                indexAxis: 'y',
                color: chartTheme().tick,
                plugins: { legend: { display: false } },
                scales: {
                    x: themedScale({ beginAtZero: true, ticks: { precision: 0 } }),
                    y: themedScale(),
                },
            },
        }),
    );
}

watch(
    () => theme.resolved,
    () => {
        renderSummaryCharts();
        renderStatsChart();
    },
);

onMounted(load);
onBeforeUnmount(() => {
    charts.forEach((chart) => chart.destroy());
});
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-bold">Dashboard</h1>

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>

        <div v-if="summary" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Total submissions</p>
                <p class="mt-1 text-2xl font-bold text-emerald-300">{{ summary.submissions.total }}</p>
            </div>
            <div class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Last 7 days</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.submissions.last_7_days }}</p>
            </div>
            <div class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Today</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.submissions.today }}</p>
            </div>
            <div class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Enumerators</p>
                <p class="mt-1 text-2xl font-bold">{{ summary.submissions.enumerators }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="card">
                <h2 class="mb-2 text-sm font-semibold">Submissions by form</h2>
                <canvas ref="byFormEl" height="200"></canvas>
            </div>
            <div class="card">
                <h2 class="mb-2 text-sm font-semibold">Submissions per day (30 days)</h2>
                <canvas ref="byDayEl" height="200"></canvas>
            </div>
        </div>

        <div class="card mt-4">
            <h2 class="mb-3 text-sm font-semibold">Field statistics</h2>

            <div class="flex flex-wrap items-center gap-2">
                <select v-model="selectedFormId" class="input !w-56" @change="onFormChange">
                    <option value="">Select a form…</option>
                    <option v-for="form in forms" :key="form.id" :value="form.id">{{ form.name }}</option>
                </select>

                <select v-model="selectedField" class="input !w-56" :disabled="!selectedFormId" @change="onFieldChange">
                    <option value="">Select a field…</option>
                    <option v-for="field in formFields" :key="field.name" :value="field.name">
                        {{ field.label }} ({{ field.type }})
                    </option>
                </select>

                <span v-if="loadingStats" class="text-xs text-amber-300">Loading…</span>
            </div>

            <div v-if="fieldStats" class="mt-4">
                <div
                    v-if="fieldStats.kind === 'numeric'"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                >
                    <div class="rounded-md bg-slate-950/60 p-3">
                        <p class="text-xs text-slate-400">Responses</p>
                        <p class="text-lg font-semibold">{{ fieldStats.count }}</p>
                    </div>
                    <div class="rounded-md bg-slate-950/60 p-3">
                        <p class="text-xs text-slate-400">Average</p>
                        <p class="text-lg font-semibold">{{ fieldStats.average?.toFixed(2) ?? '—' }}</p>
                    </div>
                    <div class="rounded-md bg-slate-950/60 p-3">
                        <p class="text-xs text-slate-400">Min</p>
                        <p class="text-lg font-semibold">{{ fieldStats.min ?? '—' }}</p>
                    </div>
                    <div class="rounded-md bg-slate-950/60 p-3">
                        <p class="text-xs text-slate-400">Max</p>
                        <p class="text-lg font-semibold">{{ fieldStats.max ?? '—' }}</p>
                    </div>
                </div>

                <canvas v-else ref="statsEl" height="160" class="mt-2 max-w-md"></canvas>
            </div>
        </div>
    </div>
</template>
