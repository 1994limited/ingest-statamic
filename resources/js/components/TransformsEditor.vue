<!--
    The transforms a value goes through, in order: trim, replace, math…
-->
<script>
import { Button, Input, Select } from '@statamic/cms/ui';

const ARGS = () => ({
    replace: [__('Find'), __('Replace with')],
    regex_replace: [__('Pattern'), __('Replace with')],
    prepend: [__('Text before')],
    append: [__('Text after')],
    truncate: [__('Length'), __('Ending')],
    split: [__('Separator')],
    join: [__('Separator')],
    nth: [__('Position (from 0)')],
    pluck: [__('Path')],
    default: [__('Value')],
    math: [__('Operation, e.g. * 1.2')],
    round: [__('Decimal places')],
    money: [__('Decimal places')],
    date: [__('Format in (optional)'), __('Timezone'), __('Format out')],
    absolute_url: [__('Base URL (optional)')],
    modifier: [__('Statamic modifier'), __('Argument')],
    trim: [__('Characters (optional)')],
});

export default {
    components: { Button, Input, Select },

    inject: { options: { default: () => ({}) } },

    props: {
        modelValue: { type: Array, default: () => [] },
    },

    emits: ['update:modelValue'],

    computed: {
        rows() {
            return this.modelValue.map((t) => (typeof t === 'string' ? { name: t, args: [] } : { name: t?.name ?? '', args: [...(t?.args ?? [])] }));
        },

        names() {
            return (this.options.transforms ?? []).map((name) => ({ value: name, label: name.replaceAll('_', ' ') }));
        },
    },

    methods: {
        args: (name) => ARGS()[name] ?? [],

        emit(rows) {
            this.$emit('update:modelValue', rows.map((r) => (r.args.some((a) => a !== '' && a !== null && a !== undefined) ? r : r.name)));
        },

        set(index, key, value, arg = null) {
            const rows = this.rows.map((r) => ({ ...r, args: [...r.args] }));
            if (key === 'name') {
                rows[index].name = value;
                rows[index].args = [];
            } else {
                rows[index].args[arg] = value;
            }
            this.emit(rows);
        },

        add() {
            this.emit([...this.rows, { name: 'trim', args: [] }]);
        },

        remove(index) {
            this.emit(this.rows.filter((_, i) => i !== index));
        },

        move(index, by) {
            const rows = [...this.rows];
            const [row] = rows.splice(index, 1);
            rows.splice(index + by, 0, row);
            this.emit(rows);
        },
    },
};
</script>

<template>
    <div class="space-y-2">
        <div v-for="(row, index) in rows" :key="index" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-850!">
            <span class="w-5 text-center text-xs text-gray-500">{{ index + 1 }}</span>
            <div class="w-44">
                <Select :model-value="row.name" :options="names" option-label="label" option-value="value" :aria-label="__('Transform')" @update:model-value="set(index, 'name', $event)" />
            </div>
            <Input
                v-for="(label, arg) in args(row.name)"
                :key="arg"
                :model-value="row.args[arg] ?? ''"
                :placeholder="label"
                class="min-w-32 grow"
                @update:model-value="set(index, 'arg', $event, arg)"
            />
            <div class="ml-auto flex">
                <Button size="sm" variant="ghost" icon="chevron-up" :disabled="index === 0" :aria-label="__('Earlier')" @click="move(index, -1)" />
                <Button size="sm" variant="ghost" icon="chevron-down" :disabled="index === rows.length - 1" :aria-label="__('Later')" @click="move(index, 1)" />
                <Button size="sm" variant="ghost" icon="trash" :aria-label="__('Remove')" @click="remove(index)" />
            </div>
        </div>
        <Button size="sm" icon="plus" :text="__('Add a transform')" @click="add" />
    </div>
</template>
