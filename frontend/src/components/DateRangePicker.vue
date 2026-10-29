<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

/**
 * Date-range filter. One control replaces a pair of native date inputs:
 * presets, a month grid, and a highlighted span. Values stay YYYY-MM-DD.
 */
const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    labelledBy: { type: String, default: '' },
});

const emit = defineEmits(['update:from', 'update:to', 'change']);

const hintId = useId();
const root = ref(null);
const trigger = ref(null);
const panel = ref(null);
const open = ref(false);
const placed = ref(false);
const picking = ref(null);
const hover = ref(null);
const focusDate = ref(startOfDay(new Date()));
const viewMonth = ref(startOfMonth(new Date()));
const panelStyle = ref({});
const compactQuery = window.matchMedia('(max-width: 767px)');
const compact = ref(compactQuery.matches);

const monthLabel = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
const fullDate = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const monthDay = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
const monthDayYear = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
const monthShort = new Intl.DateTimeFormat(undefined, { month: 'short' });
const weekdayShort = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
const weekdayLong = new Intl.DateTimeFormat(undefined, { weekday: 'long' });

const weekStart = weekStartsOn();
const earliest = new Date(1970, 0, 1);

const weekdays = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(2024, 0, 7 + ((weekStart + index) % 7));

    return {
        short: weekdayShort.format(date),
        long: weekdayLong.format(date),
    };
});

const presets = [
    { id: 'today', label: 'Today' },
    { id: 'yesterday', label: 'Yesterday' },
    { id: '7', label: 'Last 7 days' },
    { id: '30', label: 'Last 30 days' },
    { id: 'month', label: 'This month' },
    { id: 'last-month', label: 'Last month' },
    { id: 'all', label: 'All time' },
];

const hasRange = computed(() => Boolean(props.from || props.to));

const triggerLabel = computed(() => formatRange(props.from, props.to));

const shellClass = computed(() => {
    if (open.value) {
        return 'border-emerald-500';
    }

    if (hasRange.value) {
        return 'border-emerald-700';
    }

    return 'border-slate-700';
});

const visibleMonths = computed(() => {
    const left = startOfMonth(viewMonth.value);

    return compact.value ? [left] : [left, addMonths(left, 1)];
});

const maxLeft = computed(() => {
    const current = startOfMonth(new Date());

    return compact.value ? current : addMonths(current, -1);
});

const canGoPrev = computed(() => startOfMonth(viewMonth.value) > earliest);
const canGoNext = computed(() => startOfMonth(viewMonth.value) < maxLeft.value);

const bounds = computed(() => {
    if (picking.value) {
        return order(picking.value, hover.value ?? picking.value);
    }

    const start = parseIso(props.from);
    const end = parseIso(props.to);

    if (start && end) {
        return order(start, end);
    }

    if (start) {
        return [start, start];
    }

    if (end) {
        return [end, end];
    }

    return [null, null];
});

const footerText = computed(() => {
    if (picking.value) {
        return `From ${fullDate.format(picking.value)} · choose an end date`;
    }

    const start = parseIso(props.from);
    const end = parseIso(props.to);

    if (start && end) {
        const [first, last] = order(start, end);
        const count = inclusiveDays(first, last);
        const noun = count === 1 ? 'day' : 'days';

        return `${formatRange(toIso(first), toIso(last))} · ${count} ${noun}`;
    }

    if (start) {
        return `From ${fullDate.format(start)} onward`;
    }

    if (end) {
        return `Through ${fullDate.format(end)}`;
    }

    return 'Choose a start date, then an end date';
});

function weekStartsOn() {
    try {
        const locale = new Intl.Locale(navigator.language);
        const firstDay = locale.getWeekInfo?.().firstDay ?? locale.weekInfo?.firstDay;

        if (firstDay) {
            return firstDay === 7 ? 0 : firstDay;
        }
    } catch {
        // Locale week data is missing in some browsers.
    }

    return 1;
}

