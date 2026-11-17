<script setup lang="ts">
import { useCopy } from '@/composables/useCopy';
import type { BalanceSummary } from '@/types';

type Props = {
    /** Server-formatted, 4 decimals on the pill and exact on hover (D-042). */
    balance: BalanceSummary;
};

defineProps<Props>();

/** Available and On hold at every width (P10); only the word "Available" hides on phones. */
const { t } = useCopy();
</script>

<template>
    <p
        data-slot="balance-pill"
        class="inline-flex h-8 items-center gap-1.5 rounded-full border bg-background px-3 text-sm whitespace-nowrap tabular-nums"
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
            <strong class="font-semibold" :title="balance.availableExact">
                {{ balance.available }}
            </strong>
            <span class="text-muted-foreground">·</span>
            <span class="text-muted-foreground">
                {{ t('billing.on_hold') }}
            </span>
            <span :title="balance.onHoldExact">{{ balance.onHold }}</span>
        </span>
    </p>
</template>
