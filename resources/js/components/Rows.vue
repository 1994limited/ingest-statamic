<!--
    Editable name/value rows: headers, query parameters, a value map.
-->
<script>
import { Button, Input } from '@statamic/cms/ui';

export default {
    components: { Button, Input },

    props: {
        modelValue: { type: Array, default: () => [] },
        nameKey: { type: String, default: 'name' },
        valueKey: { type: String, default: 'value' },
        namePlaceholder: { type: String, default: null },
        valuePlaceholder: { type: String, default: null },
        addText: { type: String, default: null },
    },

    emits: ['update:modelValue'],

    methods: {
        update(index, key, value) {
            const rows = this.modelValue.map((row) => ({ ...row }));
            rows[index][key] = value;
            this.$emit('update:modelValue', rows);
        },

        add() {
            this.$emit('update:modelValue', [...this.modelValue, { [this.nameKey]: '', [this.valueKey]: '' }]);
        },

        remove(index) {
            this.$emit('update:modelValue', this.modelValue.filter((_, i) => i !== index));
        },
    },
};
</script>

<template>
    <div class="space-y-2">
        <div v-for="(row, index) in modelValue" :key="index" class="flex items-center gap-2">
            <Input :model-value="row[nameKey]" :placeholder="namePlaceholder" class="w-1/3" @update:model-value="update(index, nameKey, $event)" />
            <Input :model-value="row[valueKey]" :placeholder="valuePlaceholder" class="grow" @update:model-value="update(index, valueKey, $event)" />
            <Button size="sm" variant="ghost" icon="trash" :aria-label="__('Remove')" @click="remove(index)" />
        </div>
        <Button size="sm" icon="plus" :text="addText ?? __('Add')" @click="add" />
    </div>
</template>
