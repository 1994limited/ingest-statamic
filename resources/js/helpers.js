// Small helpers shared by Ingest's Control Panel pages.

export const statusColours = {
    queued: 'gray',
    fetching: 'blue',
    processing: 'blue',
    finalising: 'blue',
    cancelling: 'amber',
    completed: 'green',
    completed_with_errors: 'amber',
    aborted: 'red',
    failed: 'red',
    cancelled: 'gray',
    undone: 'gray',
};

export const actionColours = {
    created: 'green',
    updated: 'blue',
    unchanged: 'gray',
    skipped: 'gray',
    failed: 'red',
    conflict: 'amber',
    unpublished: 'amber',
    deleted: 'red',
};

export function ago(iso) {
    if (!iso) return '';

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const future = seconds < 0;
    const s = Math.abs(seconds);
    const [value, unit] =
        s < 60 ? [s, 'second'] : s < 3600 ? [Math.round(s / 60), 'minute'] : s < 86400 ? [Math.round(s / 3600), 'hour'] : [Math.round(s / 86400), 'day'];

    return new Intl.RelativeTimeFormat(document.documentElement.lang || 'en', { numeric: 'auto' }).format(future ? value : -value, unit);
}

export function when(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function duration(seconds) {
    if (seconds === null || seconds === undefined) return '';
    if (seconds < 60) return __(':s s', { s: seconds });

    return __(':m min :s s', { m: Math.floor(seconds / 60), s: seconds % 60 });
}

/** "12 created · 40 updated", skipping zeros. */
export function summary(counts) {
    const labels = {
        created: __('created'),
        updated: __('updated'),
        unpublished: __('unpublished'),
        deleted: __('deleted'),
        failed: __('failed'),
        conflicts: __('kept local edits'),
        unchanged: __('unchanged'),
    };

    const parts = Object.entries(labels)
        .filter(([key]) => (counts?.[key] ?? 0) > 0)
        .map(([key, label]) => `${counts[key]} ${label}`);

    return parts.length ? parts.join(' · ') : __('No changes');
}

/** A request's error message, from Laravel's JSON. */
export function errorMessage(error) {
    const data = error?.response?.data;

    if (data?.errors) {
        return Object.values(data.errors).flat()[0];
    }

    return data?.message || error?.message || __('Something went wrong.');
}

export function toast(type, message) {
    if (message && typeof Statamic?.$toast?.[type] === 'function') {
        Statamic.$toast[type](message);
    }
}

/** Deep-clone plain data. */
export const clone = (value) => JSON.parse(JSON.stringify(value ?? null));
