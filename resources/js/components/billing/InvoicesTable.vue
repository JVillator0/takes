<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useCopy } from '@/composables/useCopy';
import type { InvoiceSummary } from '@/types';

/**
 * Invoices tab (P09 list, spec 6): one invoice per purchase, exact totals
 * (D-042). The welcome credit and usage are never invoiced (D-022, D-029).
 */
type Props = {
    invoices: InvoiceSummary[];
};

defineProps<Props>();

const { t } = useCopy();
const captionId = useId();
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            role="region"
            tabindex="0"
            :aria-labelledby="captionId"
            class="overflow-x-auto rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <table class="w-full border-separate border-spacing-0 text-sm">
                <caption :id="captionId" class="sr-only">
                    {{
                        t('billing.invoices.caption')
                    }}
                </caption>
                <thead>
                    <tr
                        class="text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th scope="col" class="border-b px-3 py-2 font-medium">
                            {{ t('billing.invoices.number') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-3 py-2 font-medium max-sm:hidden"
                        >
                            {{ t('billing.invoices.issued') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-3 py-2 text-right font-medium"
                        >
                            {{ t('billing.invoices.total') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-3 py-2 font-medium max-sm:hidden"
                        >
                            {{ t('billing.invoices.status') }}
                        </th>
                        <td class="border-b px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="invoices.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            {{ t('billing.invoices.empty') }}
                        </td>
                    </tr>
                    <tr v-for="invoice in invoices" :key="invoice.id">
                        <th
                            scope="row"
                            class="border-b px-3 py-2 text-left font-medium whitespace-nowrap"
                        >
                            {{ invoice.number }}
                        </th>
                        <td
                            class="border-b px-3 py-2 whitespace-nowrap max-sm:hidden"
                        >
                            <time :datetime="invoice.issuedAt">
                                {{ invoice.issuedAtLabel }}
                            </time>
                        </td>
                        <td
                            class="border-b px-3 py-2 text-right whitespace-nowrap tabular-nums"
                        >
                            {{ invoice.total }}
                        </td>
                        <td class="border-b px-3 py-2 max-sm:hidden">
                            <Badge
                                variant="outline"
                                class="border-emerald-600 text-emerald-700 dark:text-emerald-400"
                            >
                                {{
                                    t(
                                        `billing.invoice_status.${invoice.status}`,
                                    )
                                }}
                            </Badge>
                        </td>
                        <td class="border-b px-3 py-2 text-right">
                            <Link
                                :href="invoice.href"
                                class="inline-flex h-8 items-center rounded-md border px-3 text-sm font-medium hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                :aria-label="
                                    t('billing.invoices.view_number', {
                                        number: invoice.number,
                                    })
                                "
                            >
                                {{ t('billing.invoices.view') }}
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-muted-foreground">
            {{ t('billing.invoices.note') }}
        </p>
    </div>
</template>
