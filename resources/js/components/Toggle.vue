<!--
    A switch with its label beside it, clickable as one.
-->
<script>
import { Switch } from '@statamic/cms/ui';

let uid = 0;

export default {
    components: { Switch },

    props: {
        modelValue: { type: Boolean, default: false },
        label: { type: String, required: true },
    },

    emits: ['update:modelValue'],

    data() {
        return { id: `ingest-toggle-${++uid}` };
    },
};
</script>

<template>
    <div class="inline-flex items-center gap-2">
        <Switch :id="id" :model-value="!!modelValue" @update:model-value="$emit('update:modelValue', $event)" />
        <label :for="id" class="cursor-pointer text-sm">{{ label }}</label>
    </div>
</template>
