<!--
    One key of a feed item in the preview: a value, or an object or list
    that opens and closes. Clicking a name or value uses its path.
-->
<script>
export default {
    name: 'PreviewNode',

    inject: { tree: { default: null } },

    props: {
        name: { type: String, required: true },
        value: { default: null },
        path: { type: String, required: true },
        depth: { type: Number, default: 0 },
    },

    emits: ['use'],

    data() {
        return { localOpen: null, openedAt: null };
    },

    computed: {
        isList() {
            return Array.isArray(this.value);
        },

        isObject() {
            return this.value !== null && typeof this.value === 'object' && !this.isList;
        },

        branch() {
            return this.isList || this.isObject;
        },

        children() {
            if (this.isList) return this.value.map((v, i) => ({ name: String(i), value: v, path: `${this.path}.${i}` }));
            if (this.isObject) return Object.entries(this.value).map(([k, v]) => ({ name: k, value: v, path: this.path ? `${this.path}.${k}` : k }));
            return [];
        },

        size() {
            return this.isList ? this.value.length : Object.keys(this.value ?? {}).length;
        },

        /** A collapsed object's first bit of text, so "0 {4}" says what it is. */
        hint() {
            if (!this.isObject || this.open) return null;
            const first = Object.values(this.value).find((v) => typeof v === 'string' && v.trim() !== '' && v.length < 200);
            return first ? (first.length > 40 ? first.slice(0, 40) + '…' : first) : null;
        },

        sizeLabel() {
            return this.isList ? '[' + this.size + ']' : '{' + this.size + '}';
        },

        matches() {
            return this.tree?.matches(this.path, this.value) ?? true;
        },

        open() {
            // A filter opens every branch with a match inside.
            if (this.tree?.filtering) return true;
            if (this.localOpen !== null && this.tree?.version === this.openedAt) return this.localOpen;

            return this.tree?.all ?? this.depth < 1;
        },

        display() {
            const v = this.value;
            if (v === null) return 'null';
            if (v === '') return '""';
            const text = String(v);
            return text.length > 120 ? text.slice(0, 120) + '…' : text;
        },
    },

    methods: {
        toggle() {
            this.localOpen = !this.open;
            this.openedAt = this.tree?.version;
        },
    },
};
</script>

<template>
    <div v-if="matches" class="text-sm">
        <div class="group flex items-baseline gap-1 rounded py-0.5 pr-2 hover:bg-blue-50 dark:hover:bg-gray-800!" :style="{ paddingLeft: `${depth * 0.875 + 0.25}rem` }">
            <button
                v-if="branch"
                type="button"
                class="w-4 shrink-0 text-center text-xs text-gray-500"
                :aria-expanded="open"
                :aria-label="open ? __('Collapse :name', { name }) : __('Expand :name', { name })"
                @click="toggle"
            >
                {{ open ? '▾' : '▸' }}
            </button>
            <span v-else class="w-4 shrink-0" aria-hidden="true" />

            <button type="button" class="shrink-0 font-mono text-xs text-gray-600 hover:underline! dark:text-gray-400!" :title="__('Use :path', { path })" @click="$emit('use', path)">
                {{ name }}
            </button>

            <template v-if="branch">
                <button type="button" class="text-xs text-gray-400" :aria-hidden="true" tabindex="-1" @click="toggle">{{ sizeLabel }}</button>
                <span v-if="hint" class="min-w-0 truncate text-xs text-gray-500">{{ hint }}</span>
                <button
                    v-if="isList && size"
                    type="button"
                    class="ml-1 rounded bg-gray-100 px-1.5 text-xs text-gray-600 opacity-0 group-focus-within:opacity-100! group-hover:opacity-100! focus-visible:opacity-100! dark:bg-gray-700! dark:text-gray-300!"
                    :title="__('Use :path (every item)', { path: path + '.*' })"
                    @click="$emit('use', path + '.*')"
                >
                    {{ __('each') }}
                </button>
            </template>
            <button v-else type="button" class="min-w-0 truncate text-left" :title="__('Use :path', { path })" @click="$emit('use', path)">{{ display }}</button>
        </div>

        <div v-if="branch && open">
            <PreviewNode v-for="child in children" :key="child.path" :name="child.name" :value="child.value" :path="child.path" :depth="depth + 1" @use="$emit('use', $event)" />
        </div>
    </div>
</template>
