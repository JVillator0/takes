<script setup lang="ts">
import { computed, useId } from 'vue';
import TakeImage from '@/components/takes/TakeImage.vue';
import TakeStatusBadge from '@/components/takes/TakeStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { TakeCardData } from '@/types';

type Props = {
    take: TakeCardData;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    open: [id: number];
    cancel: [id: number];
}>();

const { t } = useCopy();
const titleId = useId();

const running = computed(
    () => props.take.phase !== 'done' && props.take.phase !== 'failed',
);

const dimensions = computed(() => props.take.size.replace('x', '×'));

const detail = computed(
    () => props.take.failureMessage ?? props.take.explanation,
);

const moneyClass = computed(() => {
    switch (props.take.phase) {
        case 'done':
            return 'font-semibold text-foreground';
        case 'failed':
            return 'font-medium text-emerald-700 dark:text-emerald-400';
        default:
            return 'font-medium text-muted-foreground';
    }
});
</script>

<template>
    <article
        :aria-labelledby="titleId"
        :data-phase="take.phase"
        :class="
            cn(
                'group relative flex h-full flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs transition-shadow has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50 motion-safe:hover:shadow-md',
                running && 'ring-1 ring-primary/15',
            )
        "
    >
        <TakeImage
            :src="take.imageUrl"
            alt=""
            :phase="take.phase"
            class="aspect-[4/3] border-b"
        />

        <div class="flex flex-1 flex-col gap-1.5 p-3">
            <div
                class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
            >
                <TakeStatusBadge
                    :phase="take.phase"
                    :label="take.statusLabel"
                />
                <span class="text-xs text-muted-foreground tabular-nums">
                    {{ dimensions }}
                </span>
            </div>

            <h3 :id="titleId" class="text-sm leading-snug font-medium">
                <button
                    type="button"
                    class="block w-full cursor-pointer text-left outline-none after:absolute after:inset-0 after:content-['']"
                    @click="emit('open', take.id)"
                >
                    <span class="sr-only">
                        {{ t('takes.card.open', { id: take.id }) }}:
                    </span>
                    <!-- Clamp on a block inside the button; prompts reach 4,000 characters. -->
                    <span class="line-clamp-2 break-words">
                        {{ take.prompt }}
                    </span>
                </button>
            </h3>

            <p
                v-if="take.parentId"
                class="text-xs text-muted-foreground tabular-nums"
            >
                {{ t('takes.card.remix_of', { id: take.parentId }) }}
            </p>

            <!--
                Sits above the stretched open button (relative z-10), so the
                exact amount is reachable by hover and by keyboard (D-042).
            -->
            <TooltipProvider v-if="take.moneyExact" :delay-duration="150">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            :class="
                                cn(
                                    'relative z-10 w-fit cursor-help rounded-sm text-left text-sm tabular-nums underline decoration-muted-foreground/60 decoration-dotted underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    moneyClass,
                                )
                            "
                        >
                            {{ take.moneyLine }}
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="tabular-nums">
                        {{
                            t('takes.card.exact_amount', {
                                amount: take.moneyExact,
                            })
                        }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <p v-else :class="cn('text-sm tabular-nums', moneyClass)">
                {{ take.moneyLine }}
            </p>

            <p v-if="detail" class="text-xs text-muted-foreground">
                {{ detail }}
            </p>

            <div v-if="take.canCancel" class="relative z-10 mt-auto pt-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="emit('cancel', take.id)"
                >
                    {{ t('takes.actions.cancel') }}
                    <span class="sr-only">
                        {{ t('takes.card.title', { id: take.id }) }}
                    </span>
                </Button>
            </div>
        </div>

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{
                t('takes.card.announcement', {
                    id: take.id,
                    status: take.statusLabel,
                })
            }}
        </p>
    </article>
</template>