function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addDays(date, amount) {
    const next = startOfDay(date);
    next.setDate(next.getDate() + amount);

    return next;
}

function addMonths(monthDate, amount) {
    return new Date(monthDate.getFullYear(), monthDate.getMonth() + amount, 1);
}

function addMonthsClamped(date, amount) {
    const day = date.getDate();
    const next = new Date(date.getFullYear(), date.getMonth() + amount, 1);
    const lastDay = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate();
    next.setDate(Math.min(day, lastDay));

    return startOfDay(next);
}

function parseIso(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day);

    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
        return null;
    }

    return date;
}

function toIso(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function sameDay(left, right) {
    return Boolean(
        left &&
            right &&
            left.getFullYear() === right.getFullYear() &&
            left.getMonth() === right.getMonth() &&
            left.getDate() === right.getDate(),
    );
}

function order(left, right) {
    return startOfDay(left) <= startOfDay(right)
        ? [startOfDay(left), startOfDay(right)]
        : [startOfDay(right), startOfDay(left)];
}

function isFuture(date) {
    return startOfDay(date) > startOfDay(new Date());
}

function isToday(date) {
    return sameDay(date, new Date());
}

function isOutside(day, monthDate) {
    return day.getMonth() !== monthDate.getMonth() || day.getFullYear() !== monthDate.getFullYear();
}

function inclusiveDays(start, end) {
    const ms = Math.abs(startOfDay(end) - startOfDay(start));

    return Math.round(ms / 86_400_000) + 1;
}

function formatRange(from, to) {
    const start = parseIso(from);
    const end = parseIso(to);

    if (start && !end) {
        return `From ${fullDate.format(start)}`;
    }

    if (!start && end) {
        return `Through ${fullDate.format(end)}`;
    }

    if (!start) {
        return 'Any date';
    }

    if (!end || sameDay(start, end)) {
        return fullDate.format(start);
    }

    const [first, last] = order(start, end);

    if (first.getFullYear() === last.getFullYear() && first.getMonth() === last.getMonth()) {
        return `${monthShort.format(first)} ${first.getDate()}–${last.getDate()}, ${first.getFullYear()}`;
    }

    if (first.getFullYear() === last.getFullYear()) {
        return `${monthDay.format(first)} – ${monthDayYear.format(last)}`;
    }

    return `${fullDate.format(first)} – ${fullDate.format(last)}`;
}

function presetBounds(id) {
    const today = startOfDay(new Date());

    if (id === 'today') {
        return [today, today];
    }

    if (id === 'yesterday') {
        const yesterday = addDays(today, -1);

        return [yesterday, yesterday];
    }

    if (id === '7') {
        return [addDays(today, -6), today];
    }

    if (id === '30') {
        return [addDays(today, -29), today];
    }

    if (id === 'month') {
        return [new Date(today.getFullYear(), today.getMonth(), 1), today];
    }

    const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const end = new Date(today.getFullYear(), today.getMonth(), 0);

    return [start, end];
}

function presetIsActive(id) {
    if (picking.value) {
        return false;
    }

    if (id === 'all') {
        return !props.from && !props.to;
    }

    if (!props.from || !props.to) {
        return false;
    }

    const [start, end] = presetBounds(id);

    return toIso(start) === props.from && toIso(end) === props.to;
}

function segmentFor(day) {
    const [start, end] = bounds.value;

    if (!start || !end) {
        return null;
    }

    const time = startOfDay(day).getTime();
    const from = startOfDay(start).getTime();
    const to = startOfDay(end).getTime();

    if (time < from || time > to) {
        return null;
    }

    if (from === to) {
        return 'single';
    }

    if (time === from) {
        return 'start';
    }

    if (time === to) {
        return 'end';
    }

    return 'middle';
}

function rangeBarClass(day) {
    const segment = segmentFor(day);

    if (segment === 'middle') {
        return 'absolute inset-y-1.5 right-0 left-0 bg-emerald-400/25 sm:inset-y-1';
    }

    if (segment === 'start') {
        return 'absolute inset-y-1.5 right-0 left-1/2 bg-emerald-400/25 sm:inset-y-1';
    }

    if (segment === 'end') {
        return 'absolute inset-y-1.5 right-1/2 left-0 bg-emerald-400/25 sm:inset-y-1';
    }

    return '';
}

function dayClass(day) {
    const segment = segmentFor(day);
    const selected = segment === 'start' || segment === 'end' || segment === 'single';
    const classes = [];

    if (isFuture(day)) {
        classes.push('cursor-not-allowed text-slate-700');

        return classes;
    }

    classes.push('cursor-pointer');

    if (selected) {
        classes.push('bg-emerald-500 font-semibold text-white');
    } else {
        classes.push('text-slate-100 hover:bg-slate-800');
    }

    if (!selected && isToday(day)) {
        classes.push('ring-1 ring-emerald-400 ring-inset');
    }

    return classes;
}

function dayAria(day) {
    const label = fullDate.format(day);
    const segment = segmentFor(day);

    if (segment === 'start') {
        return `${label}, range start`;
    }

    if (segment === 'end') {
        return `${label}, range end`;
    }

    if (segment === 'single' || segment === 'middle') {
        return `${label}, selected`;
    }

    return label;
}

function weeksFor(monthDate) {
    const first = startOfMonth(monthDate);
    const offset = (first.getDay() - weekStart + 7) % 7;
    const gridStart = addDays(first, -offset);
    const days = Array.from({ length: 42 }, (_, index) => addDays(gridStart, index));
    const weeks = [];

    for (let index = 0; index < days.length; index += 7) {
        weeks.push(days.slice(index, index + 7));
    }

    return weeks;
}

function clampFocus(date) {
    const today = startOfDay(new Date());
    let next = startOfDay(date);

    if (next > today) {
        next = today;
    }

    if (next < earliest) {
        next = earliest;
    }

    return next;
}

function ensureVisible(date) {
    const target = startOfMonth(date);
    const lastShown = addMonths(viewMonth.value, compact.value ? 0 : 1);

    if (target < startOfMonth(viewMonth.value)) {
        viewMonth.value = target > maxLeft.value ? maxLeft.value : target;

        return;
    }

    if (target > lastShown) {
        const left = compact.value ? target : addMonths(target, -1);
        viewMonth.value = left > maxLeft.value ? maxLeft.value : left;
    }
}

function setFocus(date) {
    const next = clampFocus(date);
    focusDate.value = next;

    if (picking.value) {
        hover.value = next;
    }

    ensureVisible(next);
    nextTick(() => focusDay(next));
}

function focusDay(day) {
    panel.value?.querySelector(`[data-date="${toIso(day)}"]`)?.focus();
}

function initialViewMonth() {
    const anchor = parseIso(props.from) ?? parseIso(props.to) ?? new Date();
    let left = startOfMonth(anchor);

    if (left > maxLeft.value) {
        left = maxLeft.value;
    }

    if (left < earliest) {
        left = earliest;
    }

    return left;
}

function place() {
    const triggerRect = trigger.value?.getBoundingClientRect();

    if (!triggerRect) {
        return;
    }

    if (triggerRect.bottom < 0 || triggerRect.top > window.innerHeight) {
        closePanel(false);

        return;
    }

    const margin = 8;
    const width = Math.min(compact.value ? 352 : 672, window.innerWidth - margin * 2);
    let left = triggerRect.left;

    if (left + width > window.innerWidth - margin) {
        left = window.innerWidth - margin - width;
    }

    left = Math.max(margin, left);

    const panelHeight = panel.value?.offsetHeight ?? 420;
    const spaceBelow = window.innerHeight - triggerRect.bottom - margin;
    const spaceAbove = triggerRect.top - margin;
    const openAbove = spaceBelow < Math.min(panelHeight, 320) && spaceAbove > spaceBelow;
    const top = openAbove
        ? Math.max(margin, triggerRect.top - margin - Math.min(panelHeight, window.innerHeight - margin * 2))
        : triggerRect.bottom + margin;

    panelStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
        width: `${width}px`,
    };
    placed.value = true;
}

