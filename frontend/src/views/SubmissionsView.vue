<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const canWrite = computed(() => auth.hasPermission('submissions.write'));

const forms = ref([]);
const rows = ref([]);
const formCounts = ref([]);
const total = ref(0);
const page = ref(1);
const perPage = 25;

const loading = ref(false);
const exportingId = ref('');
const error = ref(null);

const filters = reactive({
    form_id: '',
    from: '',
    to: '',
    q: '',
});

function queryParams(targetPage = page.value) {
    const params = { page: targetPage, per_page: perPage };

    if (filters.form_id) {
        params.form_id = filters.form_id;
    }
    if (filters.from) {
        params.from = filters.from;
    }
    if (filters.to) {
        params.to = filters.to;
    }
    if (filters.q) {
        params.q = filters.q;
    }

    return params;
}

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get('/submissions', { params: queryParams() });
        rows.value = response.data.data ?? [];
        formCounts.value = response.data.meta?.form_counts ?? [];
        total.value = response.data.meta?.total ?? 0;
    } catch (loadError) {
        error.value = loadError?.response?.data?.error ?? loadError?.message;
    } finally {
        loading.value = false;
    }
}

async function loadForms() {
    try {
        const response = await api.get('/forms');
        // Keep the full schema — the import panel uses it for the
        // template download and the column mapping preview, and the
        // results table uses field labels as column headers.
        forms.value = (response.data.data ?? []).map((form) => ({
            id: form.id,
            name: form.attributes.name,
            schema: form.attributes.schema,
        }));
    } catch {
        // Non-fatal: filters just have no form options.
    }
}

function formColumns(form, groupRows) {
    const columns = [];
    const seen = new Set();

    for (const field of form?.schema?.fields ?? []) {
        if (!field?.name || seen.has(field.name)) {
            continue;
        }

        seen.add(field.name);
        columns.push({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
        });
    }

    for (const row of groupRows) {
        for (const key of Object.keys(row.attributes?.payload ?? {})) {
            if (seen.has(key)) {
                continue;
            }

            seen.add(key);
            columns.push({ name: key, label: key, type: 'text' });
        }
    }

    return columns;
}

const tables = computed(() => {
    const groups = new Map();

    for (const row of rows.value) {
        const formId = String(row.attributes?.form_id ?? '');

        if (!groups.has(formId)) {
            groups.set(formId, []);
        }

        groups.get(formId).push(row);
    }

    const visible = [...groups.entries()].map(([formId, groupRows]) => {
        const form = forms.value.find((candidate) => String(candidate.id) === formId) ?? null;

        return {
            formId,
            name: form?.name ?? groupRows[0]?.attributes?.form_name ?? 'Form',
            columns: formColumns(form, groupRows),
            rows: groupRows,
        };
    });

    if (formCounts.value.length === 0) {
        return visible.map((table) => ({ ...table, count: table.rows.length }));
    }

    return formCounts.value.map((item) => {
        const formId = String(item.form_id);
        const existing = visible.find((table) => table.formId === formId);
        const form = forms.value.find((candidate) => String(candidate.id) === formId) ?? null;

        return {
            formId,
            name: item.name || form?.name || existing?.name || 'Form',
            count: item.count,
            columns: existing?.columns ?? formColumns(form, []),
            rows: existing?.rows ?? [],
        };
    });
});

function submissionCountLabel(count) {
    return count === 1 ? '1 submission' : `${count} submissions`;
}

const exportAllLabel = computed(() => {
    if (exportingId.value === 'all' || (filters.form_id && exportingId.value === String(filters.form_id))) {
        return 'Exporting…';
    }

    return filters.form_id ? '⬇ Export this form' : '⬇ Export all';
});

