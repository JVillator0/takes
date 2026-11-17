<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Hourglass,
    LoaderCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { TakePhase } from '@/types';

type Props = {
    phase: TakePhase;
    /** Translated label (2-01). The badge never relies on colour alone. */
    label: string;
    class?: string;
};

const props = defineProps<Props>();

const tones: Record<TakePhase, string> = {
    submitting:
        'border-border bg-background text-foreground [&>svg]:text-muted-foreground',
    waiting_for_capacity:
        'border-dashed border-amber-500 bg-amber-50 text-amber-900 dark:border-amber-400/70 dark:bg-amber-950/60 dark:text-amber-200',
    queued: 'border-border bg-muted text-foreground [&>svg]:text-muted-foreground',
    processing:
        'border-border bg-background text-foreground [&>svg]:text-muted-foreground',
    done: 'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:border-emerald-400/40 dark:bg-emerald-950/60 dark:text-emerald-200',
    failed: 'border-red-600/40 bg-red-50 text-red-800 dark:border-red-400/40 dark:bg-red-950/60 dark:text-red-200',
};

const icon = computed(() => {
    switch (props.phase) {
        case 'submitting':
        case 'processing':
            return LoaderCircle;
        case 'waiting_for_capacity':
            return Hourglass;
        case 'queued':
            return CircleDashed;
        case 'done':
            return CircleCheck;
        default:
            return CircleX;
    }
});

const spins = computed(
    () => props.phase === 'submitting' || props.phase === 'processing',
);
</script>

<template>
    <span
        data-slot="take-status-badge"
        :data-phase="phase"
        :class="
            cn(
                'inline-flex w-fit shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap [&>svg]:size-3.5',
                tones[phase],
                props.class,
            )
        "
    >
        <component
            :is="icon"
            aria-hidden="true"
            :class="{ 'motion-safe:animate-spin': spins }"
        />
        {{ label }}
    </span>
</template>
