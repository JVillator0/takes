<script setup lang="ts">
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { TakeFilter, TakeFilterCounts } from '@/types';

type Props = {
    counts: TakeFilterCounts;
};

defineProps<Props>();

/** The active filter; In progress groups the four running states (2-02). */
const filter = defineModel<TakeFilter>({ default: 'all' });

const { t } = useCopy();

const filters: TakeFilter[] = ['all', 'in_progress', 'done', 'failed'];
</script>

<template>
    <div
        role="group"
        :aria-label="t('takes.filter.label')"
        class="inline-flex max-w-full overflow-x-auto rounded-lg border bg-background p-0.5"
    >
        <button
            v-for="option in filters"
            :key="option"
            type="button"
            :aria-pressed="filter === option"
            :class="
                cn(
                    'inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    filter === option
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                )
            "
            @click="filter = option"
        >
            {{ t(`takes.filter.${option}`) }}
            <span class="tabular-nums opacity-80">{{ counts[option] }}</span>
        </button>
    </div>
</template>
