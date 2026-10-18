<!--
    A path into the feed item (formats.*.sku). Suggests paths from the
    preview, and while focused, clicking a path in the preview fills it in.
-->
<script>
import { Input } from '@statamic/cms/ui';

let uid = 0;

export default {
    components: { Input },

    inject: { picker: { default: null } },

    props: {
        modelValue: { type: String, default: '' },
        placeholder: { type: String, default: null },
        scope: { type: String, default: null }, // only paths under this prefix, made relative (rows)
        label: { type: String, default: null },
    },

    emits: ['update:modelValue'],

    data() {
        return { id: `ingest-path-${++uid}` };
    },

    computed: {
        suggestions() {
            const paths = Object.keys(this.picker?.paths ?? {});

            if (!this.scope) return paths.slice(0, 300);

            const prefix = this.scope.replace(/\.\*$/, '') + '.*.';

            return paths.filter((p) => p.startsWith(prefix)).map((p) => p.slice(prefix.length)).slice(0, 300);
        },

        active() {
            return this.picker?.target === this.id;
        },
    },

    methods: {
        focus() {
            if (!this.picker) return;

            this.picker.target = this.id;
            this.picker.scope = this.scope;
            this.picker.set = (path) => this.$emit('update:modelValue', path);
        },
    },
};
</script>

<template>
    <div class="relative">
        <Input
            :model-value="modelValue"
            :placeholder="placeholder ?? __('e.g. title or formats.*.sku')"
            :list="`${id}-list`"
            :aria-label="label ?? __('Path in the feed')"
            class="font-mono"
            :class="{ 'ring-2 ring-blue-300 rounded-lg': active }"
            @update:model-value="$emit('update:modelValue', $event)"
            @focus="focus"
            @focusin="focus"
        />
        <datalist :id="`${id}-list`">
            <option v-for="path in suggestions" :key="path" :value="path" />
        </datalist>
    </div>
</template>
