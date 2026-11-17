<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref, watchEffect } from 'vue';
import ActivityTable from '@/components/billing/ActivityTable.vue';
import BuyCreditsDialog from '@/components/billing/BuyCreditsDialog.vue';
import InvoicesTable from '@/components/billing/InvoicesTable.vue';
import { Button } from '@/components/ui/button';
import { useCopy } from '@/composables/useCopy';
import { show } from '@/routes/billing';
import type {
    BillingActivityPage,
    BillingSummary,
    CreditPack,
    InvoiceSummary,
} from '@/types';

/**
 * Billing (P08, spec 6): Available, On hold, spend this month by feature,
 * credit packs, then Activity and Invoices. A money page, so every amount is
 * the server's exact string (D-042); the pill in the top bar keeps its
 * 4-decimal rounding.
 */
type Props = {
    summary: BillingSummary;
    packs: CreditPack[];
    activity: BillingActivityPage;
    invoices: InvoiceSummary[];
};

const props = defineProps<Props>();

const { t } = useCopy();
const page = usePage();

/** The pack whose purchase dialog is open. */
const selectedPack = ref<CreditPack | null>(null);
const tab = ref<'activity' | 'invoices'>('activity');

watchEffect(() => {
    const customer = page.props.auth.customer;

    setLayoutProps({
        breadcrumbs: [{ title: t('billing.page_title'), href: show() }],
        balance: customer
            ? {
                  available: customer.available,
                  availableExact: props.summary.available,
                  onHold: customer.on_hold,
                  onHoldExact: props.summary.onHold,
              }
            : null,
    });
});

const tabClass =
    'relative -mb-px px-1 py-2 text-sm font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-selected:text-foreground aria-selected:after:absolute aria-selected:after:inset-x-0 aria-selected:after:bottom-0 aria-selected:after:border-b-2 aria-selected:after:border-foreground';
</script>

<template>
    <div class="flex flex-col gap-6 px-4 py-6 sm:px-6">
        <Head :title="t('billing.page_title')" />

        <h1 class="text-2xl font-semibold tracking-tight">
            {{ t('billing.page_title') }}
        </h1>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="flex flex-col gap-1 rounded-lg border p-4">
                <h2
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('billing.available') }}
                </h2>
                <p class="text-3xl font-semibold break-all tabular-nums">
                    {{ summary.available }}
                </p>
                <p class="text-xs text-muted-foreground tabular-nums">
                    {{
                        t('billing.balance_breakdown', {
                            balance: summary.balance,
                            on_hold: summary.onHold,
                        })
                    }}
                </p>
            </article>

            <article class="flex flex-col gap-1 rounded-lg border p-4">
                <h2
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('billing.on_hold_title') }}
                </h2>
                <p class="text-3xl font-semibold break-all tabular-nums">
                    {{ summary.onHold }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('billing.on_hold_hint') }}
                </p>
            </article>

            <article class="flex flex-col gap-1 rounded-lg border p-4">
                <h2
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('billing.spent_this_month') }}
                </h2>
                <p class="text-3xl font-semibold break-all tabular-nums">
                    {{ summary.spent.total }}
                </p>
                <dl
                    class="grid grid-cols-[1fr_auto] gap-x-3 text-xs tabular-nums"
                >
                    <dt class="text-muted-foreground">
                        {{ t('billing.features.images') }}
                    </dt>
                    <dd class="text-right">{{ summary.spent.images }}</dd>
                    <dt class="text-muted-foreground">
                        {{ t('billing.features.ai_suggestions') }}
                    </dt>
                    <dd class="text-right">
                        {{ summary.spent.aiSuggestions }}
                    </dd>
                </dl>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('billing.spent_by_settlement', {
                            month: summary.spent.month,
                        })
                    }}
                </p>
            </article>

            <article class="flex flex-col gap-3 rounded-lg border p-4">
                <h2
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('billing.buy_credits') }}
                </h2>
                <div
                    v-if="page.props.auth.can?.purchaseCredits.allowed"
                    class="flex flex-wrap gap-2"
                >
                    <Button
                        v-for="pack in packs"
                        :key="pack.usd"
                        type="button"
                        variant="outline"
                        :aria-label="
                            t('billing.buy_pack', { amount: pack.amount })
                        "
                        @click="selectedPack = pack"
                    >
                        {{ pack.amount }}
                    </Button>
                </div>
                <p
                    v-else-if="page.props.auth.can?.purchaseCredits.message"
                    class="text-sm text-muted-foreground"
                >
                    {{ page.props.auth.can.purchaseCredits.message }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('billing.packs_hint') }}
                </p>
            </article>
        </section>

        <TabsRoot v-model="tab" class="flex flex-col gap-4">
            <TabsList
                :aria-label="t('billing.tabs.label')"
                class="flex gap-6 overflow-x-auto border-b"
            >
                <TabsTrigger value="activity" :class="tabClass">
                    {{ t('billing.tabs.activity') }}
                </TabsTrigger>
                <TabsTrigger value="invoices" :class="tabClass">
                    {{ t('billing.tabs.invoices') }}
                </TabsTrigger>
            </TabsList>
            <TabsContent value="activity" class="outline-none">
                <ActivityTable :activity="activity" />
            </TabsContent>
            <TabsContent value="invoices" class="outline-none">
                <InvoicesTable :invoices="invoices" />
            </TabsContent>
        </TabsRoot>

        <BuyCreditsDialog :pack="selectedPack" @close="selectedPack = null" />
    </div>
</template>
