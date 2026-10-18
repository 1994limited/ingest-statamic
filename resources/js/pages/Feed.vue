<!--
    Creating or editing a feed: where it comes from, which items to read,
    where they go, how fields are filled, the sync rules and the schedule.
    The feed preview on the right reads the real feed as it's set up here.
-->
<script>
import Toggle from '../components/Toggle.vue';
import { Head, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Field, Header, Input, Panel, Select, Switch, TabContent, TabList, Tabs, TabTrigger, Textarea } from '@statamic/cms/ui';
import { reactive } from 'vue';
import FieldMapping from '../components/FieldMapping.vue';
import PathInput from '../components/PathInput.vue';
import PreviewPanel from '../components/PreviewPanel.vue';
import Rows from '../components/Rows.vue';
import { clone, errorMessage, toast } from '../helpers.js';

export default {
    components: { Toggle, Alert, Badge, Button, Field, FieldMapping, Head, Header, Input, Panel, PathInput, PreviewPanel, Rows, Select, Switch, TabContent, TabList, Tabs, TabTrigger, Textarea },

    props: {
        handle: { type: String, default: null },
        feed: { type: Object, required: true },
        icon: { type: String, required: true },
        urls: { type: Object, required: true },
    },

    provide() {
        return { picker: this.picker, options: this.options };
    },

    data() {
        const form = clone(this.feed);
        form.title ??= '';
        form.source ??= { type: 'url' };
        form.source.headers ??= [];
        form.source.query ??= [];
        form.source.auth ??= { type: 'none' };
        form.source.pagination ??= { type: 'none' };
        form.format ??= { type: 'auto' };
        form.filters ??= [];
        form.destination ??= { type: 'entries' };
        form.key ??= [];
        form.mapping = Array.isArray(form.mapping) ? {} : (form.mapping ?? {});
        form.sync ??= {};
        form.sync.missing ??= { action: 'leave' };
        form.sync.safety ??= {};
        form.schedule ??= { frequency: 'manual' };
        form.notify ??= { on: ['failed', 'aborted'] };
        form.then_run ??= [];

        return {
            form,
            newHandle: '',
            tab: 'source',
            saving: false,
            errors: {},
            options: reactive({}),
            picker: reactive({ target: null, scope: null, set: null, paths: {} }),
            fields: [],
            fieldsError: null,
            preview: null,
            previewError: null,
            previewing: false,
            previewIndex: 0,
            previewTimer: null,
            uploading: false,
        };
    },

    computed: {
        creating() {
            return !this.handle;
        },

        title() {
            return this.creating ? __('Create Feed') : this.form.title || this.handle;
        },

        sourceType() {
            return this.form.source.type === 'api' ? 'url' : this.form.source.type;
        },

        destinationType() {
            return this.form.destination.type;
        },

        collection() {
            return (this.options.collections ?? []).find((c) => c.value === this.form.destination.collection);
        },

        taxonomy() {
            return (this.options.taxonomies ?? []).find((t) => t.value === this.form.destination.taxonomy);
        },

        collectionChoices() {
            const all = this.options.collections ?? [];
            return ['cargo_products', 'cargo_stock'].includes(this.destinationType) ? all.filter((c) => c.cargo) : all;
        },

        keyPaths() {
            return Object.keys(this.picker.paths).filter((p) => !p.includes('*'));
        },

        mappedCount() {
            return Object.keys(this.form.mapping).length;
        },

        envNames() {
            const names = [];
            const walk = (v) => {
                if (typeof v === 'string' && /^env:[A-Z0-9_]+$/i.test(v.trim())) names.push(v.trim().slice(4));
                else if (v && typeof v === 'object') Object.values(v).forEach(walk);
            };
            walk(this.form.source);
            return [...new Set(names)];
        },

        sampleMapped() {
            return this.preview?.mapped?.[this.previewIndex]?.values ?? {};
        },

        sampleDisplay() {
            return this.preview?.mapped?.[this.previewIndex]?.display ?? {};
        },

        mappingErrors() {
            const out = {};
            for (const [key, messages] of Object.entries(this.errors)) {
                if (key.startsWith('mapping.')) out[key.slice(8)] = messages[0];
            }
            return out;
        },

        tabErrors() {
            const tabFor = (key) =>
                key.startsWith('source') || key === 'title' || key === 'handle'
                    ? 'source'
                    : key.startsWith('format') || key.startsWith('filters')
                      ? 'items'
                      : key.startsWith('destination') || key === 'key' || key === 'localizes'
                        ? 'destination'
                        : key.startsWith('mapping')
                          ? 'mapping'
                          : key.startsWith('schedule') || key.startsWith('notify')
                            ? 'schedule'
                            : 'sync';
            const out = {};
            Object.keys(this.errors).forEach((key) => (out[tabFor(key)] = (out[tabFor(key)] ?? 0) + 1));
            return out;
        },

        // What changes the feed read: a new fetch. Everything else re-maps the cached sample.
        fetchSignature() {
            return JSON.stringify([this.form.source, this.form.format]);
        },

        mapSignature() {
            return JSON.stringify([this.form.mapping, this.form.destination, this.form.filters]);
        },
    },

    watch: {
        fetchSignature() {
            this.schedulePreview(900);
        },

        mapSignature() {
            this.schedulePreview(500);
        },

        'form.destination': {
            deep: true,
            handler() {
                this.loadFields();
            },
        },

        envNames() {
            this.loadOptions();
        },
    },

    mounted() {
        this.loadOptions();
        this.loadFields();
        if (this.ready()) this.loadPreview();
    },

    methods: {
        err(key) {
            return this.errors[key]?.[0] ?? null;
        },

        ready() {
            const s = this.form.source;
            return (this.sourceType === 'url' && /^https?:\/\/.+/.test(s.url ?? '')) || (this.sourceType === 'upload' && s.file) || (this.sourceType === 'path' && s.path) || (this.sourceType === 'google_sheets' && s.url);
        },

        async loadOptions() {
            try {
                const { data } = await this.$axios.get(this.urls.options, { params: { env: this.envNames.join(',') } });
                Object.assign(this.options, data);
            } catch (error) {
                toast('error', errorMessage(error));
            }
        },

        async loadFields() {
            const d = this.form.destination;
            const ready = { entries: d.collection, cargo_products: d.collection, cargo_stock: d.collection, terms: d.taxonomy, globals: d.set, users: true }[d.type];

            if (!ready) {
                this.fields = [];
                return;
            }

            try {
                const { data } = await this.$axios.post(this.urls.fields, { destination: d });
                this.fields = data.fields;
                this.fieldsError = null;
            } catch (error) {
                this.fields = [];
                this.fieldsError = errorMessage(error);
            }
        },

        schedulePreview(delay) {
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => this.loadPreview(), delay);
        },

        async loadPreview(fresh = false) {
            if (!this.ready()) return;

            this.previewing = true;

            try {
                const { data } = await this.$axios.post(this.urls.preview, { handle: this.handle ?? this.newHandle ?? 'new', feed: this.payload(), fresh });
                this.preview = data;
                this.previewError = null;
                this.picker.paths = data.paths ?? {};
                if (this.previewIndex >= (data.items?.length ?? 0)) this.previewIndex = 0;
            } catch (error) {
                this.previewError = errorMessage(error);
            } finally {
                this.previewing = false;
            }
        },

        payload() {
            return clone(this.form);
        },

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const request = this.creating
                    ? this.$axios.post(this.urls.save, { handle: this.newHandle, feed: this.payload() })
                    : this.$axios.put(this.urls.save, { feed: this.payload() });
                const { data } = await request;
                toast('success', data.message);
                router.visit(data.redirect);
            } catch (error) {
                this.errors = error?.response?.data?.errors ?? {};
                toast('error', errorMessage(error));
                const first = Object.keys(this.tabErrors)[0];
                if (first) this.tab = first;
            } finally {
                this.saving = false;
            }
        },

        async upload(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            const handle = this.handle || this.newHandle || (this.form.title || 'feed').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'feed';
            const body = new FormData();
            body.append('file', file);
            body.append('handle', handle);
            this.uploading = true;

            try {
                const { data } = await this.$axios.post(this.urls.upload, body);
                if (this.creating && !this.newHandle) this.newHandle = handle;
                this.form.source.file = data.file;
                toast('success', __('Uploaded.'));
            } catch (error) {
                toast('error', errorMessage(error));
            } finally {
                this.uploading = false;
            }
        },

        setMapping(handle, value) {
            const mapping = { ...this.form.mapping };
            if (value === null) delete mapping[handle];
            else mapping[handle] = value;
            this.form.mapping = mapping;
        },

        /** Map every field whose handle matches a key in the feed. */
        autoMap() {
            const paths = Object.keys(this.picker.paths);
            const mapping = { ...this.form.mapping };
            let count = 0;

            for (const field of this.fields) {
                if (mapping[field.handle] || ['rows', 'group', 'variants'].includes(field.kind)) continue;

                const norm = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');
                const match = paths.find((p) => norm(p) === norm(field.handle)) ?? paths.find((p) => norm(p.split('.').pop()) === norm(field.handle) && !p.includes('*'));

                if (match) {
                    mapping[field.handle] = { path: match };
                    count++;
                }
            }

            this.form.mapping = mapping;
            toast('success', count ? __(':n fields mapped by name. Check them below.', { n: count }) : __('No feed values share a field\'s name.'));
        },

        useItemPath(path) {
            this.form.format.item_path = path;
        },

        addFilter() {
            this.form.filters = [...this.form.filters, { path: '', op: 'equals', value: '' }];
        },

        setKey(index, value) {
            const key = [...this.form.key];
            key[index] = value;
            this.form.key = key;
        },

        list: (v) => (v ?? []).join(', '),
        unlist: (v) => v.split(',').map((x) => x.trim()).filter(Boolean),
        envState(name) {
            return (this.options.env ?? []).find((e) => e.name === name)?.set;
        },
        toggleNotify(event, on) {
            const list = new Set(this.form.notify.on ?? []);
            on ? list.add(event) : list.delete(event);
            this.form.notify.on = [...list];
        },
    },
};
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-[96rem]">
        <Header :title="title" :icon="icon">
            <Button :href="urls.show ?? urls.index" :text="__('Cancel')" />
            <Button variant="primary" :loading="saving" :text="__('Save')" @click="save" />
        </Header>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_28rem]">
            <Tabs v-model="tab">
                <TabList class="mb-4">
                    <TabTrigger name="source" :text="__('Source') + (tabErrors.source ? ' •' : '')" />
                    <TabTrigger name="items" :text="__('Items') + (tabErrors.items ? ' •' : '')" />
                    <TabTrigger name="destination" :text="__('Destination') + (tabErrors.destination ? ' •' : '')" />
                    <TabTrigger name="mapping" :text="(mappedCount ? __('Mapping (:n)', { n: mappedCount }) : __('Mapping')) + (tabErrors.mapping ? ' •' : '')" />
                    <TabTrigger name="sync" :text="__('Sync rules') + (tabErrors.sync ? ' •' : '')" />
                    <TabTrigger name="schedule" :text="__('Schedule & alerts') + (tabErrors.schedule ? ' •' : '')" />
                </TabList>

                <!-- Source -->
                <TabContent name="source">
                    <Panel>
                        <div class="space-y-5 p-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <Field :label="__('Name')" :error="err('title')" required>
                                    <Input v-model="form.title" :placeholder="__('Supplier catalogue')" />
                                </Field>
                                <Field v-if="creating" :label="__('Handle')" :instructions="__('Used in commands and the webhook. Leave empty to make one from the name.')" :error="err('handle')">
                                    <Input v-model="newHandle" class="font-mono" placeholder="supplier_catalogue" />
                                </Field>
                            </div>

                            <Field :label="__('Where the feed comes from')" :error="err('source.type')">
                                <Select v-model="form.source.type" :options="options.sources ?? []" option-label="label" option-value="value" />
                            </Field>

                            <template v-if="sourceType === 'url'">
                                <Field :label="__('Feed URL')" :error="err('source.url')" :instructions="__('A feed, an export or an API endpoint. {page}, {offset} or {cursor} in the URL are filled in by pagination.')" required>
                                    <Input v-model="form.source.url" type="url" class="font-mono" placeholder="https://supplier.example/api/books" />
                                </Field>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <Field :label="__('Authentication')" :error="err('source.auth.type')">
                                        <Select
                                            v-model="form.source.auth.type"
                                            :options="[
                                                { value: 'none', label: __('None') },
                                                { value: 'bearer', label: __('Bearer token') },
                                                { value: 'api_key', label: __('API key') },
                                                { value: 'basic', label: __('Username and password') },
                                                { value: 'oauth2', label: __('OAuth 2 (client credentials)') },
                                            ]"
                                            option-label="label"
                                            option-value="value"
                                        />
                                    </Field>
                                    <Field :label="__('Method')">
                                        <Select v-model="form.source.method" :options="[{ value: 'GET', label: 'GET' }, { value: 'POST', label: 'POST' }]" option-label="label" option-value="value" :placeholder="'GET'" />
                                    </Field>
                                </div>

                                <div v-if="form.source.auth.type !== 'none'" class="space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-850!">
                                    <p class="text-sm text-gray-700 dark:text-gray-300!">{{ __('Secrets stay in .env. Enter env:NAME here, for example env:SUPPLIER_TOKEN, and add SUPPLIER_TOKEN=… to .env on each server.') }}</p>
                                    <Field v-if="form.source.auth.type === 'bearer'" :label="__('Token')" :error="err('source.auth.token')">
                                        <Input v-model="form.source.auth.token" class="font-mono" placeholder="env:SUPPLIER_TOKEN" />
                                    </Field>
                                    <template v-if="form.source.auth.type === 'api_key'">
                                        <div class="grid gap-4 sm:grid-cols-3">
                                            <Field :label="__('Sent as')"><Select v-model="form.source.auth.in" :options="[{ value: 'header', label: __('A header') }, { value: 'query', label: __('A query parameter') }]" option-label="label" option-value="value" :placeholder="__('A header')" /></Field>
                                            <Field :label="__('Name')"><Input v-model="form.source.auth.name" class="font-mono" placeholder="X-API-Key" /></Field>
                                            <Field :label="__('Key')" :error="err('source.auth.value')"><Input v-model="form.source.auth.value" class="font-mono" placeholder="env:SUPPLIER_KEY" /></Field>
                                        </div>
                                    </template>
                                    <div v-if="form.source.auth.type === 'basic'" class="grid gap-4 sm:grid-cols-2">
                                        <Field :label="__('Username')"><Input v-model="form.source.auth.username" /></Field>
                                        <Field :label="__('Password')" :error="err('source.auth.password')"><Input v-model="form.source.auth.password" class="font-mono" placeholder="env:SUPPLIER_PASSWORD" /></Field>
                                    </div>
                                    <div v-if="form.source.auth.type === 'oauth2'" class="grid gap-4 sm:grid-cols-2">
                                        <Field :label="__('Token URL')" class="sm:col-span-2"><Input v-model="form.source.auth.token_url" type="url" class="font-mono" placeholder="https://auth.example/oauth/token" /></Field>
                                        <Field :label="__('Client ID')"><Input v-model="form.source.auth.client_id" class="font-mono" /></Field>
                                        <Field :label="__('Client secret')" :error="err('source.auth.client_secret')"><Input v-model="form.source.auth.client_secret" class="font-mono" placeholder="env:SUPPLIER_CLIENT_SECRET" /></Field>
                                        <Field :label="__('Scope')"><Input v-model="form.source.auth.scope" /></Field>
                                    </div>
                                    <div v-if="envNames.length" class="flex flex-wrap gap-2">
                                        <Badge v-for="name in envNames" :key="name" :text="name + (envState(name) ? ' ✓ ' + __('set') : ' ✗ ' + __('missing'))" :color="envState(name) ? 'green' : 'red'" size="sm" />
                                    </div>
                                </div>

                                <Field :label="__('Headers')" :instructions="__('Sent with every request. Values can be env:NAME.')">
                                    <Rows v-model="form.source.headers" :name-placeholder="'Accept'" :value-placeholder="'application/json'" :add-text="__('Add a header')" />
                                </Field>
                                <Field :label="__('Query parameters')">
                                    <Rows v-model="form.source.query" :name-placeholder="'status'" :value-placeholder="'live'" :add-text="__('Add a parameter')" />
                                </Field>

                                <Field :label="__('Pagination')" :error="err('source.pagination.type')" :instructions="__('How to find the next page of a long feed.')">
                                    <Select
                                        v-model="form.source.pagination.type"
                                        :options="[
                                            { value: 'none', label: __('One page') },
                                            { value: 'next_url', label: __('A link to the next page in the feed') },
                                            { value: 'link_header', label: __('A Link header') },
                                            { value: 'page', label: __('A page number') },
                                            { value: 'offset', label: __('An offset') },
                                            { value: 'cursor', label: __('A cursor') },
                                        ]"
                                        option-label="label"
                                        option-value="value"
                                    />
                                </Field>
                                <div v-if="!['none', 'link_header', undefined].includes(form.source.pagination.type)" class="grid gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-3 dark:bg-gray-850!">
                                    <Field v-if="['next_url', 'cursor'].includes(form.source.pagination.type)" :label="form.source.pagination.type === 'cursor' ? __('Where the cursor is') : __('Where the next link is')" :error="err('source.pagination.path')">
                                        <PathInput v-model="form.source.pagination.path" :placeholder="form.source.pagination.type === 'cursor' ? 'next_cursor' : 'links.next'" />
                                    </Field>
                                    <Field v-if="['page', 'offset', 'cursor'].includes(form.source.pagination.type)" :label="__('Query parameter')" :instructions="__('Or put {page}, {offset} or {cursor} in the URL.')">
                                        <Input v-model="form.source.pagination.param" class="font-mono" :placeholder="form.source.pagination.type" />
                                    </Field>
                                    <Field v-if="['page', 'offset'].includes(form.source.pagination.type)" :label="__('Page size')">
                                        <Input v-model="form.source.pagination.size" type="number" min="1" />
                                    </Field>
                                    <Field v-if="form.source.pagination.type === 'page'" :label="__('Last page number is at')">
                                        <PathInput v-model="form.source.pagination.last_page_path" placeholder="meta.last_page" />
                                    </Field>
                                    <Field v-if="['page', 'offset'].includes(form.source.pagination.type)" :label="__('Total items is at')">
                                        <PathInput v-model="form.source.pagination.total_path" placeholder="meta.total" />
                                    </Field>
                                    <Field v-if="form.source.pagination.type === 'cursor'" :label="__('More pages flag at')">
                                        <PathInput v-model="form.source.pagination.has_more_path" placeholder="has_more" />
                                    </Field>
                                    <Field :label="__('Most pages to read')">
                                        <Input v-model="form.source.pagination.max_pages" type="number" min="1" placeholder="500" />
                                    </Field>
                                </div>
                            </template>

                            <template v-if="sourceType === 'upload'">
                                <Field :label="__('File')" :error="err('source.file')" :instructions="__('JSON, XML, RSS, CSV or BLM. Kept privately on the server; upload a new one whenever it changes.')">
                                    <div class="flex items-center gap-3">
                                        <input type="file" accept=".json,.ndjson,.jsonl,.xml,.rss,.atom,.csv,.tsv,.txt,.blm" class="text-sm" @change="upload" />
                                        <span v-if="uploading" class="text-sm text-gray-600">{{ __('Uploading…') }}</span>
                                        <Badge v-else-if="form.source.file" :text="form.source.file" color="green" size="sm" />
                                    </div>
                                </Field>
                            </template>

                            <template v-if="sourceType === 'path'">
                                <Field :label="__('Path on the server')" :error="err('source.path')" :instructions="__('Only folders listed in ingest.server_paths can be read.')">
                                    <Input v-model="form.source.path" class="font-mono" placeholder="/home/forge/drops/stock.csv" />
                                </Field>
                                <Toggle v-model="form.source.archive" :label="__('Move the file into an archive folder after a successful run')" />
                            </template>

                            <template v-if="sourceType === 'google_sheets'">
                                <Field :label="__('Sheet link')" :error="err('source.url')" :instructions="__('Copy it from the sheet\'s Share button. The tab in the link is the one read, unless you name another.')">
                                    <Input v-model="form.source.url" type="url" class="font-mono" placeholder="https://docs.google.com/spreadsheets/d/…/edit#gid=0" />
                                </Field>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <Field :label="__('Tab name')"><Input v-model="form.source.sheet" :placeholder="__('From the link')" /></Field>
                                    <div class="flex items-end pb-2"><Toggle v-model="form.source.private" :label="__('Private sheet (uses the service account)')" /></div>
                                </div>
                            </template>
                        </div>
                    </Panel>
                </TabContent>

                <!-- Items -->
                <TabContent name="items">
                    <Panel>
                        <div class="space-y-5 p-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <Field :label="__('Format')" :error="err('format.type')">
                                    <Select v-model="form.format.type" :options="options.formats ?? []" option-label="label" option-value="value" />
                                </Field>
                                <Field :label="__('Item path')" :instructions="__('Where the repeating items are: data, catalogue/book, rss/channel/item. Empty means the whole file is one item, or each row of a CSV.')">
                                    <PathInput v-model="form.format.item_path" :placeholder="preview?.item_path || 'data'" />
                                </Field>
                            </div>

                            <div v-if="preview?.tree?.length" class="rounded-lg border border-gray-200 dark:border-gray-700!">
                                <p class="border-b border-gray-200 px-3 py-2 text-sm font-medium dark:border-gray-700!">{{ __('The feed\'s structure: pick where the items are') }}</p>
                                <div class="max-h-64 overflow-y-auto">
                                    <button
                                        v-for="node in preview.tree"
                                        :key="node.path"
                                        type="button"
                                        class="flex w-full items-baseline gap-3 px-3 py-1.5 text-left text-sm hover:bg-blue-50 dark:hover:bg-gray-800!"
                                        :class="{ 'bg-blue-50 dark:bg-gray-800!': form.format.item_path === node.path }"
                                        @click="useItemPath(node.path)"
                                    >
                                        <span class="font-mono">{{ node.path }}</span>
                                        <Badge v-if="node.count > 1" :text="__(':n items', { n: node.count })" color="blue" size="sm" />
                                        <span class="truncate text-xs text-gray-500">{{ node.keys.join(', ') }}</span>
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.format.type === 'csv' || preview?.format === 'csv'" class="grid gap-4 sm:grid-cols-4">
                                <Field :label="__('Separator')">
                                    <Select v-model="form.format.delimiter" :options="[{ value: 'auto', label: __('Detect') }, { value: ',', label: __('Comma') }, { value: ';', label: __('Semicolon') }, { value: '\\t', label: __('Tab') }, { value: '|', label: __('Pipe') }]" option-label="label" option-value="value" :placeholder="__('Detect')" />
                                </Field>
                                <Field :label="__('Encoding')">
                                    <Select v-model="form.format.encoding" :options="[{ value: 'auto', label: __('Detect') }, { value: 'UTF-8', label: 'UTF-8' }, { value: 'Windows-1252', label: 'Windows-1252' }, { value: 'ISO-8859-1', label: 'ISO-8859-1' }]" option-label="label" option-value="value" :placeholder="__('Detect')" />
                                </Field>
                                <Field :label="__('Rows to skip')"><Input v-model="form.format.skip_rows" type="number" min="0" placeholder="0" /></Field>
                                <div class="flex items-end pb-2"><Toggle :model-value="form.format.header !== false" :label="__('First row has the column names')" @update:model-value="form.format.header = $event" /></div>
                            </div>

                            <Field :label="__('Filters')" :instructions="__('Only items that pass every filter are imported. Items left out count as missing from the feed.')">
                                <div class="space-y-2">
                                    <div v-for="(filter, i) in form.filters" :key="i" class="flex flex-wrap items-center gap-2">
                                        <div class="w-56"><PathInput v-model="filter.path" placeholder="status" /></div>
                                        <div class="w-40"><Select v-model="filter.op" :options="(options.operators ?? []).map((o) => ({ value: o, label: o.replaceAll('_', ' ') }))" option-label="label" option-value="value" /></div>
                                        <Input v-if="!['empty', 'not_empty'].includes(filter.op)" v-model="filter.value" class="w-56" placeholder="live" />
                                        <Button size="sm" variant="ghost" icon="trash" :aria-label="__('Remove')" @click="form.filters.splice(i, 1)" />
                                    </div>
                                    <Button size="sm" icon="plus" :text="__('Add a filter')" @click="addFilter" />
                                </div>
                            </Field>
                            <p v-if="preview && form.filters.length" class="text-sm text-gray-600 dark:text-gray-400!">{{ __(':passed of the :count sample items pass the filters.', { passed: preview.filtered, count: preview.items.length }) }}</p>
                        </div>
                    </Panel>
                </TabContent>

                <!-- Destination -->
                <TabContent name="destination">
                    <Panel>
                        <div class="space-y-5 p-4">
                            <Field :label="__('Items become')" :error="err('destination.type')">
                                <Select v-model="form.destination.type" :options="options.destinations ?? []" option-label="label" option-value="value" />
                            </Field>

                            <div v-if="['entries', 'cargo_products'].includes(destinationType)" class="grid gap-4 sm:grid-cols-3">
                                <Field :label="__('Collection')"><Select v-model="form.destination.collection" :options="collectionChoices" option-label="label" option-value="value" /></Field>
                                <Field :label="__('Blueprint')"><Select v-model="form.destination.blueprint" :options="collection?.blueprints ?? []" option-label="label" option-value="value" :placeholder="__('Default')" /></Field>
                                <Field v-if="options.multisite" :label="__('Site')"><Select v-model="form.destination.site" :options="options.sites ?? []" option-label="label" option-value="value" :placeholder="__('Default')" /></Field>
                            </div>
                            <div v-if="destinationType === 'cargo_stock'" class="grid gap-4 sm:grid-cols-2">
                                <Field :label="__('Product collection')"><Select v-model="form.destination.collection" :options="collectionChoices" option-label="label" option-value="value" /></Field>
                                <Field :label="__('SKU field')" :instructions="__('The field on simple products, and the option field on variants, that holds the SKU.')"><Input v-model="form.destination.sku_field" class="font-mono" placeholder="sku" /></Field>
                            </div>
                            <Alert v-if="destinationType === 'cargo_stock'" variant="default" :text="__('Each row is matched to a product or variant by SKU, and only its price and stock change. Nothing is created or removed; rows with an unknown SKU are skipped.')" />

                            <p v-if="['cargo_products', 'cargo_stock'].includes(destinationType) && !collectionChoices.length" class="text-sm text-amber-700">{{ __('No collection is one of Cargo\'s product collections.') }}</p>

                            <Field v-if="destinationType === 'cargo_products'" :label="__('Tax class for new products')" :instructions="__('Given to new products that don\'t map one. Cargo can\'t check out a product without one.')">
                                <Select v-model="form.destination.tax_class" :options="options.tax_classes ?? []" option-label="label" option-value="value" clearable />
                            </Field>

                            <div v-if="destinationType === 'terms'" class="grid gap-4 sm:grid-cols-3">
                                <Field :label="__('Taxonomy')"><Select v-model="form.destination.taxonomy" :options="options.taxonomies ?? []" option-label="label" option-value="value" /></Field>
                                <Field :label="__('Blueprint')"><Select v-model="form.destination.blueprint" :options="taxonomy?.blueprints ?? []" option-label="label" option-value="value" :placeholder="__('Default')" /></Field>
                                <Field v-if="options.multisite" :label="__('Site')"><Select v-model="form.destination.site" :options="options.sites ?? []" option-label="label" option-value="value" :placeholder="__('Default')" /></Field>
                            </div>

                            <div v-if="destinationType === 'globals'" class="grid gap-4 sm:grid-cols-2">
                                <Field :label="__('Global set')" :instructions="__('The feed\'s first item fills the set.')"><Select v-model="form.destination.set" :options="options.globals ?? []" option-label="label" option-value="value" /></Field>
                                <Field v-if="options.multisite" :label="__('Site')"><Select v-model="form.destination.site" :options="options.sites ?? []" option-label="label" option-value="value" :placeholder="__('Default')" /></Field>
                            </div>

                            <div v-if="destinationType === 'users'" class="space-y-2">
                                <Alert variant="default" :text="__('A feed can\'t make anyone a super admin or set passwords. Roles are limited to those listed in ingest.users.roles. Personal data in run history is cleared after a few days.')" />
                                <Toggle v-model="form.destination.send_activation" :label="__('Email new users a link to set their password')" />
                            </div>

                            <p v-if="fieldsError" class="text-sm text-red-600">{{ fieldsError }}</p>

                            <Field v-if="destinationType !== 'globals'" :label="__('Key: what identifies an item')" :error="err('key')" :instructions="__('A value that\'s unique and never changes, such as an ID, SKU or ISBN. Use two for a combined key.')" required>
                                <div class="space-y-2">
                                    <div v-for="(k, i) in form.key.length ? form.key : ['']" :key="i" class="flex items-center gap-2">
                                        <div class="grow"><PathInput :model-value="k" placeholder="sku" @update:model-value="setKey(i, $event)" /></div>
                                        <Button v-if="form.key.length > 1" size="sm" variant="ghost" icon="trash" :aria-label="__('Remove')" @click="form.key.splice(i, 1)" />
                                    </div>
                                    <Button v-if="form.key.length" size="sm" icon="plus" :text="__('Combine with another value')" @click="form.key.push('')" />
                                </div>
                            </Field>

                            <Field v-if="destinationType !== 'globals'" :label="__('Adopt existing content')" :instructions="__('On the first run, items that already exist on the site (made by hand, or by another import) are found by this field and updated, rather than created twice.')">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <Input :model-value="form.sync.adopt?.field ?? ''" class="font-mono" :placeholder="__('Site field, e.g. isbn')" @update:model-value="form.sync.adopt = $event ? { ...(form.sync.adopt ?? {}), field: $event } : null" />
                                    <PathInput :model-value="form.sync.adopt?.path ?? ''" :placeholder="__('Feed path, e.g. isbn')" @update:model-value="form.sync.adopt = { ...(form.sync.adopt ?? {}), path: $event }" />
                                </div>
                            </Field>

                            <Field v-if="options.multisite && ['entries', 'cargo_products'].includes(destinationType)" :label="__('Localize another feed\'s entries')" :error="err('localizes')" :instructions="__('For a feed in another language: items with the same key become localizations of that feed\'s entries.')">
                                <Select v-model="form.localizes" :options="(options.feeds ?? []).filter((f) => f.value !== handle)" option-label="label" option-value="value" clearable />
                            </Field>
                        </div>
                    </Panel>
                </TabContent>

                <!-- Mapping -->
                <TabContent name="mapping">
                    <div class="mb-3 flex flex-wrap items-center gap-3">
                        <p class="grow text-sm text-gray-600 dark:text-gray-400!">{{ __('Fields you don\'t map are never touched. Click a field to map it; values on the right are what the current preview item would become.') }}</p>
                        <Button size="sm" icon="wand" :disabled="!preview || !fields.length" :text="__('Map by name')" @click="autoMap" />
                    </div>
                    <div v-if="!fields.length" class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-600 dark:border-gray-700!">
                        {{ fieldsError || __('Choose the destination first.') }}
                    </div>
                    <div v-else class="space-y-2">
                        <FieldMapping
                            v-for="field in fields"
                            :key="field.handle"
                            :field="field"
                            :model-value="form.mapping[field.handle] ?? null"
                            :preview="form.mapping[field.handle] ? sampleMapped[field.handle] : undefined"
                            :display="form.mapping[field.handle] ? sampleDisplay[field.handle] : undefined"
                            :error="mappingErrors[field.handle] ?? preview?.mapped?.[previewIndex]?.errors?.[field.handle] ?? null"
                            @update:model-value="setMapping(field.handle, $event)"
                        />
                    </div>
                </TabContent>

                <!-- Sync rules -->
                <TabContent name="sync">
                    <Panel>
                        <div class="space-y-5 p-4">
                            <div class="flex flex-wrap gap-6">
                                <Toggle :model-value="form.sync.create !== false" :label="__('Create new items')" @update:model-value="form.sync.create = $event" />
                                <Toggle :model-value="form.sync.update !== false" :label="__('Update existing items')" @update:model-value="form.sync.update = $event" />
                                <Toggle :model-value="form.sync.publish_new !== false" :label="__('Publish new items')" @update:model-value="form.sync.publish_new = $event" />
                            </div>

                            <Field v-if="destinationType !== 'globals'" :label="__('Items that are no longer in the feed')" :error="err('sync.missing.action')">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <Select v-model="form.sync.missing.action" :options="[{ value: 'leave', label: __('Leave them as they are') }, { value: 'unpublish', label: destinationType === 'users' ? __('Block them (remove roles)') : __('Unpublish them') }, { value: 'delete', label: __('Delete them') }]" option-label="label" option-value="value" />
                                    <Input v-if="form.sync.missing.action === 'delete'" v-model="form.sync.missing.delete_after_days" type="number" min="1" :placeholder="__('Unpublish first, delete after … days (optional)')" />
                                </div>
                            </Field>

                            <div v-if="form.sync.missing.action !== 'leave'" class="space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-850!">
                                <p class="text-sm font-medium">{{ __('Safety check') }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Items are only treated as missing after the whole feed was read. A run that would remove more than this share of the feed\'s items stops and tells you instead.') }}</p>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <Field :label="__('Most to remove in one run (%)')" :error="err('sync.safety.max_removal_percent')">
                                        <Input v-model="form.sync.safety.max_removal_percent" type="number" min="0" max="100" :placeholder="String(25)" />
                                    </Field>
                                    <div class="flex items-end pb-2"><Toggle v-model="form.sync.safety.allow_empty" :label="__('Allow an empty feed (removes everything)')" /></div>
                                </div>
                            </div>

                            <Field :label="__('Fields edited on the site')" :instructions="__('When someone changes a mapped field in the Control Panel, the next change from the feed can overwrite it, or leave it and report a conflict.')">
                                <Select :model-value="form.sync.local_edits ?? 'overwrite'" :options="[{ value: 'overwrite', label: __('The feed wins') }, { value: 'keep', label: __('Keep the site\'s edit') }]" option-label="label" option-value="value" @update:model-value="form.sync.local_edits = $event" />
                            </Field>

                            <Field :label="__('When one field can\'t be read')">
                                <Select :model-value="form.sync.on_field_error ?? 'save_rest'" :options="[{ value: 'save_rest', label: __('Save the rest of the item, with a warning') }, { value: 'skip_item', label: __('Leave the whole item out') }]" option-label="label" option-value="value" @update:model-value="form.sync.on_field_error = $event" />
                            </Field>

                            <Field v-if="(options.hooks ?? []).length" :label="__('PHP hook')" :error="err('hook')" :instructions="__('Code in your app that can change or skip items (Ingest::hook()).')">
                                <Select v-model="form.hook" :options="options.hooks" option-label="label" option-value="value" clearable />
                            </Field>

                            <Field :label="__('Then run')" :error="err('then_run')" :instructions="__('Feeds to start after this one succeeds, such as products after categories.')">
                                <Select v-model="form.then_run" :options="(options.feeds ?? []).filter((f) => f.value !== handle)" option-label="label" option-value="value" multiple clearable />
                            </Field>
                        </div>
                    </Panel>
                </TabContent>

                <!-- Schedule & alerts -->
                <TabContent name="schedule">
                    <Panel>
                        <div class="space-y-5 p-4">
                            <Toggle :model-value="form.enabled !== false" :label="__('Feed is on')" @update:model-value="form.enabled = $event" />

                            <div class="grid gap-4 sm:grid-cols-3">
                                <Field :label="__('Run')" :error="err('schedule.frequency')">
                                    <Select
                                        v-model="form.schedule.frequency"
                                        :options="[
                                            { value: 'manual', label: __('Only when started') },
                                            { value: 'minutes', label: __('Every few minutes') },
                                            { value: 'hourly', label: __('Hourly') },
                                            { value: 'daily', label: __('Daily') },
                                            { value: 'weekly', label: __('Weekly') },
                                            { value: 'cron', label: __('Cron expression') },
                                        ]"
                                        option-label="label"
                                        option-value="value"
                                    />
                                </Field>
                                <Field v-if="form.schedule.frequency === 'minutes'" :label="__('Every … minutes')"><Input v-model="form.schedule.every" type="number" min="1" max="59" placeholder="15" /></Field>
                                <Field v-if="form.schedule.frequency === 'hourly'" :label="__('Minutes past the hour')"><Input v-model="form.schedule.minute" type="number" min="0" max="59" placeholder="0" /></Field>
                                <Field v-if="form.schedule.frequency === 'weekly'" :label="__('Day')">
                                    <Select v-model="form.schedule.day" :options="['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].map((d, i) => ({ value: i, label: __(d) }))" option-label="label" option-value="value" />
                                </Field>
                                <Field v-if="['daily', 'weekly'].includes(form.schedule.frequency)" :label="__('Time')" :error="err('schedule.time')"><Input v-model="form.schedule.time" type="time" /></Field>
                                <Field v-if="form.schedule.frequency === 'cron'" :label="__('Expression')" :error="err('schedule.expression')"><Input v-model="form.schedule.expression" class="font-mono" placeholder="0 */2 * * *" /></Field>
                            </div>

                            <Field :label="__('Tell people when')" :instructions="__('Emails go to the addresses in Ingest\'s settings; Slack to INGEST_SLACK_WEBHOOK_URL. Once per problem, then again when it\'s fixed.')">
                                <div class="flex flex-wrap gap-6">
                                    <Toggle :model-value="(form.notify.on ?? []).includes('failed')" :label="__('A run fails')" @update:model-value="toggleNotify('failed', $event)" />
                                    <Toggle :model-value="(form.notify.on ?? []).includes('aborted')" :label="__('The safety check stops a run')" @update:model-value="toggleNotify('aborted', $event)" />
                                    <Toggle :model-value="(form.notify.on ?? []).includes('errors')" :label="__('Some items fail')" @update:model-value="toggleNotify('errors', $event)" />
                                </div>
                            </Field>
                            <Field :label="__('…or the feed hasn\'t run successfully for (hours)')">
                                <Input v-model="form.notify.stale_after_hours" type="number" min="1" class="max-w-40" :placeholder="__('Off')" />
                            </Field>
                        </div>
                    </Panel>
                </TabContent>
            </Tabs>

            <div class="xl:sticky xl:top-4">
                <PreviewPanel v-model:index="previewIndex" :preview="preview" :loading="previewing" :error="previewError" @refresh="loadPreview(true)" />
            </div>
        </div>
    </div>
</template>
