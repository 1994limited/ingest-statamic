<!--
    One run: live progress while it goes, then what it did to each item,
    field by field. Dry runs can be applied as previewed; real runs undone.
-->
<script>
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Header, Input, Modal, Panel } from '@statamic/cms/ui';
import { actionColours, ago, duration, errorMessage, statusColours, toast, when } from '../helpers.js';

const FILTERS = () => [
    { value: '', label: __('Everything') },
    { value: 'created', label: __('Created') },
    { value: 'updated', label: __('Updated') },
    { value: 'unpublished,deleted', label: __('Removed') },
    { value: 'failed', label: __('Failed') },
    { value: 'conflict', label: __('Kept local edits') },
    { value: 'warnings', label: __('With warnings') },
    { value: 'skipped', label: __('Skipped') },
];

export default {
    components: { Alert, Badge, Button, Head, Header, Input, Link, Modal, Panel },

    props: {
        feed: { type: Object, required: true },
        run: { type: Object, required: true },
        undoable: { type: String, default: null },
        can: { type: Object, required: true },
        icon: { type: String, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        return {
            current: this.run,
            canUndoReason: this.undoable,
            items: [],
            total: 0,
            page: 1,
            lastPage: 1,
            filter: '',
            search: '',
            loading: false,
            open: {},
            poll: null,
            timer: null,
            confirmUndo: false,
            working: false,
        };
    },

    computed: {
        filters: () => FILTERS(),

        percent() {
            const r = this.current;
            if (!r.total) return r.active ? 5 : 100;
            return Math.min(100, Math.round((r.processed / r.total) * 100));
        },

        tiles() {
            const c = this.current.counts;
            return [
                ['created', __('Created'), c.created],
                ['updated', __('Updated'), c.updated],
                ['unchanged', __('Unchanged'), c.unchanged],
                ['removed', __('Removed'), c.unpublished + c.deleted],
                ['failed', __('Failed'), c.failed],
                ['skipped', __('Skipped'), c.skipped],
            ];
        },

        title() {
            return this.current.dry_run ? __('Dry run, :when', { when: when(this.current.created_at) }) : __('Run, :when', { when: when(this.current.created_at) });
        },
    },

    watch: {
        filter() {
            this.page = 1;
            this.load();
        },

        search() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                this.page = 1;
                this.load();
            }, 300);
        },
    },

    mounted() {
        this.load();

        if (this.current.active) {
            this.poll = setInterval(() => this.refresh(), 1500);
        }
    },

    beforeUnmount() {
        clearInterval(this.poll);
    },

    methods: {
        ago,
        duration,
        statusColour: (s) => statusColours[s] ?? 'gray',
        actionColour: (a) => actionColours[a] ?? 'gray',

        actionLabel(action) {
            return {
                created: this.current.dry_run ? __('Would create') : __('Created'),
                updated: this.current.dry_run ? __('Would update') : __('Updated'),
                unchanged: __('Unchanged'),
                skipped: __('Skipped'),
                failed: __('Failed'),
                conflict: __('Kept local edit'),
                unpublished: this.current.dry_run ? __('Would unpublish') : __('Unpublished'),
                deleted: this.current.dry_run ? __('Would delete') : __('Deleted'),
            }[action] ?? action;
        },

        async refresh() {
            try {
                const { data } = await this.$axios.get(this.urls.status);
                this.current = data.run;
                this.canUndoReason = data.undoable;

                if (!data.run.active) {
                    clearInterval(this.poll);
                    this.load();
                }
            } catch {
                // Try again next tick.
            }
        },

        async load() {
            this.loading = true;

            try {
                const { data } = await this.$axios.get(this.urls.items, { params: { page: this.page, action: this.filter, search: this.search || undefined } });
                this.items = data.items;
                this.total = data.total;
                this.lastPage = data.last_page;
            } finally {
                this.loading = false;
            }
        },

        go(page) {
            this.page = page;
            this.load();
        },

        async cancel() {
            try {
                const { data } = await this.$axios.post(this.urls.cancel);
                this.current = data.run;
                toast('success', data.message);
            } catch (error) {
                toast('error', errorMessage(error));
            }
        },

        async apply() {
            this.working = true;

            try {
                const { data } = await this.$axios.post(this.urls.apply, { apply: this.current.id });
                router.visit(data.redirect);
            } catch (error) {
                toast('error', errorMessage(error));
            } finally {
                this.working = false;
            }
        },

        async undo() {
            this.confirmUndo = false;
            this.working = true;

            try {
                const { data } = await this.$axios.post(this.urls.undo);
                this.current = data.run;
                this.canUndoReason = __('This run has been undone already.');
                toast('success', data.message);
                this.load();
            } catch (error) {
                toast('error', errorMessage(error));
            } finally {
                this.working = false;
            }
        },
    },
};
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-6xl">
        <div class="mb-2 text-sm"><Link :href="feed.url" class="text-gray-600 hover:underline! dark:text-gray-400!">← {{ feed.title }}</Link></div>

        <Header :title="title" :icon="icon">
            <Badge :text="current.status_label" :color="statusColour(current.status)" />
            <Button v-if="current.active && can.run" :text="__('Cancel')" @click="cancel" />
            <Button v-if="current.dry_run && !current.active && can.run && current.changed > 0" variant="primary" :loading="working" :text="__('Apply these changes')" @click="apply" />
            <Button v-if="!current.dry_run && can.undo && !canUndoReason" :loading="working" :text="__('Undo this run')" icon="history" @click="confirmUndo = true" />
        </Header>

        <Panel v-if="current.active" class="mb-6">
            <div class="p-4">
                <div class="mb-2 flex justify-between text-sm">
                    <span>{{ current.status_label }}…</span>
                    <span v-if="current.total" class="tabular-nums">{{ current.processed }} / {{ current.total }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700!">
                    <div class="h-full rounded-full bg-blue-500 transition-all" :style="{ width: percent + '%' }" />
                </div>
            </div>
        </Panel>

        <Alert v-if="current.dry_run && !current.active" variant="default" class="mb-4" :text="__('Nothing was saved. This is what a run would do. Applying uses exactly these items, not a fresh copy of the feed.')" />
        <Alert v-if="current.status === 'failed'" variant="error" class="mb-4" :heading="__('The run failed')" :text="current.message" />
        <Alert v-else-if="current.status === 'aborted'" variant="warning" class="mb-4" :heading="__('Stopped by the safety check')" :text="current.message" />
        <Alert v-else-if="current.message" variant="default" class="mb-4" :text="current.message" />
        <Alert v-for="note in [...current.notes, ...current.warnings]" :key="note" variant="warning" class="mb-4" :text="note" />
        <Alert v-if="current.undo_conflicts.length" variant="warning" class="mb-4" :heading="__('Left as they are')">
            <ul class="list-disc pl-5"><li v-for="c in current.undo_conflicts" :key="c">{{ c }}</li></ul>
        </Alert>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3! lg:grid-cols-6!">
            <Panel v-for="[key, label, value] in tiles" :key="key" class="mb-0!">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ label }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums" :class="{ 'text-red-600 dark:text-red-400!': key === 'failed' && value > 0 }">{{ value }}</div>
                </div>
            </Panel>
        </div>

        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400!">
            <template v-if="current.total !== null">{{ __(':n items read', { n: current.total }) }} · </template>
            <template v-if="current.duration !== null">{{ __('took :t', { t: duration(current.duration) }) }} · </template>
            <template v-if="current.user">{{ __('started by :name', { name: current.user }) }}</template>
            <template v-else>{{ { schedule: __('started by the schedule'), webhook: __('started by the webhook'), cli: __('started from the command line'), chain: __('started by another feed'), apply: __('a dry run applied') }[current.trigger] ?? '' }}</template>
        </p>

        <div class="mb-3 flex flex-col gap-3 sm:flex-row">
            <div class="flex flex-wrap gap-1">
                <Button v-for="f in filters" :key="f.value" size="sm" :variant="filter === f.value ? 'primary' : 'default'" :text="f.label" @click="filter = f.value" />
            </div>
            <div class="sm:ml-auto sm:w-64"><Input v-model="search" icon="magnifying-glass" :placeholder="__('Search by key or title')" clearable /></div>
        </div>

        <Panel>
            <div v-if="items.length" class="divide-y divide-gray-100 dark:divide-gray-800!">
                <div v-for="(item, i) in items" :key="i" class="px-4 py-3">
                    <button type="button" class="flex w-full items-center gap-3 text-left" :aria-expanded="!!open[i]" @click="open[i] = !open[i]">
                        <Badge :text="actionLabel(item.action)" :color="actionColour(item.action)" size="sm" class="shrink-0" />
                        <span class="min-w-0 grow truncate">
                            <span class="font-medium">{{ item.title || item.key }}</span>
                            <span v-if="item.title" class="ml-2 font-mono text-xs text-gray-500">{{ item.key }}</span>
                        </span>
                        <span v-if="item.changes.length" class="shrink-0 text-xs text-gray-500">{{ item.changes.length === 1 ? __('1 change') : __(':n changes', { n: item.changes.length }) }}</span>
                        <span v-if="item.warnings.length" class="shrink-0 text-xs text-amber-700 dark:text-amber-400!">{{ item.warnings.length === 1 ? __('1 warning') : __(':n warnings', { n: item.warnings.length }) }}</span>
                        <span class="text-gray-400" aria-hidden="true">{{ open[i] ? '▾' : '▸' }}</span>
                    </button>

                    <div v-if="open[i]" class="mt-3 space-y-3 pl-1">
                        <ul v-if="item.errors.length" class="list-disc pl-5 text-sm text-red-700 dark:text-red-400!">
                            <li v-for="e in item.errors" :key="e">{{ e }}</li>
                        </ul>
                        <ul v-if="item.warnings.length" class="list-disc pl-5 text-sm text-amber-700 dark:text-amber-400!">
                            <li v-for="w in item.warnings" :key="w">{{ w }}</li>
                        </ul>
                        <table v-if="item.changes.length" class="w-full table-fixed text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500">
                                    <th class="w-64 pb-1 font-normal">{{ __('Field') }}</th>
                                    <th class="pb-1 font-normal">{{ __('Before') }}</th>
                                    <th class="pb-1 font-normal">{{ __('After') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="change in item.changes" :key="change.field" class="align-top">
                                    <td class="py-1 pr-2 font-mono text-xs">{{ change.field }}</td>
                                    <td class="py-1 pr-2 break-words text-gray-500 line-through decoration-gray-300">{{ change.old ?? '—' }}</td>
                                    <td class="py-1 break-words">{{ change.new ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <a v-if="item.edit_url" :href="item.edit_url" class="inline-block text-sm text-blue-700 hover:underline! dark:text-blue-400!">{{ __('Edit') }} →</a>
                    </div>
                </div>
            </div>
            <div v-else class="px-6 py-10 text-center text-sm text-gray-600 dark:text-gray-400!">
                {{ loading ? __('Loading…') : current.active ? __('Items appear here as they\'re processed.') : filter || search ? __('Nothing matches.') : __('Nothing changed. Unchanged items aren\'t listed.') }}
            </div>
        </Panel>

        <nav v-if="lastPage > 1" class="mt-4 flex items-center justify-between text-sm">
            <Button v-if="page > 1" size="sm" variant="ghost" :text="'← ' + __('Previous')" @click="go(page - 1)" /><span v-else />
            <span class="text-gray-600 dark:text-gray-400!">{{ __(':page of :last (:total items)', { page, last: lastPage, total }) }}</span>
            <Button v-if="page < lastPage" size="sm" variant="ghost" :text="__('Next') + ' →'" @click="go(page + 1)" /><span v-else />
        </nav>

        <Modal v-model:open="confirmUndo" :title="__('Undo this run?')">
            <p class="text-sm">{{ __('Items it created are removed, values it changed are put back, and anything it unpublished or deleted returns. Anything edited on the site since is left as it is.') }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button :text="__('Cancel')" @click="confirmUndo = false" />
                    <Button variant="danger" :text="__('Undo the run')" @click="undo" />
                </div>
            </template>
        </Modal>
    </div>
</template>
