<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
     * Where On hold leads (2-02: "clicking it opens the In progress filter"):
     * the workspace's In progress filter, or the projects list with its
     * in-progress badges. Null keeps the pill a plain tooltip trigger.
     */
    holdsHref?: string | null;
};

withDefaults(defineProps<Props>(), { holdsHref: null });

/**
 * Available and On hold at every width (P10); only the word "Available" hides
 * on phones. The pill is a focusable tooltip trigger, so the exact amounts are
 * reachable by hover, keyboard and screen reader alike; with `holdsHref` it is
 * also the link to the takes that hold the amount.
 */
const { t } = useCopy();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <component
                    :is="holdsHref ? Link : 'button'"
                    v-bind="
                        holdsHref
                            ? { href: holdsHref, preserveScroll: true }
                            : { type: 'button' }
                    "
                    data-slot="balance-pill"
                    :class="[
                        'inline-flex h-8 min-w-0 items-center gap-1.5 rounded-full border bg-background px-3 text-sm whitespace-nowrap tabular-nums outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        holdsHref
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
                        <template v-if="holdsHref">
                            {{ t('billing.on_hold_link') }}
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
