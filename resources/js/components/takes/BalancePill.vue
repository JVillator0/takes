<script setup lang="ts">
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
};

defineProps<Props>();

/**
 * Available and On hold at every width (P10); only the word "Available" hides
 * on phones. The pill is a focusable tooltip trigger, so the exact amounts are
 * reachable by hover, keyboard and screen reader alike. Spec 4 can make On
 * hold a link to the workspace's In progress filter (2-02) once that route
 * exists; until then the pill has no click action.
 */
const { t } = useCopy();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    data-slot="balance-pill"
                    class="inline-flex h-8 min-w-0 cursor-help items-center gap-1.5 rounded-full border bg-background px-3 text-sm whitespace-nowrap tabular-nums outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <span class="sr-only">
                        {{
                            t('billing.balance_label', {
                                available: balance.available,
                                on_hold: balance.onHold,
                            })
                        }}
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
                </button>
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
