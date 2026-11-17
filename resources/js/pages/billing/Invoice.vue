<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import TakesMark from '@/components/takes/TakesMark.vue';
import { Button } from '@/components/ui/button';
import { useCopy } from '@/composables/useCopy';
import { show } from '@/routes/billing';
import type { InvoiceDetail } from '@/types';

/**
 * Printable invoice (P09, spec 6): one purchase, one line, no tax. HTML with
 * print styles; "Print / Save as PDF" is the browser's own dialog, no PDF
 * library. The app chrome and the buttons are hidden when printing.
 */
type Props = {
    invoice: InvoiceDetail;
};

const props = defineProps<Props>();

const { t } = useCopy();

setLayoutProps({
    breadcrumbs: [
        { title: t('billing.page_title'), href: show() },
        { title: props.invoice.number, href: props.invoice.href },
    ],
});

function print(): void {
    window.print();
}
</script>

<template>
    <div class="flex flex-col gap-4 px-4 py-6 sm:px-6 print:p-0">
        <Head :title="`${t('billing.invoice.title')} ${invoice.number}`" />

        <div
            class="flex flex-wrap items-center justify-between gap-2 print:hidden"
        >
            <Link
                :href="show({ query: { tab: 'invoices' } })"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                {{ t('billing.invoice.back') }}
            </Link>
            <Button type="button" variant="outline" @click="print">
                <Printer class="size-4" aria-hidden="true" />
                {{ t('billing.invoice.print') }}
            </Button>
        </div>

        <article
            class="mx-auto flex w-full max-w-3xl flex-col gap-8 rounded-lg border bg-background p-6 text-sm sm:p-10 print:max-w-none print:border-0 print:p-0 print:text-black"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <TakesMark />
                <div class="text-right">
                    <h1 class="text-2xl font-semibold">
                        {{ t('billing.invoice.title') }}
                    </h1>
                    <p class="font-medium">{{ invoice.number }}</p>
                    <p class="text-muted-foreground print:text-black">
                        <time :datetime="invoice.issuedAt">
                            {{
                                t('billing.invoice.issued', {
                                    date: invoice.issuedAtLabel,
                                })
                            }}
                        </time>
                        ·
                        {{ t(`billing.invoice_status.${invoice.status}`) }}
                    </p>
                </div>
            </header>

            <section class="grid gap-4 sm:grid-cols-2">
                <div>
                    <h2 class="font-semibold">
                        {{ t('billing.invoice.billed_to') }}
                    </h2>
                    <p>{{ invoice.billedName }}</p>
                    <p class="break-all">{{ invoice.billedEmail }}</p>
                </div>
                <div class="sm:text-right">
                    <h2 class="font-semibold">
                        {{ t('billing.invoice.from') }}
                    </h2>
                    <p>{{ t('billing.invoice.merchant') }}</p>
                    <p>{{ t('billing.invoice.merchant_note') }}</p>
                </div>
            </section>

            <table class="w-full border-separate border-spacing-0 tabular-nums">
                <thead>
                    <tr
                        class="text-left text-xs tracking-wide text-muted-foreground uppercase print:text-black"
                    >
                        <th scope="col" class="border-b px-2 py-2 font-medium">
                            {{ t('billing.invoice.description') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-2 py-2 text-right font-medium"
                        >
                            {{ t('billing.invoice.quantity') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-2 py-2 text-right font-medium max-sm:hidden print:table-cell"
                        >
                            {{ t('billing.invoice.unit') }}
                        </th>
                        <th
                            scope="col"
                            class="border-b px-2 py-2 text-right font-medium"
                        >
                            {{ t('billing.invoice.amount') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th
                            scope="row"
                            class="border-b px-2 py-2 text-left font-normal"
                        >
                            {{ t('billing.purchase_line') }}
                        </th>
                        <td class="border-b px-2 py-2 text-right">
                            {{ invoice.quantity }}
                        </td>
                        <td
                            class="border-b px-2 py-2 text-right max-sm:hidden print:table-cell"
                        >
                            {{ invoice.unitPrice }}
                        </td>
                        <td class="border-b px-2 py-2 text-right">
                            {{ invoice.lineAmount }}
                        </td>
                    </tr>
                    <tr>
                        <th
                            scope="row"
                            colspan="2"
                            class="border-b px-2 py-2 text-left font-normal"
                        >
                            {{ t('billing.invoice.tax') }}
                        </th>
                        <!-- The Unit column: hidden below 640px like its header. -->
                        <td
                            class="border-b px-2 py-2 max-sm:hidden print:table-cell"
                        />
                        <td class="border-b px-2 py-2 text-right">
                            {{ invoice.tax }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <th scope="row" colspan="2" class="px-2 py-2 text-left">
                            {{ t('billing.invoice.total_paid') }}
                        </th>
                        <td class="px-2 py-2 max-sm:hidden print:table-cell" />
                        <td class="px-2 py-2 text-right">
                            {{ invoice.total }}
                        </td>
                    </tr>
                </tfoot>
            </table>

            <footer class="text-xs text-muted-foreground print:text-black">
                <p class="break-all">
                    {{
                        t('billing.invoice.payment', {
                            reference: invoice.reference,
                        })
                    }}
                </p>
                <p>{{ t('billing.invoice.no_expiry') }}</p>
            </footer>
        </article>
    </div>
</template>
