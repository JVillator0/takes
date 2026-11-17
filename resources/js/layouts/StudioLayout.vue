<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import BalancePill from '@/components/takes/BalancePill.vue';
import TakesMark from '@/components/takes/TakesMark.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Toaster } from '@/components/ui/sonner';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCopy } from '@/composables/useCopy';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import type { BalanceSummary, BreadcrumbItem } from '@/types';

/**
 * Customer app shell (P03, P04, P06, P10): one top bar with the project
 * switcher, the balance pill and the user menu, then the page. Settings pages
 * use it too, so the customer never leaves the studio chrome.
 */
type Props = {
    breadcrumbs?: BreadcrumbItem[];
    /** Overrides the signed-in customer's shared balance (e.g. a live-updated one). */
    balance?: BalanceSummary | null;
    showBuyCredits?: boolean;
    /** Full-bleed pages (the workspace) manage their own padding. */
    fluid?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    balance: null,
    showBuyCredits: false,
    fluid: false,
});

const emit = defineEmits<{
    buyCredits: [];
}>();

defineSlots<{
    default?: () => unknown;
    /** Project switcher; defaults to the breadcrumbs. */
    switcher?: () => unknown;
    /** Connection indicator (Live / Reconnecting, spec 5). */
    status?: () => unknown;
    /** Replaces the default balance pill. */
    balance?: () => unknown;
}>();

const page = usePage();
const customer = computed(() => page.props.auth.customer);

/**
 * The pill on every customer page: the page's balance if it passes one,
 * otherwise the shared `auth.customer` strings (renamed only, no money math).
 */
const shellBalance = computed<BalanceSummary | null>(() => {
    if (props.balance) {
        return props.balance;
    }

    const current = customer.value;

    return current
        ? {
              available: current.available,
              availableExact: current.available_exact,
              onHold: current.on_hold,
              onHoldExact: current.on_hold_exact,
          }
        : null;
});
const { t } = useCopy();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <a
            href="#main"
            class="sr-only z-50 rounded-md bg-background px-3 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:ring-[3px] focus:ring-ring/50 print:hidden"
        >
            {{ t('landing.shell.skip_to_content') }}
        </a>

        <header
            class="sticky top-0 z-30 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80 print:hidden"
        >
            <div class="mx-auto flex h-14 w-full items-center gap-3 px-4">
                <Link
                    :href="dashboard()"
                    class="shrink-0 rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <TakesMark compact />
                </Link>

                <div class="hidden min-w-0 flex-1 truncate sm:block">
                    <slot name="switcher">
                        <Breadcrumbs
                            v-if="breadcrumbs.length"
                            :breadcrumbs="breadcrumbs"
                        />
                    </slot>
                </div>

                <div
                    class="ml-auto flex min-w-0 items-center justify-end gap-2"
                >
                    <slot name="status" />
                    <slot name="balance">
                        <BalancePill
                            v-if="shellBalance"
                            :balance="shellBalance"
                        />
                    </slot>
                    <Button
                        v-if="showBuyCredits"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="hidden sm:inline-flex"
                        @click="emit('buyCredits')"
                    >
                        {{ t('billing.buy_credits') }}
                    </Button>

                    <DropdownMenu v-if="customer">
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-9 shrink-0 rounded-full p-0.5"
                                :aria-label="customer.name"
                            >
                                <Avatar
                                    class="size-8 overflow-hidden rounded-full"
                                >
                                    <AvatarFallback
                                        class="rounded-full bg-muted text-xs font-semibold"
                                    >
                                        {{ getInitials(customer.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="customer" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>

        <main
            id="main"
            tabindex="-1"
            :class="[
                'flex w-full flex-1 flex-col outline-none',
                !fluid && 'mx-auto max-w-7xl',
            ]"
        >
            <slot />
        </main>

        <Toaster />
    </div>
</template>
