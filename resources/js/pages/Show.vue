<!--
    One feed: how it's set up at a glance, its runs, and what can be done
    with it (run, dry run, pause, webhook, duplicate, delete).
-->
<script>
import Toggle from '../components/Toggle.vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Header, Input, Modal, Panel, Switch, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { ago, duration, errorMessage, statusColours, summary, toast, when } from '../helpers.js';

export default {
    components: { Toggle, Alert, Badge, Button, Dropdown, DropdownItem, DropdownMenu, DropdownSeparator, Head, Header, Input, Link, Modal, Panel, Switch, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows },

    props: {
        feed: { type: Object, required: true },
        runs: { type: Array, required: true },
        pagination: { type: Object, required: true },
        webhookUrl: { type: String, default: null },
        can: { type: Object, required: true },
        icon: { type: String, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        return {
            starting: null,
            runOptions: false,
            options: { force: false, limit: '' },
            token: null,
            tokenUrl: null,
            confirmDelete: false,
            poll: null,
        };
    },

    computed: {
        busy() {
            return Boolean(this.feed.active_run);
        },

        missingEnv() {
            return (this.feed.source.env ?? []).filter((e) => !e.set);
        },
    },

    mounted() {
        this.poll = setInterval(() => {
            if (this.busy) router.reload({ only: ['feed', 'runs'], preserveScroll: true });
        }, 3000);
    },

    beforeUnmount() {
        clearInterval(this.poll);
    },

    methods: {
        ago,
        duration,
        summary,
        when,
        colour: (status) => statusColours[status] ?? 'gray',

        async run(dry, extra = {}) {
            this.starting = dry ? 'dry' : 'run';

            try {
                const { data } = await this.$axios.post(this.urls.run, { dry, ...extra });
                router.visit(data.redirect);
            } catch (error) {
                toast('error', errorMessage(error));
                if (error?.response?.data?.run?.url) router.visit(error.response.data.run.url);
            } finally {
                this.starting = null;
            }
        },

        runWithOptions(dry) {
            this.runOptions = false;
            this.run(dry, { force: this.options.force, limit: this.options.limit ? Number(this.options.limit) : null });
        },

        async post(url, method = 'post') {
            try {
                const { data } = await this.$axios({ url, method });
                toast('success', data.message);
                if (data.redirect) router.visit(data.redirect);
                else router.reload({ preserveScroll: true });
                return data;
            } catch (error) {
                toast('error', errorMessage(error));
            }
        },

        async issueToken() {
            const data = await this.post(this.urls.webhook);
            if (data?.token) {
                this.token = data.token;
                this.tokenUrl = data.url;
            }
        },

        async destroy() {
            this.confirmDelete = false;
            await this.post(this.urls.destroy, 'delete');
        },

        pageUrl(page) {
            return this.urls.self + (page > 1 ? `?page=${page}` : '');
        },
    },
};
</script>

<template>
    <Head :title="feed.title" />

    <div class="mx-auto max-w-6xl">
        <div class="mb-2 text-sm"><Link :href="urls.index" class="text-gray-600 hover:underline! dark:text-gray-400!">← {{ __('Ingest') }}</Link></div>

        <Header :title="feed.title" :icon="icon">
            <Badge v-if="!feed.enabled" :text="__('Paused')" color="gray" />
            <Dropdown v-if="can.manage">
                <DropdownMenu>
                    <DropdownItem :text="feed.enabled ? __('Pause') : __('Turn on')" @click="post(urls.toggle)" />
                    <DropdownItem :text="__('Duplicate')" @click="post(urls.duplicate)" />
                    <DropdownItem :text="__('Download as YAML')" :href="urls.download" />
                    <DropdownSeparator />
                    <DropdownItem :text="__('Delete')" variant="destructive" @click="confirmDelete = true" />
                </DropdownMenu>
            </Dropdown>
            <Button v-if="can.manage" :href="urls.edit" :text="__('Edit')" icon="pencil" />
            <template v-if="can.run && !busy">
                <Button :loading="starting === 'dry'" :text="__('Dry run')" icon="eye" @click="run(true)" />
                <Button variant="primary" :loading="starting === 'run'" :text="__('Run now')" @click="run(false)" />
            </template>
        </Header>

        <Alert v-if="missingEnv.length" variant="error" class="mb-4" :heading="__('Missing settings in .env')" :text="__('This feed needs :names in the server\'s .env file.', { names: missingEnv.map((e) => e.name).join(', ') })" />

        <Alert v-if="busy" variant="default" class="mb-4">
            <div class="flex items-center gap-3">
                <span>{{ __('Running now: :status', { status: feed.active_run.status_label }) }}<template v-if="feed.active_run.total"> · {{ feed.active_run.processed }} / {{ feed.active_run.total }}</template></span>
                <Link :href="feed.active_run.url" class="font-medium hover:underline!">{{ __('Watch') }} →</Link>
            </div>
        </Alert>

        <div class="mb-6 grid gap-4 md:grid-cols-4">
            <Panel class="mb-0!">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('From') }}</div>
                    <div class="mt-1 truncate font-medium">{{ feed.source.label }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ feed.source.format === 'auto' ? __('Format detected') : feed.source.format.toUpperCase() }}</div>
                </div>
            </Panel>
            <Panel class="mb-0!">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Into') }}</div>
                    <div class="mt-1 truncate font-medium">{{ feed.destination.label }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ feed.destination.target }}<template v-if="feed.items !== null"> · {{ __(':n items', { n: feed.items }) }}</template></div>
                </div>
            </Panel>
            <Panel class="mb-0!">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Schedule') }}</div>
                    <div class="mt-1 font-medium">{{ feed.enabled ? feed.schedule : __('Paused') }}</div>
                    <div v-if="feed.next_run" class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Next :when', { when: ago(feed.next_run) }) }}</div>
                </div>
            </Panel>
            <Panel class="mb-0!">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Last run') }}</div>
                    <template v-if="feed.last_run">
                        <div class="mt-1"><Badge :text="feed.last_run.status_label" :color="colour(feed.last_run.status)" size="sm" /></div>
                        <div class="text-sm text-gray-600 dark:text-gray-400!">{{ ago(feed.last_run.finished_at || feed.last_run.created_at) }}</div>
                    </template>
                    <div v-else class="mt-1 font-medium">{{ __('Never') }}</div>
                </div>
            </Panel>
        </div>

        <div v-if="can.run && !busy" class="mb-4 text-sm">
            <button type="button" class="text-blue-700 hover:underline! dark:text-blue-400!" @click="runOptions = !runOptions">{{ runOptions ? '▾' : '▸' }} {{ __('Run with options') }}</button>
            <div v-if="runOptions" class="mt-2 flex flex-wrap items-end gap-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-850!">
                <label class="text-sm">{{ __('Only the first … items') }}<Input v-model="options.limit" type="number" min="1" class="w-32" :placeholder="__('All')" /></label>
                <Toggle v-model="options.force" :label="__('Read the whole feed even if unchanged, and ignore the safety limit')" />
                <Button size="sm" :text="__('Dry run')" @click="runWithOptions(true)" />
                <Button size="sm" variant="primary" :text="__('Run')" @click="runWithOptions(false)" />
            </div>
        </div>

        <Panel :heading="__('Runs')">
            <div v-if="runs.length" class="overflow-x-auto">
                <Table>
                    <TableColumns>
                        <TableColumn>{{ __('Started') }}</TableColumn>
                        <TableColumn>{{ __('Status') }}</TableColumn>
                        <TableColumn>{{ __('What changed') }}</TableColumn>
                        <TableColumn>{{ __('By') }}</TableColumn>
                        <TableColumn class="text-right">{{ __('Took') }}</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="run in runs" :key="run.id">
                            <TableCell>
                                <Link :href="run.url" class="hover:underline!">{{ when(run.created_at) }}</Link>
                                <Badge v-if="run.dry_run" :text="__('Dry run')" color="purple" size="sm" class="ml-2" />
                            </TableCell>
                            <TableCell><Badge :text="run.status_label" :color="colour(run.status)" size="sm" /></TableCell>
                            <TableCell class="text-sm">
                                <span v-if="run.unchanged_feed" class="text-gray-600 dark:text-gray-400!">{{ __('The feed hadn\'t changed') }}</span>
                                <span v-else-if="run.status === 'failed'" class="text-red-700 dark:text-red-400!">{{ run.message }}</span>
                                <span v-else>{{ summary(run.counts) }}</span>
                            </TableCell>
                            <TableCell class="text-sm text-gray-600 dark:text-gray-400!">{{ run.user || __({ schedule: 'Schedule', webhook: 'Webhook', cli: 'Command line', chain: 'Another feed', apply: 'Dry run applied', manual: 'Someone' }[run.trigger] ?? run.trigger) }}</TableCell>
                            <TableCell class="text-right text-sm tabular-nums">{{ duration(run.duration) }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>
            <div v-else class="px-6 py-10 text-center">
                <p class="font-medium">{{ __('No runs yet') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400!">{{ __('Start with a dry run: it shows exactly what would change, and nothing is saved.') }}</p>
            </div>
        </Panel>

        <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
            <Link v-if="pagination.page > 1" :href="pageUrl(pagination.page - 1)" class="hover:underline!">← {{ __('Newer') }}</Link><span v-else />
            <span class="text-gray-600 dark:text-gray-400!">{{ __(':page of :last', { page: pagination.page, last: pagination.last_page }) }}</span>
            <Link v-if="pagination.page < pagination.last_page" :href="pageUrl(pagination.page + 1)" class="hover:underline!">{{ __('Older') }} →</Link><span v-else />
        </nav>

        <Panel v-if="can.manage" :heading="__('Webhook')" class="mt-6">
            <div class="space-y-3 p-4 text-sm">
                <p class="text-gray-600 dark:text-gray-400!">{{ __('Let another system start this feed: a supplier\'s export, a deploy, or a Google Apps Script when a sheet changes. It POSTs to the URL with the token in an Authorization: Bearer header.') }}</p>
                <template v-if="token">
                    <Alert variant="warning" :text="__('Copy the token now. It isn\'t stored and won\'t be shown again.')" />
                    <Input :model-value="tokenUrl" read-only copyable class="font-mono" :aria-label="__('URL')" />
                    <Input :model-value="token" read-only copyable class="font-mono" :aria-label="__('Token')" />
                </template>
                <Input v-else-if="webhookUrl" :model-value="webhookUrl" read-only copyable class="font-mono" :aria-label="__('URL')" />
                <div class="flex gap-2">
                    <Button size="sm" :text="feed.webhook ? __('Make a new token') : __('Turn on the webhook')" @click="issueToken" />
                    <Button v-if="feed.webhook" size="sm" variant="ghost" :text="__('Turn off')" @click="post(urls.webhook, 'delete')" />
                </div>
            </div>
        </Panel>

        <Modal v-model:open="confirmDelete" :title="__('Delete this feed?')">
            <p class="text-sm">{{ __('The content it imported stays on the site; Ingest just stops looking after it. Its run history is kept.') }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button :text="__('Cancel')" @click="confirmDelete = false" />
                    <Button variant="danger" :text="__('Delete feed')" @click="destroy" />
                </div>
            </template>
        </Modal>
    </div>
</template>
