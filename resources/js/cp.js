import Feed from './pages/Feed.vue';
import Index from './pages/Index.vue';
import Run from './pages/Run.vue';
import Show from './pages/Show.vue';
import FeedHealth from './widgets/FeedHealth.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('ingest::Index', Index);
    Statamic.$inertia.register('ingest::Show', Show);
    Statamic.$inertia.register('ingest::Feed', Feed);
    Statamic.$inertia.register('ingest::Run', Run);
    Statamic.$components.register('ingest-feed-health', FeedHealth);
});