async function openPanel() {
    picking.value = null;
    hover.value = null;
    viewMonth.value = initialViewMonth();
    focusDate.value = clampFocus(parseIso(props.from) ?? parseIso(props.to) ?? new Date());
    ensureVisible(focusDate.value);
    placed.value = false;
    open.value = true;
    await nextTick();
    place();
    await nextTick();

    if (open.value) {
        focusDay(focusDate.value);
    }
}

function closePanel(restoreFocus) {
    const wasOpen = open.value;
    picking.value = null;
    hover.value = null;
    open.value = false;
    placed.value = false;

    if (restoreFocus && wasOpen) {
        nextTick(() => trigger.value?.focus());
    }
}

function toggle() {
    if (open.value) {
        closePanel(true);

        return;
    }

    openPanel();
}

function publish(from, to) {
    const changed = props.from !== from || props.to !== to;
    picking.value = null;
    hover.value = null;

    if (changed) {
        emit('update:from', from);
        emit('update:to', to);
        emit('change');
    }

    closePanel(true);
}

function commit(start, end) {
    const [first, last] = order(start, end);
    publish(toIso(first), toIso(last));
}

function commitOpenStart() {
    if (!picking.value) {
        return;
    }

    publish(toIso(picking.value), '');
}

function commitOpenEnd() {
    if (!picking.value) {
        return;
    }

    publish('', toIso(picking.value));
}

