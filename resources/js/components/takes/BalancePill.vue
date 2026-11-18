<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCopy } from '@/composables/useCopy';
import type { BalanceSummary } from '@/types';

type Props = {
    /** Server-formatted, 4 decimals on the pill and exact in the tooltip (D-042). */
    balance: BalanceSummary;
    /**
     * On the workspace (2-02: "clicking it opens the In progress filter"):
     * applies the filter in place, so the composer draft, the drawer and the
     * live subscription stay. Wins over `holdsHref`.
     */
    holdsAction?: (() => void) | null;
    /** Elsewhere: a link to the projects list (its cards carry the in-progress badges). */
    holdsHref?: string | null;
    /** Screen-reader text saying where the click leads (2-01, one per destination). */
    holdsLabel?: string | null;
};

const props = withDefaults(defineProps<Props>(), {
    holdsAction: null,
    holdsHref: null,
    holdsLabel: null,
});

/**
 * Available and On hold at every width (P10); only the word "Available" hides
 * on phones. The pill is a focusable tooltip trigger, so the exact amounts are
 * reachable by hover, keyboard and screen reader alike; with a holds action or
 * link it also leads to the takes that hold the amount.
 */
const { t } = useCopy();

const asLink = computed(() => !props.holdsAction && Boolean(props.holdsHref));
const leads = computed(() => Boolean(props.holdsAction) || asLink.value);

const onClick = (): void => {
    props.holdsAction?.();
};
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <component
                    :is="asLink ? Link : 'button'"
                    v-bind="
                        asLink
                            ? { href: holdsHref, preserveScroll: true }
                            : { type: 'button' }
                    "
                    @click="onClick"
                    data-slot="balance-pill"
                    :class="[
                        'inline-flex h-8 min-w-0 items-center gap-1.5 rounded-full border bg-background px-3 text-sm whitespace-nowrap tabular-nums outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        leads
                            ? 'cursor-pointer hover:bg-accent'
                            : 'cursor-help',
                    ]"
                >
                    <span class="sr-only">
                        {{
                            t('billing.balance_label', {
                                available: balance.available,
                                on_hold: balance.onHold,
                            })
                        }}
                        <template v-if="leads && holdsLabel">
                            {{ holdsLabel }}
                        </template>
                    </span>
                    <span aria-hidden="true" class="contents">
                        <span class="hidden text-muted-foreground sm:inline">
                            {{ t('billing.available') }}
                        </span>
                        <strong class="font-semibold">
                            {{ balance.available }}
                        </strong>
                        <span class="text-muted-foreground">·</span>
                        <span class="text-muted-foreground">
                            {{ t('billing.on_hold') }}
                        </span>
                        <span>{{ balance.onHold }}</span>
                    </span>
                </component>
            </TooltipTrigger>
            <TooltipContent class="tabular-nums">
                {{
                    t('billing.balance_exact', {
                        available: balance.availableExact,
                        on_hold: balance.onHoldExact,
                    })
                }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
