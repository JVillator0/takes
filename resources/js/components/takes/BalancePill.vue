<script setup lang="ts">
import { useCopy } from '@/composables/useCopy';
import type { BalanceSummary } from '@/types';

type Props = {
    /** Server-formatted, 4 decimals on the pill and exact on hover (D-042). */
    balance: BalanceSummary;
};

defineProps<Props>();

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
                    on_hold: balance.onHold ?? '$0.0000',
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
            <template v-if="balance.onHold">
                <span class="hidden text-muted-foreground md:inline">·</span>
                <span class="hidden text-muted-foreground md:inline">
                    {{ t('billing.on_hold') }}
                </span>
                <span
                    class="hidden md:inline"
                    :title="balance.onHoldExact ?? undefined"
                >
                    {{ balance.onHold }}
                </span>
            </template>
        </span>
    </p>
</template>
