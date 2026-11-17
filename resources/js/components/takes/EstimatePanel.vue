<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { DenialReason, EstimateState, TakeEstimate } from '@/types';

type Props = {
    /** Last valid estimate; kept (dimmed) while a newer one loads (2-02). */
    estimate: TakeEstimate | null;
    state: EstimateState;
    /** Customer's available credits, 4 decimals, for `insufficient_credits` (P05-A). */
    availableDisplay?: string | null;
    /**
     * Why the take is denied (`App\Enums\Auth\DenialReason`). Only
     * `insufficient_credits` adds the balance lines; every other reason shows
     * just its 2-01 sentence (F-17).
     */
    deniedReason?: DenialReason | null;
    /** Denial sentence from the server (`denials.*`, owned by spec 3-01). */
    deniedMessage?: string | null;
    /**
     * Id of the message region (no price yet, denied or error), so the
     * composer can point Generate's aria-describedby at it (one is generated
     * when omitted).
     */
    messageId?: string;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    availableDisplay: null,
    deniedReason: null,
    deniedMessage: null,
    messageId: undefined,
    class: '',
});

const { t } = useCopy();
const titleId = useId();
const fallbackMessageId = useId();
const messageId = computed(() => props.messageId ?? fallbackMessageId);

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
const isInsufficient = computed(
    () => isDenied.value && props.deniedReason === 'insufficient_credits',
);
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
        </div>

        <!--
            Always rendered, so screen readers announce a denial or an error
            when it appears (a region inserted together with its text is not
            announced reliably).
        -->
        <div :id="messageId" aria-live="polite" aria-atomic="true">
            <div v-if="isDenied" class="mt-2 space-y-1.5 text-sm">
                <p v-if="isInsufficient && estimate && availableDisplay">
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
                <p v-if="isInsufficient" class="text-xs text-muted-foreground">
                    {{ t('takes.estimate.max_not_average') }}
                </p>
            </div>

            <p
                v-else-if="state === 'error'"
                class="mt-2 text-sm text-red-700 dark:text-red-300"
            >
                {{ t('takes.estimate.error') }}
            </p>

            <p
                v-else-if="state === 'idle' && !estimate"
                class="text-sm text-muted-foreground"
            >
                {{ t('takes.estimate.idle') }}
            </p>
        </div>

        <div
            v-if="estimate && !isDenied && state !== 'error'"
            class="mt-1.5 space-y-1 text-sm"
        >
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