function clearRange() {
    publish('', '');
}

function applyPreset(id) {
    if (id === 'all') {
        clearRange();

        return;
    }

    const [start, end] = presetBounds(id);
    commit(start, end);
}

function onDayFocus(day) {
    const next = startOfDay(day);

    if (!sameDay(focusDate.value, next)) {
        focusDate.value = next;
    }
}

function selectDay(day) {
    if (isFuture(day) || startOfDay(day) < earliest) {
        return;
    }

    const chosen = startOfDay(day);
    focusDate.value = chosen;

    if (!picking.value) {
        picking.value = chosen;
        hover.value = null;
        ensureVisible(chosen);

        return;
    }

    commit(picking.value, chosen);
}

function onHover(day) {
    if (!picking.value || isFuture(day)) {
        return;
    }

    hover.value = startOfDay(day);
}

function onGridLeave() {
    hover.value = null;
}

function shiftMonth(amount) {
    const next = addMonths(viewMonth.value, amount);

    if (next < earliest || next > maxLeft.value) {
        return;
    }

    viewMonth.value = next;
}

function onDayKeydown(event) {
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        setFocus(addDays(focusDate.value, -1));
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        setFocus(addDays(focusDate.value, 1));
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        setFocus(addDays(focusDate.value, -7));
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        setFocus(addDays(focusDate.value, 7));
    } else if (event.key === 'Home') {
        event.preventDefault();
        const offset = (focusDate.value.getDay() - weekStart + 7) % 7;
        setFocus(addDays(focusDate.value, -offset));
    } else if (event.key === 'End') {
        event.preventDefault();
        const offset = (focusDate.value.getDay() - weekStart + 7) % 7;
        setFocus(addDays(focusDate.value, 6 - offset));
    } else if (event.key === 'PageUp') {
        event.preventDefault();
        setFocus(addMonthsClamped(focusDate.value, event.shiftKey ? -12 : -1));
    } else if (event.key === 'PageDown') {
        event.preventDefault();
        setFocus(addMonthsClamped(focusDate.value, event.shiftKey ? 12 : 1));
    }
}

