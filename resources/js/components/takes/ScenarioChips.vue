<script setup lang="ts">
import { useId } from 'vue';
import { useCopy } from '@/composables/useCopy';
import type { ScenarioKeyword } from '@/types';

type Props = {
    disabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    add: [keyword: string];
}>();

const { t } = useCopy();
const labelId = useId();

/** Exactly the D-044 list; `[overloaded]` stays a typed keyword. */
const scenarios: ScenarioKeyword[] = ['fail', 'slow', 'duplicate', 'blocked'];
</script>

<template>
    <div
        role="group"
        :aria-labelledby="labelId"
        class="flex flex-wrap items-center gap-1.5"
    >
        <span :id="labelId" class="mr-0.5 text-xs text-muted-foreground">
            {{ t('takes.scenarios.title') }}
        </span>
        <button
            v-for="scenario in scenarios"
            :key="scenario"
            type="button"
            :disabled="disabled"
            :title="t(`takes.scenarios.${scenario}`)"
            :aria-label="t('takes.scenarios.add', { keyword: `[${scenario}]` })"
            class="rounded-full border bg-background px-2 py-0.5 font-mono text-xs text-foreground transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50"
            @click="emit('add', `[${scenario}]`)"
        >
            [{{ scenario }}]
        </button>
    </div>
</template>
