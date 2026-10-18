<!--
    How one field is filled: from a path in the feed, a fixed value or a
    template; then transforms, what to do when the feed has nothing, and
    settings for the kind of field (options, relationships, images, rows,
    variants). Grids, groups, replicator sets and Cargo variants map their
    own fields with this same component.
-->
<script>
import Toggle from './Toggle.vue';
import { Badge, Button, Input, Select, Switch, Textarea } from '@statamic/cms/ui';
import PathInput from './PathInput.vue';
import Rows from './Rows.vue';
import TransformsEditor from './TransformsEditor.vue';

export default {
    name: 'FieldMapping',

    components: { Toggle, Badge, Button, Input, PathInput, Rows, Select, Switch, Textarea, TransformsEditor },

    inject: { options: { default: () => ({}) } },

    props: {
        field: { type: Object, required: true },
        modelValue: { type: Object, default: null },
        preview: { default: undefined },
        display: { type: String, default: undefined },
        error: { type: String, default: null },
        scope: { type: String, default: null },
        depth: { type: Number, default: 0 },
        open: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return { expanded: this.open, showTransforms: false };
    },

    computed: {
        m() {
            return this.modelValue ?? {};
        },

        mode() {
            if (!this.modelValue) return 'none';
            if ('template' in this.m && this.m.template !== undefined) return 'template';
            if ('value' in this.m) return 'value';
            if (this.field.kind === 'group' && !this.m.path) return 'fields';
            return 'path';
        },

        modes() {
            const modes = [{ value: 'none', label: __('Not mapped') }, { value: 'path', label: __('From the feed') }];

            if (this.field.kind === 'group') modes.push({ value: 'fields', label: __('Field by field') });
            if (!['rows', 'group', 'variants'].includes(this.field.kind)) {
                modes.push({ value: 'value', label: __('A fixed value') }, { value: 'template', label: __('A template') });
            }

            return modes;
        },

        mapped() {
            return this.mode !== 'none';
        },

        summary() {
            switch (this.mode) {
                case 'path':
                    return this.m.path ? this.m.path : __('Choose a path');
                case 'value':
                    return __('Always ":value"', { value: String(this.m.value ?? '') });
                case 'template':
                    return this.m.template;
                case 'fields':
                    return __('Field by field');
                default:
                    return null;
            }
        },

        previewText() {
            if (this.display !== undefined && this.display !== null) return this.display;
            const v = this.preview;
            if (v === undefined) return null;
            if (v === null || v === '') return __('(empty)');
            if (typeof v === 'boolean') return v ? __('Yes') : __('No');
            if (Array.isArray(v) && v.length && v[0]?.type && ['paragraph', 'heading'].includes(v[0].type)) {
                return JSON.stringify(v).match(/"text":"([^"]*)"/g)?.map((t) => t.slice(8, -1)).join(' ').slice(0, 160) ?? '';
            }
            const text = typeof v === 'object' ? JSON.stringify(v) : String(v);

            return text.length > 160 ? text.slice(0, 160) + '…' : text;
        },

        transformCount() {
            return (this.m.transforms ?? []).length;
        },

        sets() {
            return (this.field.sets ?? []).map((s) => ({ value: s.handle, label: s.display }));
        },

        rowFields() {
            if (this.field.type === 'replicator') {
                const set = this.field.sets?.find((s) => s.handle === this.m.set);
                return set?.fields ?? [];
            }

            return this.field.fields ?? [];
        },

        rowScope() {
            // Paths inside rows are relative to each row.
            return this.m.path || null;
        },

        optionChoices() {
            return this.field.options ?? [];
        },

        valueRows: {
            get() {
                const values = this.m.values ?? [];
                return Array.isArray(values) ? values : Object.entries(values).map(([from, to]) => ({ from, to }));
            },
            set(rows) {
                this.set('values', rows.filter((r) => r.from !== ''));
            },
        },

        dimensionRows: {
            get() {
                return this.m.dimensions ?? [];
            },
            set(rows) {
                this.set('dimensions', rows);
            },
        },
    },

    methods: {
        emit(next) {
            this.$emit('update:modelValue', next);
        },

        set(key, value) {
            const next = { ...this.m };
            if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length && key !== 'path')) {
                delete next[key];
            } else {
                next[key] = value;
            }
            this.emit(next);
        },

        setMode(mode) {
            const keep = { ...this.m };
            delete keep.path;
            delete keep.value;
            delete keep.template;

            if (mode === 'none') return this.emit(null);
            if (mode === 'path') return this.emit({ ...keep, path: this.m.path ?? '' });
            if (mode === 'value') return this.emit({ ...keep, value: this.field.kind === 'toggle' ? true : '' });
            if (mode === 'template') return this.emit({ ...keep, template: '' });
            if (mode === 'fields') return this.emit({ ...keep, fields: this.m.fields ?? {} });
        },

        setChild(handle, value) {
            const fields = { ...(this.m.fields ?? {}) };
            if (value === null) delete fields[handle];
            else fields[handle] = value;
            this.set('fields', fields);
        },

        setSub(key, sub, value) {
            this.set(key, { ...(this.m[key] ?? {}), [sub]: value });
        },

        childPreview(handle) {
            if (this.preview === undefined) return undefined;
            const value = Array.isArray(this.preview) ? this.preview[0] : this.preview;
            return value && typeof value === 'object' ? value[handle] : undefined;
        },

        list(value) {
            return (value ?? []).join(', ');
        },

        unlist(value) {
            return value.split(',').map((v) => v.trim()).filter(Boolean);
        },
    },
};
</script>

