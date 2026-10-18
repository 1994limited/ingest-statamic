<!--
    Tools → Ingest: every feed with its source, destination, schedule, the
    last run and the next one. Feeds with problems sort first.
-->
<script>
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Header, Input, Panel, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { ago, errorMessage, statusColours, summary, toast } from '../helpers.js';

export default {
    components: { Alert, Badge, Button, Head, Header, Input, Link, Panel, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows },

    props: {
        feeds: { type: Array, required: true },
        schedulerRunning: { type: Boolean, default: false },
        scheduled: { type: Boolean, default: false },
        can: { type: Object, required: true },
        icon: { type: String, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        return { query: '', starting: null, poll: null };
    },

    computed: {
        shown() {
            const q = this.query.trim().toLowerCase();
            const rank = (feed) => (['failed', 'aborted'].includes(feed.last_run?.status) ? 0 : feed.last_run?.status === 'completed_with_errors' ? 1 : 2);

            return this.feeds
                .filter((feed) => !q || `${feed.title} ${feed.handle} ${feed.source.label} ${feed.destination.target ?? ''}`.toLowerCase().includes(q))
                .sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title));
        },

        active() {
            return this.feeds.some((feed) => feed.active_run);
        },
    },

    mounted() {
        // Keep progress fresh while something is running.
        this.poll = setInterval(() => {
            if (this.active) router.reload({ only: ['feeds'], preserveScroll: true });
        }, 3000);
    },

    beforeUnmount() {
        clearInterval(this.poll);
    },

    methods: {
        ago,
        summary,
        colour: (status) => statusColours[status] ?? 'gray',

        async run(feed, dry) {
            this.starting = feed.handle + (dry ? ':dry' : '');

            try {
                const { data } = await this.$axios.post(feed.urls.run, { dry });
                router.visit(data.redirect);
            } catch (error) {
                toast('error', errorMessage(error));
                if (error?.response?.data?.run?.url) router.visit(error.response.data.run.url);
            } finally {
                this.starting = null;
            }
        },
    },
};
</script>

<template>
    <Head :title="__('Ingest')" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__('Ingest')" :icon="icon">
            <Button v-if="urls.settings" :href="urls.settings" :text="__('Settings')" icon="cog" />
            <Button v-if="can.manage" :href="urls.create" variant="primary" :text="__('Create Feed')" />
        </Header>

        <Alert v-if="scheduled && !schedulerRunning" variant="warning" class="mb-4" :heading="__('Scheduled feeds aren\'t running')">
            {{ __('Laravel\'s scheduler hasn\'t run in the last few minutes, so feeds won\'t start on their own. Add `* * * * * php artisan schedule:run` to the server\'s cron.') }}
        </Alert>

        <div v-if="feeds.length > 5" class="mb-3">
            <Input v-model="query" icon="magnifying-glass" :placeholder="__('Search feeds')" clearable :aria-label="__('Search')" />
        </div>

        <Panel>
            <div v-if="shown.length" class="overflow-x-auto">
                <Table>
                    <TableColumns>
                        <TableColumn>{{ __('Feed') }}</TableColumn>
                        <TableColumn>{{ __('Into') }}</TableColumn>
                        <TableColumn>{{ __('Schedule') }}</TableColumn>
                        <TableColumn>{{ __('Last run') }}</TableColumn>
                        <TableColumn class="text-right"><span class="sr-only">{{ __('Actions') }}</span></TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="feed in shown" :key="feed.handle">
                            <TableCell>
                                <Link :href="feed.urls.show" class="font-medium hover:underline!">{{ feed.title }}</Link>
                                <div class="text-sm text-gray-600 dark:text-gray-400!">
                                    {{ feed.source.label }}<span v-if="feed.source.format !== 'auto'"> · {{ feed.source.format.toUpperCase() }}</span>
                                </div>
                            </TableCell>
                            <TableCell>
                                {{ feed.destination.label }}
                                <div v-if="feed.destination.target" class="text-sm text-gray-600 dark:text-gray-400!">{{ feed.destination.target }}</div>
                            </TableCell>
                            <TableCell>
                                <Badge v-if="!feed.enabled" :text="__('Paused')" color="gray" size="sm" />
                                <template v-else>
                                    {{ feed.schedule }}
                                    <div v-if="feed.next_run" class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Next :when', { when: ago(feed.next_run) }) }}</div>
                                </template>
                            </TableCell>
                            <TableCell>
                                <template v-if="feed.active_run">
                                    <Link :href="feed.active_run.url" class="hover:underline!">
                                        <Badge :text="feed.active_run.status_label" color="blue" size="sm" />
                                    </Link>
                                    <div v-if="feed.active_run.total" class="text-sm text-gray-600 dark:text-gray-400!">{{ feed.active_run.processed }} / {{ feed.active_run.total }}</div>
                                </template>
                                <template v-else-if="feed.last_run">
                                    <Link :href="feed.last_run.url" class="hover:underline!">
                                        <Badge :text="feed.last_run.status_label" :color="colour(feed.last_run.status)" size="sm" />
                                    </Link>
                                    <div class="text-sm text-gray-600 dark:text-gray-400!">
                                        {{ feed.last_run.unchanged_feed ? __('Feed unchanged') : summary(feed.last_run.counts) }} · {{ ago(feed.last_run.finished_at || feed.last_run.created_at) }}
                                    </div>
                                </template>
                                <span v-else class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Never run') }}</span>
                            </TableCell>
                            <TableCell class="text-right whitespace-nowrap">
                                <template v-if="can.run && !feed.active_run">
                                    <Button size="sm" variant="ghost" :loading="starting === feed.handle + ':dry'" :text="__('Dry run')" @click="run(feed, true)" />
                                    <Button size="sm" :loading="starting === feed.handle" :text="__('Run')" @click="run(feed, false)" />
                                </template>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <div v-else-if="query" class="px-6 py-10 text-center text-gray-600 dark:text-gray-400!">{{ __('No feeds match.') }}</div>

            <div v-else class="px-6 py-12 text-center">
                <p class="text-lg font-medium">{{ __('Keep Statamic in step with your feeds') }}</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-gray-600 dark:text-gray-400!">
                    {{ __('Point Ingest at a supplier catalogue, an API, a spreadsheet or an RSS feed. It maps the items onto your blueprints, runs on a schedule, only changes what changed, and lets you preview and undo every run.') }}
                </p>
                <Button v-if="can.manage" class="mt-5" :href="urls.create" variant="primary" :text="__('Create your first feed')" />
            </div>
        </Panel>
    </div>
</template>
