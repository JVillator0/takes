<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BuyCreditsDialog from '@/components/billing/BuyCreditsDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useBuyCredits } from '@/composables/useBuyCredits';
import { useCopy } from '@/composables/useCopy';
import type { CreditPack } from '@/types';

/**
 * Buy credits from any customer page (D-026, P05-A): pick one of the fixed
 * packs, then the billing page's purchase dialog (spec 6) takes over, so
 * there is one purchase flow: its key is minted per attempt, an unknown
 * outcome is never reported as "not charged", and the success toast links
 * the invoice. Amounts arrive formatted from the server (F-12).
 */
const page = usePage();
const { t } = useCopy();
const { isOpen, opener, close } = useBuyCredits();

const packs = computed<CreditPack[]>(() => page.props.creditPacks ?? []);
const selected = ref<CreditPack | null>(null);

/*
 * Dismissing the pack chooser without a pack returns focus to the opener;
 * after a pack, the purchase dialog does that on its own close (F-20).
 */
const onChooserCloseAutoFocus = (event: Event): void => {
    if (selected.value === null && opener.value?.isConnected) {
        event.preventDefault();
        opener.value.focus();
    }
};

const choose = (pack: CreditPack): void => {
    close();
    selected.value = pack;
};
</script>

<template>
    <div>
        <Dialog
            :open="isOpen"
            @update:open="(open: boolean) => (open ? undefined : close())"
        >
            <DialogContent
                class="sm:max-w-md"
                @close-auto-focus="onChooserCloseAutoFocus"
            >
                <DialogHeader>
                    <DialogTitle>{{ t('billing.buy_credits') }}</DialogTitle>
                    <DialogDescription>
                        {{ t('billing.simulated_notice') }}
                    </DialogDescription>
                </DialogHeader>
                <p
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('billing.packs_label') }}
                </p>
                <ul class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <li v-for="pack in packs" :key="pack.id">
                        <Button
                            type="button"
                            variant="outline"
                            class="w-full tabular-nums"
                            @click="choose(pack)"
                        >
                            {{ t('billing.buy_pack', { amount: pack.amount }) }}
                        </Button>
                    </li>
                </ul>
            </DialogContent>
        </Dialog>

        <!-- An unknown outcome reloads only the money props (F-09). -->
        <BuyCreditsDialog
            :pack="selected"
            :return-focus="opener"
            @close="selected = null"
            @unconfirmed="
                router.reload({
                    only: [
                        'auth',
                        'balance',
                        'negativeBalance',
                        'smallestTake',
                    ],
                })
            "
        />
    </div>
</template>