<template>
    <div class="rounded-lg border border-gray-200 dark:border-gray-700!" :class="{ 'bg-white dark:bg-gray-900!': depth === 0, 'border-red-300! dark:border-red-700!': error }">
        <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left" :aria-expanded="expanded" @click="expanded = !expanded">
            <span class="min-w-0 grow">
                <span class="font-medium">{{ field.display }}</span>
                <span v-if="field.required" class="text-red-500"> *</span>
                <span class="ml-2 font-mono text-xs text-gray-500">{{ field.handle }}</span>
                <span class="mt-0.5 block truncate text-sm" :class="mapped ? 'font-mono text-gray-700 dark:text-gray-300!' : 'text-gray-400'">
                    {{ mapped ? summary : __('Not mapped') }}
                    <span v-if="transformCount" class="font-sans text-xs text-gray-500"> · {{ __(':n transforms', { n: transformCount }) }}</span>
                </span>
            </span>
            <span v-if="mapped && previewText !== null" class="hidden max-w-[45%] truncate rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 md:inline! dark:bg-gray-800! dark:text-gray-300!" :title="previewText">
                {{ previewText }}
            </span>
            <Badge v-if="!field.supported" :text="__('As is')" color="gray" size="sm" />
            <span class="text-gray-400" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
        </button>

        <p v-if="error" class="px-3 pb-2 text-sm text-red-600 dark:text-red-400!">{{ error }}</p>

        <div v-if="expanded" class="space-y-4 border-t border-gray-200 px-3 py-3 dark:border-gray-700!">
            <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                <Select :model-value="mode" :options="modes" option-label="label" option-value="value" :aria-label="__('Where the value comes from')" @update:model-value="setMode" />

                <PathInput v-if="mode === 'path'" :model-value="m.path ?? ''" :scope="scope" @update:model-value="set('path', $event)" />

                <template v-else-if="mode === 'value'">
                    <Switch v-if="field.kind === 'toggle'" :model-value="!!m.value" @update:model-value="set('value', $event)" />
                    <Select v-else-if="field.kind === 'options'" :model-value="m.value" :options="optionChoices" option-label="label" option-value="value" @update:model-value="set('value', $event)" />
                    <Input v-else :model-value="m.value ?? ''" @update:model-value="emit({ ...m, value: $event })" />
                </template>

                <div v-else-if="mode === 'template'">
                    <Input :model-value="m.template ?? ''" class="font-mono" :placeholder="'{first_name} {last_name}'" @update:model-value="emit({ ...m, template: $event })" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Paths in braces are replaced with the item\'s values.') }}</p>
                </div>
            </div>

            <template v-if="mapped">
                <!-- Kind-specific settings -->
                <div v-if="field.kind === 'options'" class="space-y-2">
                    <p class="text-sm font-medium">{{ __('Translate feed values to options') }}</p>
                    <Rows v-model="valueRows" name-key="from" value-key="to" :name-placeholder="__('In the feed')" :value-placeholder="__('Option key')" :add-text="__('Add a translation')" />
                    <div class="flex items-center gap-2 text-sm">
                        <span>{{ __('Values that match no option:') }}</span>
                        <div class="w-56">
                            <Select :model-value="m.unknown ?? 'skip'" :options="[{ value: 'skip', label: __('Leave out, with a warning') }, { value: 'raw', label: __('Save as they are') }, { value: 'error', label: __('Count as an error') }]" option-label="label" option-value="value" @update:model-value="set('unknown', $event === 'skip' ? null : $event)" />
                        </div>
                    </div>
                </div>

                <div v-if="field.kind === 'toggle' && mode !== 'value'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('Also means yes') }}<Input :model-value="list(m.true_values)" placeholder="in stock, Y" @update:model-value="set('true_values', unlist($event))" /></label>
                    <label class="text-sm">{{ __('Also means no') }}<Input :model-value="list(m.false_values)" placeholder="sold out, N" @update:model-value="set('false_values', unlist($event))" /></label>
                </div>

                <div v-if="field.kind === 'date'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('Date format in the feed') }}<Input :model-value="m.format ?? ''" :placeholder="__('Detect (e.g. d/m/Y H:i)')" @update:model-value="set('format', $event)" /></label>
                    <label class="text-sm">{{ __('Timezone of the feed\'s dates') }}<Input :model-value="m.timezone ?? ''" placeholder="Europe/London" @update:model-value="set('timezone', $event)" /></label>
                </div>

                <div v-if="field.kind === 'relationship'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('Match on') }}
                        <Select :model-value="m.match ?? (field.type === 'users' ? 'email' : 'title')" :options="(field.type === 'users' ? ['email', 'id', 'name'] : ['title', 'slug', 'id']).map((v) => ({ value: v, label: v }))" option-label="label" option-value="value" @update:model-value="set('match', $event)" />
                    </label>
                    <div v-if="field.type !== 'users'" class="flex items-end pb-2">
                        <Toggle :model-value="!!m.create" :label="__('Create what doesn\'t exist yet')" @update:model-value="set('create', $event || null)" />
                    </div>
                </div>

                <div v-if="field.kind === 'assets'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('Folder') }}<Input :model-value="m.folder ?? ''" class="font-mono" placeholder="books/{isbn}" @update:model-value="set('folder', $event)" /></label>
                    <label class="text-sm">{{ __('Alt text from') }}<PathInput :model-value="m.alt ?? ''" :placeholder="__('e.g. title')" @update:model-value="set('alt', $event)" /></label>
                    <div class="flex items-center gap-4 sm:col-span-2">
                        <Toggle :model-value="m.source === 'existing'" :label="__('The feed gives paths of files already in the container')" @update:model-value="set('source', $event ? 'existing' : null)" />
                        <Toggle v-if="m.source !== 'existing'" :model-value="!!m.refresh" :label="__('Check for a new version each run')" @update:model-value="set('refresh', $event || null)" />
                    </div>
                </div>

                <div v-if="['text', 'textarea'].includes(field.type)" class="flex items-center gap-2 text-sm">
                    <span>{{ __('HTML in the feed:') }}</span>
                    <div class="w-64">
                        <Select :model-value="m.html ?? 'strip'" :options="[{ value: 'strip', label: __('Remove it (plain text)') }, { value: 'sanitize', label: __('Keep safe tags') }, { value: 'trusted', label: __('Keep everything (trusted feed)') }]" option-label="label" option-value="value" @update:model-value="set('html', $event === 'strip' ? null : $event)" />
                    </div>
                </div>

                <div v-if="field.type === 'bard'" class="flex items-center gap-4">
                    <Toggle :model-value="!!m.markdown" :label="__('The feed sends Markdown')" @update:model-value="set('markdown', $event || null)" />
                </div>

                <!-- Rows: grid and replicator -->
                <div v-if="field.kind === 'rows' && m.path" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">{{ __('Rows') }}
                            <Select :model-value="m.mode ?? 'replace'" :options="[{ value: 'replace', label: __('Replace the rows') }, { value: 'append', label: __('Add after existing rows') }, { value: 'merge', label: __('Merge on a key') }]" option-label="label" option-value="value" @update:model-value="set('mode', $event === 'replace' ? null : $event)" />
                        </label>
                        <label v-if="m.mode === 'merge'" class="text-sm">{{ __('Row key field') }}
                            <Select :model-value="m.merge_key" :options="rowFields.map((f) => ({ value: f.handle, label: f.display }))" option-label="label" option-value="value" @update:model-value="set('merge_key', $event)" />
                        </label>
                        <label v-if="field.type === 'replicator'" class="text-sm">{{ __('Set') }}
                            <Select :model-value="m.set" :options="sets" option-label="label" option-value="value" @update:model-value="set('set', $event)" />
                        </label>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Each item at :path becomes a row. Paths below are inside the row; start one with ^. to read the main item.', { path: m.path }) }}</p>
                    <div class="space-y-2 border-l-2 border-gray-200 pl-3 dark:border-gray-700!">
                        <FieldMapping
                            v-for="child in rowFields"
                            :key="child.handle"
                            :field="child"
                            :model-value="m.fields?.[child.handle] ?? null"
                            :preview="childPreview(child.handle)"
                            :scope="rowScope"
                            :depth="depth + 1"
                            @update:model-value="setChild(child.handle, $event)"
                        />
                    </div>
                </div>

                <!-- Group -->
                <div v-if="field.kind === 'group'" class="space-y-2 border-l-2 border-gray-200 pl-3 dark:border-gray-700!">
                    <FieldMapping
                        v-for="child in field.fields ?? []"
                        :key="child.handle"
                        :field="child"
                        :model-value="m.fields?.[child.handle] ?? null"
                        :preview="childPreview(child.handle)"
                        :scope="scope"
                        :depth="depth + 1"
                        @update:model-value="setChild(child.handle, $event)"
                    />
                </div>

                <!-- Cargo variants -->
                <div v-if="field.kind === 'variants' && m.path" class="space-y-3">
                    <p class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Each item at :path becomes one option (Hardback, Paperback…). Paths below are inside it.', { path: m.path }) }}</p>
                    <div>
                        <p class="mb-1 text-sm font-medium">{{ __('Variant dimensions') }}</p>
                        <Rows v-model="dimensionRows" name-key="name" value-key="path" :name-placeholder="__('Name, e.g. Format')" :value-placeholder="__('Path, e.g. binding')" :add-text="__('Add a dimension')" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm">{{ __('SKU') }}<PathInput :model-value="m.sku ?? ''" :scope="m.path" placeholder="sku" @update:model-value="set('sku', $event)" /></label>
                        <label class="text-sm">{{ __('Price') }}<PathInput :model-value="m.price?.path ?? ''" :scope="m.path" placeholder="price" @update:model-value="setSub('price', 'path', $event)" /></label>
                        <label class="text-sm">{{ __('Stock') }}<PathInput :model-value="m.stock?.path ?? ''" :scope="m.path" placeholder="stock" @update:model-value="setSub('stock', 'path', $event)" /></label>
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <Toggle :model-value="!!m.price?.minor_units" :label="__('Prices are already in pence/cents')" @update:model-value="setSub('price', 'minor_units', $event || null)" />
                        <Toggle :model-value="m.stock?.mode === 'lower_only'" :label="__('Only ever lower stock')" @update:model-value="setSub('stock', 'mode', $event ? 'lower_only' : null)" />
                        <Toggle :model-value="m.mode === 'merge'" :label="__('Keep options the feed doesn\'t list')" @update:model-value="set('mode', $event ? 'merge' : null)" />
                        <Toggle :model-value="!!(m.price?.create_only && m.stock?.create_only)" :label="__('Price and stock for new options only (a stock feed keeps them up to date)')" @update:model-value="emit({ ...m, price: { ...(m.price ?? {}), create_only: $event || undefined }, stock: { ...(m.stock ?? {}), create_only: $event || undefined } })" />
                    </div>
                    <div v-if="field.fields?.filter((f) => f.handle !== 'sku').length" class="space-y-2 border-l-2 border-gray-200 pl-3 dark:border-gray-700!">
                        <FieldMapping
                            v-for="child in field.fields.filter((f) => f.handle !== 'sku')"
                            :key="child.handle"
                            :field="child"
                            :model-value="m.fields?.[child.handle] ?? null"
                            :scope="m.path"
                            :depth="depth + 1"
                            @update:model-value="setChild(child.handle, $event)"
                        />
                    </div>
                </div>

                <!-- Transforms and empty values -->
                <div v-if="!['rows', 'group', 'variants'].includes(field.kind)">
                    <button type="button" class="text-sm text-blue-700 hover:underline! dark:text-blue-400!" @click="showTransforms = !showTransforms">
                        {{ showTransforms ? '▾' : '▸' }} {{ transformCount ? __('Transforms (:n)', { n: transformCount }) : __('Transform the value') }}
                    </button>
                    <div v-if="showTransforms || transformCount" v-show="showTransforms" class="mt-2">
                        <TransformsEditor :model-value="m.transforms ?? []" @update:model-value="set('transforms', $event)" />
                    </div>
                </div>

                <Toggle v-if="depth === 0 && field.kind !== 'variants'" :model-value="!!m.create_only" :label="__('Only when the item is created (then the site, or another feed, looks after it)')" @update:model-value="set('create_only', $event || null)" />

                <div v-if="mode !== 'value' && mode !== 'fields'" class="flex flex-wrap items-center gap-2 text-sm">
                    <span>{{ __('When the feed has no value:') }}</span>
                    <div class="w-56">
                        <Select :model-value="m.empty ?? 'clear'" :options="[{ value: 'clear', label: __('Clear the field') }, { value: 'keep', label: __('Keep what\'s there') }, { value: 'default', label: __('Use a default') }]" option-label="label" option-value="value" @update:model-value="set('empty', $event === 'clear' ? null : $event)" />
                    </div>
                    <Input v-if="m.empty === 'default'" :model-value="m.default ?? ''" class="w-56" :placeholder="__('Default value')" @update:model-value="set('default', $event)" />
                </div>
            </template>

            <p v-if="field.instructions" class="text-xs text-gray-500">{{ field.instructions }}</p>
        </div>
    </div>
</template>
