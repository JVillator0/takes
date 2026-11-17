<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { EstimateState, TakeEstimate } from '@/types';

type Props = {
    /** Last valid estimate; kept (dimmed) while a newer one loads (2-02). */
    estimate: TakeEstimate | null;
    state: EstimateState;
    /** Customer's available credits, 4 decimals, for the denied state (P05-A). */
    availableDisplay?: string | null;
    /** Denial sentence from the server (`denials.*`, owned by spec 3-01). */
    deniedMessage?: string | null;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    availableDisplay: null,
    deniedMessage: null,
    class: '',
});

const { t } = useCopy();
const titleId = useId();

const range = computed(() =>
    props.estimate
        ? t('takes.estimate.range', {
              min: props.estimate.min_display,
              max: props.estimate.max_display,
          })
        : null,
);

const isLoading = computed(() => props.state === 'loading');
const isDenied = computed(() => props.state === 'denied');
</script>

<template>
    <section
        :aria-labelledby="titleId"
        :aria-busy="isLoading"
        :class="
            cn(
                'rounded-lg border border-dashed p-3',
                isDenied
                    ? 'border-red-600/50 bg-red-50/60 dark:border-red-400/40 dark:bg-red-950/30'
                    : 'bg-muted/40',
                props.class,
            )
        "
    >
        <div class="flex items-baseline justify-between gap-2">
            <h3
                :id="titleId"
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('takes.estimate.title') }}
            </h3>
            <span class="text-xs text-muted-foreground">
                {{
                    isLoading
                        ? t('takes.estimate.loading')
                        : t('takes.estimate.updates')
                }}
            </span>
        </div>

        <div aria-live="polite" aria-atomic="true" class="mt-1">
            <p
                v-if="range"
                :class="[
                    'text-2xl font-semibold tracking-tight tabular-nums transition-opacity',
                    isLoading && 'opacity-50',
                ]"
            >
                {{ range }}
            </p>
            <Skeleton v-else-if="isLoading" class="h-8 w-48" />
            <p v-else class="text-sm text-muted-foreground">
                {{ t('takes.estimate.idle') }}
            </p>
        </div>

        <div v-if="isDenied" class="mt-2 space-y-1.5 text-sm">
            <p v-if="estimate && availableDisplay">
                {{
                    t('takes.estimate.insufficient', {
                        max: estimate.max_display,
                        available: availableDisplay,
                    })
                }}
            </p>
            <p
                v-if="deniedMessage"
                class="flex gap-1.5 font-medium text-red-700 dark:text-red-300"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ deniedMessage }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ t('takes.estimate.max_not_average') }}
            </p>
        </div>

        <p
            v-else-if="state === 'error'"
            class="mt-2 text-sm text-red-700 dark:text-red-300"
        >
            {{ t('takes.estimate.error') }}
        </p>

        <div v-else-if="estimate" class="mt-1.5 space-y-1 text-sm">
            <p>
                {{ t('takes.estimate.pay_exact') }}
                <strong class="font-semibold">
                    {{ t('takes.estimate.failed_free') }}
                </strong>
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    t('takes.estimate.hold_note', {
                        amount: estimate.max_display,
                    })
                }}
            </p>
        </div>
    </section>
</template>