function displayValue(row, column) {
    if (column.type === 'photo') {
        const attached = row.attributes?.attachment_fields ?? [];

        if (attached.includes(column.name)) {
            return 'Photo';
        }
    }

    if (column.type === 'gps') {
        const lat = row.attributes?.gps?.lat;
        const lng = row.attributes?.gps?.lng;

        if (lat != null && lng != null) {
            return `${Number(lat).toFixed(4)}, ${Number(lng).toFixed(4)}`;
        }
    }

    const value = row.attributes?.payload?.[column.name];

    if (value == null || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length > 0 ? value.join(', ') : '—';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function formatSubmitted(row) {
    const created = row.attributes?.created_at;

    if (!created) {
        return '—';
    }

    return created.replace('T', ' ').slice(0, 16);
}

function applyFilters() {
    page.value = 1;
    load();
}

function goToPage(target) {
    if (target < 1 || target > totalPages.value) {
        return;
    }
    page.value = target;
    load();
}

const totalPages = ref(1);

// Keep pagination in sync after each load.
function refreshPagination() {
    totalPages.value = Math.max(1, Math.ceil(total.value / perPage));
}

/**
 * Stream a CSV export from the workspace-scoped export endpoint.
 */
async function exportCsv(formId = filters.form_id) {
    const targetId = formId ? String(formId) : '';
    exportingId.value = targetId || 'all';
    error.value = null;

    try {
        const params = { format: 'csv' };
        if (targetId) {
            params.form_id = targetId;
        }
        if (filters.from) {
            params.date_from = filters.from;
        }
        if (filters.to) {
            params.date_to = filters.to;
        }

        const response = await api.get(
            `/workspaces/${auth.activeAccount.id}/exports/submissions`,
            { params, responseType: 'blob' },
        );

        const form = forms.value.find((candidate) => String(candidate.id) === targetId);
        const formName = form?.name
            ?? tables.value.find((table) => table.formId === targetId)?.name
            ?? '';
        const slug = formName.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
        const date = new Date().toISOString().slice(0, 10);
        const filename = slug
            ? `${slug}_${date}.csv`
            : `submissions_${auth.activeAccount.slug}_${date}.csv`;

        const url = URL.createObjectURL(response.data);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    } catch (exportError) {
        error.value = `Export failed: ${exportError?.message}`;
    } finally {
        exportingId.value = '';
    }
}

// Re-run pagination calc whenever the total changes.
watch(total, refreshPagination);

// -- CSV import ------------------------------------------------------------

const importOpen = ref(false);
const importFormId = ref('');
const importFile = ref(null);
const importHeader = ref([]);
const importing = ref(false);
const importError = ref(null);
const importResult = ref(null);

const importForm = computed(
    () => forms.value.find((form) => String(form.id) === String(importFormId.value)) ?? null,
);

/** Naive-but-quoted CSV header parse (preview only; the server is authoritative). */
function parseHeaderLine(line) {
    const cells = [];
    let cell = '';
    let inQuotes = false;

    for (const char of line) {
        if (inQuotes) {
            if (char === '"') {
                inQuotes = false;
            } else {
                cell += char;
            }
        } else if (char === '"') {
            inQuotes = true;
        } else if (char === ',') {
            cells.push(cell.trim());
            cell = '';
        } else {
            cell += char;
        }
    }

    cells.push(cell.trim());

    return cells;
}

async function onImportFile(event) {
    importFile.value = event.target.files?.[0] ?? null;
    importHeader.value = [];
    importResult.value = null;

    if (!importFile.value) {
        return;
    }

    try {
        const head = await importFile.value.slice(0, 65536).text();
        const firstLine = head.split(/\r?\n/, 1)[0];
        importHeader.value = parseHeaderLine(firstLine.replace(/^\uFEFF/, ''));
    } catch {
        importHeader.value = [];
    }
}

const importPreview = computed(() => {
    if (!importForm.value || importHeader.value.length === 0) {
        return null;
    }

    const fields = importForm.value.schema?.fields ?? [];
    const reserved = ['local_uuid', 'created_at'];
    const matched = {};
    const matchedColumns = new Set();

    for (const field of fields) {
        const column = importHeader.value.find(
            (cell) =>
                cell &&
                !reserved.includes(cell) &&
                (cell.toLowerCase() === String(field.name ?? '').toLowerCase() ||
                    cell.toLowerCase() === String(field.label ?? '').toLowerCase()),
        );

        if (column) {
            matched[field.name] = column;
            matchedColumns.add(column);
        }
    }

    return {
        matched,
        missingRequired: fields.filter((field) => field.required && !matched[field.name])
            .map((field) => field.label || field.name),
        extraColumns: importHeader.value.filter(
            (cell) => cell && !matchedColumns.has(cell) && !reserved.includes(cell),
        ),
    };
});

function downloadTemplate() {
    const form = importForm.value;

    if (!form) {
        return;
    }

    const fields = form.schema?.fields ?? [];
    const header = ['local_uuid', ...fields.map((field) => field.name)].join(',');
    const blob = new Blob([header + '\n'], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `${form.name.toLowerCase().replace(/[^a-z0-9]+/g, '_')}_import_template.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

async function runImport() {
    importing.value = true;
    importError.value = null;
    importResult.value = null;

    try {
        const formData = new FormData();
        formData.append('form_id', importFormId.value);
        formData.append('csv', importFile.value);

        const response = await api.post(
            `/workspaces/${auth.activeAccount.id}/imports/submissions`,
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );

        importResult.value = response.data.data.attributes;
        importResult.value.error_rows = response.data.meta?.error_rows ?? [];
        load();
    } catch (importRequestError) {
        importError.value =
            importRequestError?.response?.data?.error ??
            importRequestError?.response?.data?.message ??
            importRequestError?.message;
    } finally {
        importing.value = false;
    }
}

onMounted(async () => {
    await loadForms();
    await load();
});
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold">Submissions</h1>
            <div class="flex gap-2">
                <button v-if="canWrite" class="btn-ghost" @click="importOpen = !importOpen; importResult = null">
                    ⬆ Import CSV
                </button>
                <button class="btn-ghost" :disabled="exportingId !== ''" @click="exportCsv()">
                    {{ exportAllLabel }}
                </button>
            </div>
        </div>

        <!-- Import panel -->
        <div v-if="importOpen && canWrite" class="card mb-4 space-y-3">
            <h2 class="text-sm font-semibold">Import CSV into a form</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs text-slate-400">Target form</label>
                    <select v-model="importFormId" class="input !w-56">
                        <option value="">Choose…</option>
                        <option v-for="form in forms" :key="form.id" :value="form.id">{{ form.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-400">CSV file</label>
                    <input type="file" accept=".csv,text/csv" class="max-w-64 text-xs" @change="onImportFile" />
                </div>
                <button v-if="importForm" class="btn-ghost text-xs" @click="downloadTemplate">
                    ⬇ Template
                </button>
                <button
                    class="btn"
                    :disabled="importing || !importFormId || !importFile"
                    @click="runImport"
                >
                    {{ importing ? 'Importing…' : 'Import' }}
                </button>
            </div>

            <p class="text-xs text-slate-500">
                Header row maps to field names or labels. Optional columns:
                <code class="font-mono">local_uuid</code> (idempotency key) and
                <code class="font-mono">created_at</code>. Re-importing the same file does not create copies.
            </p>

            <!-- Mapping preview -->
            <div v-if="importPreview" class="flex flex-wrap gap-2 text-xs">
                <span
                    v-for="(column, name) in importPreview.matched"
                    :key="name"
                    class="rounded-full bg-emerald-900/50 px-2 py-0.5 text-emerald-300"
                >
                    {{ name }} ← {{ column }}
                </span>
                <span
                    v-for="name in importPreview.missingRequired"
                    :key="name"
                    class="rounded-full bg-red-900/50 px-2 py-0.5 text-red-300"
                >
                    missing required: {{ name }}
                </span>
                <span
                    v-for="column in importPreview.extraColumns"
                    :key="column"
                    class="rounded-full bg-slate-800 px-2 py-0.5 text-slate-400"
                >
                    ignored: {{ column }}
                </span>
            </div>

            <p v-if="importError" class="text-sm text-red-400">{{ importError }}</p>

            <!-- Result summary -->
            <div v-if="importResult" class="rounded-md border border-slate-700 bg-slate-950/60 p-3">
                <p class="text-sm">
                    <span class="text-emerald-300">{{ importResult.imported }} imported</span>
                    <span v-if="importResult.duplicates > 0" class="text-amber-300">
                        · {{ importResult.duplicates }} duplicates skipped
                    </span>
                    <span v-if="importResult.errors > 0" class="text-red-300">
                        · {{ importResult.errors }} errors
                    </span>
                    <span class="text-slate-400"> ({{ importResult.total_rows }} rows → {{ importResult.form_name }})</span>
                </p>
                <ul v-if="importResult.error_rows?.length > 0" class="mt-2 space-y-1 text-xs text-red-300">
                    <li v-for="rowError in importResult.error_rows" :key="rowError.row">
                        Row {{ rowError.row }}: {{ rowError.error }}
                    </li>
                </ul>
            </div>
        </div>

        <div class="card mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs text-slate-400">Form</label>
                <select v-model="filters.form_id" class="input !w-48" @change="applyFilters">
                    <option value="">All forms</option>
                    <option v-for="form in forms" :key="form.id" :value="form.id">{{ form.name }}</option>
                </select>
            </div>
            <div>
                <label id="submissions-date-label" class="mb-1 block text-xs text-slate-400">Dates</label>
                <DateRangePicker
                    v-model:from="filters.from"
                    v-model:to="filters.to"
                    labelled-by="submissions-date-label"
                    @change="applyFilters"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-400">Search UUID</label>
                <input v-model="filters.q" class="input !w-44 font-mono" placeholder="local_uuid…" @change="applyFilters" />
            </div>
        </div>

        <p v-if="error" class="card mb-4 text-red-300">{{ error }}</p>
        <p v-else-if="loading" class="text-slate-400">Loading…</p>

        <div v-else-if="tables.length === 0" class="card overflow-x-auto !p-0">
            <p class="td text-slate-400">No submissions yet.</p>
        </div>

        <div v-else class="space-y-4">
            <div v-for="table in tables" :key="table.formId" class="card overflow-x-auto !p-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-3 py-2">
                    <p class="text-sm font-semibold">
                        {{ table.name }}
                        <span class="ml-2 font-normal text-slate-400">{{ submissionCountLabel(table.count) }}</span>
                    </p>
                    <button
                        class="btn-ghost text-xs"
                        :disabled="exportingId !== ''"
                        @click="exportCsv(table.formId)"
                    >
                        {{ exportingId === table.formId ? 'Exporting…' : '⬇ Export' }}
                    </button>
                </div>
                <p v-if="table.rows.length === 0" class="td text-slate-400">
                    No rows from this form on this page.
                </p>
                <table v-else class="w-full">
                    <thead>
                        <tr class="border-b border-slate-800">
                            <th v-for="column in table.columns" :key="column.name" class="th whitespace-nowrap">
                                {{ column.label }}
                            </th>
                            <th class="th whitespace-nowrap">By</th>
                            <th class="th whitespace-nowrap">Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in table.rows"
                            :key="row.id"
                            class="border-b border-slate-800/60 last:border-0"
                        >
                            <td
                                v-for="column in table.columns"
                                :key="column.name"
                                class="td max-w-64 truncate"
                                :title="displayValue(row, column)"
                            >
                                {{ displayValue(row, column) }}
                            </td>
                            <td class="td whitespace-nowrap">{{ row.attributes?.submitted_by ?? '—' }}</td>
                            <td class="td whitespace-nowrap text-xs text-slate-400">{{ formatSubmitted(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="totalPages > 1" class="mt-4 flex items-center gap-2 text-sm">
            <button class="btn-ghost" :disabled="page <= 1" @click="goToPage(page - 1)">← Prev</button>
            <span class="text-slate-400">
                Page {{ page }} of {{ totalPages }} ({{ total }} total)
            </span>
            <button class="btn-ghost" :disabled="page >= totalPages" @click="goToPage(page + 1)">Next →</button>
        </div>
    </div>
</template>
