<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useBuyCredits } from '@/composables/useBuyCredits';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import { store as purchase } from '@/routes/billing/purchases';

/**
 * Minimal Buy credits (D-026, P05-A): the fixed packs, no card form. Paying
 * posts to POST /billing/purchases (spec 3-03) with a key minted when the
 * modal opens, so a double click or a retry is one purchase (D-033 style).
 * Amounts arrive formatted from the server; the client does no money math.
 */
const page = usePage();
const { t } = useCopy();
const { isOpen, close } = useBuyCredits();
const legendId = useId();

const packs = computed(() => page.props.creditPacks ?? []);

const form = useForm({
    package: 0,
    idempotency_key: '',
});

const selected = computed(() =>
    packs.value.find((pack) => pack.package === form.package),
);

watch(isOpen, (open) => {
    if (open) {
        form.clearErrors();
        form.package = packs.value[1]?.package ?? packs.value[0]?.package ?? 0;
        form.idempotency_key = crypto.randomUUID();
    }
});

const pay = (): void => {
    form.post(purchase().url, {
        preserveScroll: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <Dialog
        :open="isOpen"
        @update:open="(open: boolean) => (open ? undefined : close())"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('billing.buy_credits') }}</DialogTitle>
                <DialogDescription>
                    {{ t('billing.simulated_notice') }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="pay">
                <fieldset class="flex flex-col gap-2">
                    <legend
                        :id="legendId"
                        class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ t('billing.packs_label') }}
                    </legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="pack in packs"
                            :key="pack.package"
                            :class="
                                cn(
                                    'flex cursor-pointer items-center justify-center rounded-lg border px-3 py-3 text-base font-semibold tabular-nums transition-colors hover:bg-accent has-[:checked]:border-foreground has-[:checked]:ring-1 has-[:checked]:ring-foreground has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                )
                            "
                        >
                            <input
                                v-model="form.package"
                                type="radio"
                                name="package"
                                class="sr-only"
                                :value="pack.package"
                            />
                            {{ pack.amount }}
                        </label>
                    </div>
                    <InputError :message="form.errors.package" />
                </fieldset>

                <DialogFooter>
                    <Button
                        type="submit"
                        class="w-full tabular-nums"
                        :disabled="form.processing || !selected"
                    >
                        {{
                            t('billing.pay', {
                                amount: selected?.amount ?? '',
                            })
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
