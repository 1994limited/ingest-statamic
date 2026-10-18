<!--
    The feed as Ingest reads it: the format, how many items, and one item
    at a time. Click a value to use its path in the field you're editing.
-->
<script>
import { Alert, Badge, Button, Input } from '@statamic/cms/ui';

function flatten(value, prefix = '', out = [], depth = 0) {
    if (value === null || typeof value !== 'object' || depth > 8) {
        out.push({ path: prefix, value });
        return out;
    }

    if (Array.isArray(value)) {
        if (!value.length) out.push({ path: prefix, value: [] });
        value.forEach((v, i) => flatten(v, prefix === '' ? String(i) : `${prefix}.${i}`, out, depth + 1));
        return out;
    }

    const keys = Object.keys(value);
    if (!keys.length) out.push({ path: prefix, value: {} });
    keys.forEach((k) => flatten(value[k], prefix === '' ? k : `${prefix}.${k}`, out, depth + 1));

    return out;
}

export default {
    components: { Alert, Badge, Button, Input },

    inject: { picker: { default: null } },

    props: {
        preview: { type: Object, default: null },
        loading: { type: Boolean, default: false },
        error: { type: String, default: null },
        index: { type: Number, default: 0 },
    },

    emits: ['update:index', 'refresh'],

    data() {
        return { filter: '' };
    },

    computed: {
        item() {
            return this.preview?.items?.[this.index] ?? null;
        },

        rows() {
            if (!this.item) return [];

            const q = this.filter.trim().toLowerCase();

            return flatten(this.item).filter((r) => !q || r.path.toLowerCase().includes(q) || String(r.value ?? '').toLowerCase().includes(q));
        },

        mapped() {
            return this.preview?.mapped?.[this.index] ?? null;
        },
    },

    methods: {
        show(value) {
            if (value === null) return 'null';
            if (Array.isArray(value)) return '[]';
            if (typeof value === 'object') return '{}';
            const text = String(value);
            return text.length > 120 ? text.slice(0, 120) + '…' : text;
        },

        use(path) {
            if (!this.picker?.set) return;

            let p = path;
            // Inside rows, paths are relative to the row: formats.0.sku → sku.
            if (this.picker.scope) {
                const base = this.picker.scope.replace(/\.\*$/, '');
                const match = p.match(new RegExp('^' + base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\.\\d+\\.(.+)$'));
                if (match) p = match[1];
            }

            this.picker.set(p);
        },
    },
};
</script>

<template>
    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700! dark:bg-gray-900!">
        <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700!">
            <span class="font-medium">{{ __('Feed preview') }}</span>
            <Badge v-if="preview?.format" :text="preview.format.toUpperCase()" color="gray" size="sm" />
            <span v-if="preview" class="text-sm text-gray-600 dark:text-gray-400!">{{ preview.count === 1 ? __('1 item in the first page') : __(':count items in the first page', { count: preview.count }) }}</span>
            <Button class="ml-auto" size="sm" variant="ghost" icon="refresh" :loading="loading" :text="__('Refresh')" @click="$emit('refresh')" />
        </div>

        <div v-if="error" class="p-3">
            <Alert variant="error" :text="error" />
        </div>

        <div v-else-if="!preview" class="p-6 text-center text-sm text-gray-600 dark:text-gray-400!">
            {{ loading ? __('Reading the feed…') : __('Set the source to see the feed here.') }}
        </div>

        <template v-else>
            <div v-for="warning in preview.warnings ?? []" :key="warning" class="px-3 pt-3"><Alert variant="warning" :text="warning" /></div>

            <div v-if="!preview.items.length" class="p-6 text-center text-sm text-gray-600 dark:text-gray-400!">
                {{ __('No items at this item path. Pick one from the structure below.') }}
            </div>

            <template v-else>
                <div class="flex items-center gap-2 px-3 py-2">
                    <Button size="sm" icon="chevron-left" :disabled="index === 0" :aria-label="__('Previous item')" @click="$emit('update:index', index - 1)" />
                    <span class="text-sm tabular-nums">{{ __('Item :n of :total', { n: index + 1, total: preview.items.length }) }}</span>
                    <Button size="sm" icon="chevron-right" :disabled="index >= preview.items.length - 1" :aria-label="__('Next item')" @click="$emit('update:index', index + 1)" />
                    <div class="ml-auto w-40"><Input v-model="filter" size="sm" :placeholder="__('Filter')" clearable /></div>
                </div>

                <p v-if="picker?.target" class="px-3 pb-1 text-xs text-blue-700 dark:text-blue-400!">{{ __('Click a value to use its path.') }}</p>

                <div class="max-h-[60vh] overflow-y-auto px-1 pb-2">
                    <button
                        v-for="row in rows"
                        :key="row.path"
                        type="button"
                        class="flex w-full items-baseline gap-2 rounded px-2 py-1 text-left text-sm hover:bg-blue-50 dark:hover:bg-gray-800!"
                        :title="__('Use :path', { path: row.path })"
                        @click="use(row.path)"
                    >
                        <span class="shrink-0 font-mono text-xs text-gray-500">{{ row.path }}</span>
                        <span class="min-w-0 truncate">{{ show(row.value) }}</span>
                    </button>
                </div>

                <div v-if="mapped && Object.keys(mapped.errors ?? {}).length" class="border-t border-gray-200 px-3 py-2 dark:border-gray-700!">
                    <p class="text-sm font-medium text-red-700 dark:text-red-400!">{{ __('Mapping problems for this item') }}</p>
                    <ul class="mt-1 list-disc pl-5 text-sm">
                        <li v-for="(message, field) in mapped.errors" :key="field"><span class="font-mono">{{ field }}</span>: {{ message }}</li>
                    </ul>
                </div>
                <div v-if="mapped?.warnings?.length" class="border-t border-gray-200 px-3 py-2 dark:border-gray-700!">
                    <ul class="list-disc pl-5 text-sm text-amber-700 dark:text-amber-400!">
                        <li v-for="w in mapped.warnings" :key="w">{{ w }}</li>
                    </ul>
                </div>
            </template>

            <details v-if="preview.tree?.length" class="border-t border-gray-200 px-3 py-2 dark:border-gray-700!">
                <summary class="cursor-pointer text-sm font-medium">{{ __('Structure') }}</summary>
                <slot name="tree" :tree="preview.tree" />
            </details>
        </template>
    </div>
</template>
