<!--
    Dashboard: feeds that need attention, then what runs next.
-->
<script>
import { Badge, Widget } from '@statamic/cms/ui';
import { ago, statusColours } from '../helpers.js';

export default {
    components: { Badge, Widget },

    props: {
        title: { type: String, required: true },
        feeds: { type: Array, required: true },
        attention: { type: Array, required: true },
        schedulerRunning: { type: Boolean, default: false },
        scheduled: { type: Boolean, default: false },
        url: { type: String, required: true },
    },

    methods: {
        ago,
        colour: (s) => statusColours[s] ?? 'gray',
    },
};
</script>

<template>
    <Widget :title="title" :href="url">
        <div class="space-y-3 px-4 py-3 text-sm">
            <p v-if="scheduled && !schedulerRunning" class="text-amber-700 dark:text-amber-400!">{{ __('The scheduler isn\'t running, so scheduled feeds won\'t start.') }}</p>

            <div v-if="attention.length">
                <p class="mb-1 font-medium text-red-700 dark:text-red-400!">{{ __('Needs attention') }}</p>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800!">
                    <li v-for="feed in attention" :key="feed.handle" class="flex items-center justify-between gap-2 py-1.5">
                        <a :href="feed.last_run.url" class="truncate hover:underline!">{{ feed.title }}</a>
                        <Badge :text="feed.last_run.status_label" :color="colour(feed.last_run.status)" size="sm" />
                    </li>
                </ul>
            </div>

            <p v-if="!feeds.length" class="text-gray-500">{{ __('No feeds yet.') }}</p>
            <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800!">
                <li v-for="feed in feeds" :key="feed.handle" class="flex items-baseline justify-between gap-3 py-1.5">
                    <a :href="feed.urls.show" class="truncate font-medium hover:underline!">{{ feed.title }}</a>
                    <span class="shrink-0 text-xs text-gray-500">
                        <template v-if="feed.active_run">{{ __('Running') }}</template>
                        <template v-else-if="!feed.enabled">{{ __('Paused') }}</template>
                        <template v-else-if="feed.next_run">{{ __('Next :when', { when: ago(feed.next_run) }) }}</template>
                        <template v-else-if="feed.last_run">{{ ago(feed.last_run.finished_at || feed.last_run.created_at) }}</template>
                    </span>
                </li>
            </ul>
        </div>
    </Widget>
</template>