function onClickOutside(event) {
    if (!open.value) {
        return;
    }

    const target = event.target;

    if (root.value?.contains(target) || panel.value?.contains(target)) {
        return;
    }

    closePanel(false);
}

function onDocumentKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        closePanel(true);
    }
}

function onScroll(event) {
    const target = event.target;

    if (target instanceof Node && (target === panel.value || panel.value?.contains(target))) {
        return;
    }

    place();
}

function syncCompact() {
    compact.value = compactQuery.matches;
}

watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('resize', place);
        window.addEventListener('scroll', onScroll, true);
    } else {
        window.removeEventListener('resize', place);
        window.removeEventListener('scroll', onScroll, true);
    }
});

watch(compact, () => {
    if (!open.value) {
        return;
    }

    ensureVisible(picking.value ?? focusDate.value);

    if (startOfMonth(viewMonth.value) > maxLeft.value) {
        viewMonth.value = maxLeft.value;
    }

    nextTick(place);
});

onMounted(() => {
    document.addEventListener('click', onClickOutside);
    document.addEventListener('keydown', onDocumentKeydown);
    compactQuery.addEventListener('change', syncCompact);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onDocumentKeydown);
    compactQuery.removeEventListener('change', syncCompact);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', onScroll, true);
});
</script>

<template>
    <div ref="root" class="w-full min-w-0 sm:w-auto">
        <div
            class="flex min-w-0 items-center rounded-md border bg-slate-900 text-sm focus-within:border-emerald-500 sm:min-w-66"
            :class="shellClass"
        >
            <button
                ref="trigger"
                type="button"
                class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-3 py-2 text-left outline-none"
                :aria-labelledby="labelledBy || undefined"
                :aria-label="labelledBy ? undefined : 'Date range'"
                aria-haspopup="dialog"
                :aria-expanded="open"
                :title="triggerLabel"
                @click="toggle"
            >
                <svg viewBox="0 0 20 20" class="h-4 w-4 shrink-0 text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="2.75" y="4" width="14.5" height="13" rx="2" />
                    <path d="M2.75 8h14.5M6.5 2.5v3M13.5 2.5v3" stroke-linecap="round" />
                </svg>
                <span class="truncate" :class="hasRange ? 'font-medium text-slate-100' : 'text-slate-500'">
                    {{ triggerLabel }}
                </span>
                <svg
                    viewBox="0 0 20 20"
                    class="ml-auto h-4 w-4 shrink-0 text-slate-500 transition"
                    :class="open ? 'rotate-180' : ''"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </button>
            <button
                v-if="hasRange"
                type="button"
                class="mr-1 flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded text-slate-500 outline-none hover:bg-slate-800 hover:text-slate-200"
                aria-label="Clear date range"
                @click="clearRange"
            >
                <svg viewBox="0 0 20 20" class="h-4 w-4" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                </svg>
            </button>
        </div>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                role="dialog"
                aria-label="Choose dates"
                :aria-describedby="hintId"
                class="fixed z-40 max-h-[min(34rem,calc(100vh-1rem))] overflow-y-auto overscroll-contain rounded-xl border border-slate-700 bg-slate-900 p-3 text-slate-100 shadow-2xl shadow-black/50"
                :class="placed ? '' : 'invisible'"
                :style="panelStyle"
            >
                <p :id="hintId" class="sr-only">
                    Choose a start date, then an end date. Arrow keys move between days. Escape closes the calendar.
                </p>

                <div :class="compact ? 'flex flex-col gap-3' : 'flex gap-3'">
                    <div
                        class="flex gap-1"
                        :class="compact ? 'flex-wrap' : 'w-36 shrink-0 flex-col border-r border-slate-800 pr-3'"
                        role="group"
                        aria-label="Date presets"
                    >
                        <button
                            v-for="preset in presets"
                            :key="preset.id"
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1.5 text-left text-xs transition"
                            :class="[
                                compact ? 'shrink-0 whitespace-nowrap' : '',
                                presetIsActive(preset.id)
                                    ? 'bg-emerald-500/15 font-medium text-emerald-200'
                                    : 'text-slate-300 hover:bg-slate-800 hover:text-slate-100',
                            ]"
                            :aria-pressed="presetIsActive(preset.id)"
                            @click="applyPreset(preset.id)"
                        >
                            {{ preset.label }}
                        </button>
                    </div>

                    <div class="min-w-0 flex-1" @mouseleave="onGridLeave">
                        <div :class="compact ? 'space-y-1' : 'grid grid-cols-2 gap-x-5'">
                            <section v-for="(month, monthIndex) in visibleMonths" :key="toIso(month)">
                                <div class="mb-2 grid grid-cols-[2.25rem_1fr_2.25rem] items-center">
                                    <button
                                        v-if="monthIndex === 0"
                                        type="button"
                                        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md text-slate-300 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                                        aria-label="Previous month"
                                        :disabled="!canGoPrev"
                                        @click="shiftMonth(-1)"
                                    >
                                        <svg viewBox="0 0 20 20" class="h-4 w-4" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                    <span v-else aria-hidden="true" />

                                    <h3 class="text-center text-sm font-semibold">{{ monthLabel.format(month) }}</h3>

                                    <button
                                        v-if="monthIndex === visibleMonths.length - 1"
                                        type="button"
                                        class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md text-slate-300 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                                        aria-label="Next month"
                                        :disabled="!canGoNext"
                                        @click="shiftMonth(1)"
                                    >
                                        <svg viewBox="0 0 20 20" class="h-4 w-4" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                    <span v-else aria-hidden="true" />
                                </div>

                                <div role="grid" :aria-label="monthLabel.format(month)">
                                    <div role="row" class="mb-1 grid grid-cols-7">
                                        <div
                                            v-for="(weekday, weekdayIndex) in weekdays"
                                            :key="weekday.long + weekdayIndex"
                                            role="columnheader"
                                            class="truncate text-center text-[11px] font-medium tracking-wide text-slate-500 uppercase"
                                            :title="weekday.long"
                                        >
                                            {{ weekday.short }}
                                        </div>
                                    </div>

                                    <div v-for="(week, weekIndex) in weeksFor(month)" :key="weekIndex" role="row" class="grid grid-cols-7">
                                        <div
                                            v-for="day in week"
                                            :key="toIso(day)"
                                            role="gridcell"
                                            class="relative h-11 sm:h-9"
                                            :aria-hidden="isOutside(day, month) ? 'true' : undefined"
                                        >
                                            <template v-if="!isOutside(day, month)">
                                                <div v-if="rangeBarClass(day)" :class="rangeBarClass(day)" />
                                                <button
                                                    type="button"
                                                    class="relative z-10 m-auto flex h-10 w-10 max-w-full items-center justify-center rounded-full text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-400 sm:h-8 sm:w-8"
                                                    :class="dayClass(day)"
                                                    :data-date="toIso(day)"
                                                    :tabindex="sameDay(day, focusDate) ? 0 : -1"
                                                    :disabled="isFuture(day)"
                                                    :aria-selected="segmentFor(day) != null"
                                                    :aria-label="dayAria(day)"
                                                    @click="selectDay(day)"
                                                    @mouseenter="onHover(day)"
                                                    @focus="onDayFocus(day)"
                                                    @keydown="onDayKeydown"
                                                >
                                                    {{ day.getDate() }}
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 pt-3">
                            <p class="text-xs text-slate-400" aria-live="polite">{{ footerText }}</p>
                            <div v-if="picking" class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:border-emerald-600 hover:text-emerald-200"
                                    @click="commitOpenStart"
                                >
                                    From this day on
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:border-emerald-600 hover:text-emerald-200"
                                    @click="commitOpenEnd"
                                >
                                    Up to this day
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
