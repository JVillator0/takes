<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useId } from 'vue';
import { useCopy } from '@/composables/useCopy';
import { show } from '@/routes/billing';
import type {
    BillingActivityPage,
    BillingActivityRow,
    BillingActivityStatus,
} from '@/types';

/**
 * Activity (P08, spec 6): one line per usage (on hold, charged, not charged)
 * and the purchases, credits and adjustments. Exact amounts (D-042) and the
 * running balance, which excludes amounts on hold. Below the container's
 * `2xl` width the rows stack as cards, each cell labelled: the table and its
 * body become blocks too, so a card is as wide as the screen (P10, 390 px)
 * and a long description truncates instead of pushing the values out.
 */
type Props = {
    activity: BillingActivityPage;
};

defineProps<Props>();

const { t } = useCopy();
const captionId = useId();

function kindLabel(row: BillingActivityRow): string {
    return row.kind === 'purchase'
        ? t('billing.purchase_line')
        : t(`billing.activity.kind.${row.kind}`);
}

const statusClass: Record<BillingActivityStatus, string> = {
    on_hold: 'text-amber-700 dark:text-amber-400',
    charged: 'text-emerald-700 dark:text-emerald-400',
    not_charged: 'text-muted-foreground',
};

/** Shared by every cell: in the stacked layout the label comes first. */
const cell =
    'px-3 py-2 align-top @max-2xl:flex @max-2xl:w-full @max-2xl:min-w-0 @max-2xl:justify-between @max-2xl:gap-4 @max-2xl:px-0 @max-2xl:py-1 @max-2xl:before:shrink-0 @max-2xl:before:font-medium @max-2xl:before:text-muted-foreground @max-2xl:before:content-[attr(data-label)]';
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            role="region"
            tabindex="0"
            :aria-labelledby="captionId"
            class="@container overflow-x-auto rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <table
                class="w-full border-separate border-spacing-0 text-sm @max-2xl:block"
            >
                <caption :id="captionId" class="sr-only">
                    {{
                        t('billing.activity.caption')
                    }}
                </caption>
                <thead class="@max-2xl:hidden">
                    <tr
                        class="text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <th scope="col" class="border-b px-3 py-2 font-medium">
                            {{ t('billing.activity.date') }}
                        </th>
                        <th scope="col" class="border-b px-3 py-2 font-medium">
                            {{ t('billing.activity.description') }}
                        </th>
                        <th scope="col" class="border-b px-3 py-2 font-medium">
                            {{ t('billing.activity.status') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-3 py-2 text-right font-medium"
                        >
                            {{ t('billing.activity.amount') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-3 py-2 text-right font-medium"
                        >
                            {{ t('billing.activity.balance') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="@max-2xl:block">
                    <tr v-if="activity.rows.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            {{ t('billing.activity.empty') }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in activity.rows"
                        :key="row.key"
                        class="@max-2xl:mb-3 @max-2xl:block @max-2xl:rounded-md @max-2xl:border @max-2xl:p-3 [&>*]:border-b @max-2xl:[&>*]:border-0"
                    >
                        <td
                            :class="[cell, 'whitespace-nowrap tabular-nums']"
                            :data-label="t('billing.activity.date')"
                        >
                            <time :datetime="row.occurredAt">
                                {{ row.occurredAtLabel }}
                            </time>
                        </td>
                        <th
                            scope="row"
                            :class="[cell, 'text-left font-normal']"
                            :data-label="t('billing.activity.description')"
                        >
                            <span
                                class="flex min-w-0 flex-col @max-2xl:items-end @max-2xl:text-right"
                            >
                                <span class="font-medium">
                                    {{ kindLabel(row) }}
                                </span>
                                <Link
                                    v-if="row.detail && row.takeHref"
                                    :href="row.takeHref"
                                    class="max-w-full truncate text-muted-foreground underline-offset-4 hover:underline @2xl:max-w-[40ch]"
                                    :title="t('billing.activity.open_take')"
                                >
                                    {{ row.detail }}
                                </Link>
                                <span
                                    v-else-if="row.detail"
                                    class="max-w-full truncate text-muted-foreground @2xl:max-w-[40ch]"
                                >
                                    {{ row.detail }}
                                </span>
                                <Link
                                    v-if="row.invoice"
                                    :href="row.invoice.href"
                                    class="text-muted-foreground underline underline-offset-4"
                                >
                                    {{
                                        t('billing.activity.invoice', {
                                            number: row.invoice.number,
                                        })
                                    }}
                                </Link>
                            </span>
                        </th>
                        <td
                            :class="[cell, '@max-2xl:text-right']"
                            :data-label="t('billing.activity.status')"
                        >
                            <span
                                v-if="row.status"
                                :class="statusClass[row.status]"
                            >
                                {{
                                    t(
                                        `billing.activity.status_label.${row.status}`,
                                    )
                                }}
                                <span
                                    v-if="
                                        row.status === 'charged' &&
                                        row.maxCharge
                                    "
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{
                                        t('billing.activity.was_held', {
                                            amount: row.maxCharge,
                                        })
                                    }}
                                </span>
                            </span>
                        </td>
                        <td
                            :class="[
                                cell,
                                'text-right whitespace-nowrap tabular-nums',
                            ]"
                            :data-label="t('billing.activity.amount')"
                        >
                            <span v-if="row.amount !== null">
                                {{ row.amount }}
                            </span>
                            <span
                                v-else-if="row.maxCharge"
                                class="text-amber-700 dark:text-amber-400"
                            >
                                {{
                                    t('billing.activity.up_to', {
                                        amount: row.maxCharge,
                                    })
                                }}
                            </span>
                        </td>
                        <td
                            :class="[
                                cell,
                                'text-right whitespace-nowrap tabular-nums',
                            ]"
                            :data-label="t('billing.activity.balance')"
                        >
                            {{ row.balance }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p class="text-muted-foreground">
                {{ t('billing.balance_footnote') }}
            </p>
            <nav
                v-if="activity.page > 1 || activity.hasMore"
                class="flex gap-3"
                :aria-label="t('billing.tabs.activity')"
            >
                <Link
                    v-if="activity.page > 1"
                    :href="show({ query: { page: activity.page - 1 } })"
                    :only="['activity']"
                    preserve-scroll
                    preserve-state
                    class="underline underline-offset-4"
                >
                    {{ t('billing.activity.newer') }}
                </Link>
                <Link
                    v-if="activity.hasMore"
                    :href="show({ query: { page: activity.page + 1 } })"
                    :only="['activity']"
                    preserve-scroll
                    preserve-state
                    class="underline underline-offset-4"
                >
                    {{ t('billing.activity.earlier') }}
                </Link>
            </nav>
        </div>
    </div>
</template>
