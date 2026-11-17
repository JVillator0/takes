<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useCopy } from '@/composables/useCopy';
import { store } from '@/routes/billing/purchases';
import type { CreditPack } from '@/types';

/**
 * The simulated purchase of one credit pack (P08, spec 6). No card fields.
 * The idempotency key is minted when the dialog opens and reused for every
 * retry of this attempt (D-033, F-08): a double-click or a retry after a lost
 * response replays the same purchase instead of buying twice. A second
 * purchase needs the dialog opened again.
 */
type Props = {
    /** The pack to buy; null keeps the dialog closed. */
    pack: CreditPack | null;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
    /** The outcome is unknown: the page reloads its money so a committed purchase shows. */
    unconfirmed: [];
}>();

const { t } = useCopy();

const form = useForm({
    package: '',
    idempotency_key: '',
});

/**
 * How the last attempt ended when it did not succeed:
 * - `failed`: the server definitely refused it (4xx), so nothing was bought;
 * - `unconfirmed`: no answer, or a 5xx after the request may have committed.
 *   The purchase may exist, so the copy never says "not charged" and asks
 *   for Pay again here: the same key replays it instead of buying twice.
 */
const outcome = ref<'failed' | 'unconfirmed' | null>(null);

const open = computed(() => props.pack !== null);

watch(
    () => props.pack,
    (pack) => {
        if (pack === null) {
            return;
        }

        form.clearErrors();
        form.package = pack.id;
        form.idempotency_key = crypto.randomUUID();
        outcome.value = null;
    },
    { immediate: true },
);

const errorMessage = computed(() => {
    const firstError = Object.values(form.errors)[0];

    if (firstError) {
        return firstError;
    }

    if (outcome.value === 'unconfirmed') {
        return t('billing.purchase_unconfirmed');
    }

    return outcome.value === 'failed' ? t('billing.purchase_failed') : null;
});

function pay(): void {
    if (form.processing || props.pack === null) {
        return;
    }

    outcome.value = null;

    form.post(store.url(), {
        preserveScroll: true,
        // Keeps the page mounted: the selected tab stays, and the dialog can
        // hand focus back to the pack button that opened it.
        preserveState: true,
        onSuccess: () => emit('close'),
        // Keep the page and the dialog (and so the key): a retry replays.
        onHttpException: (response) => {
            if (response.status >= 500) {
                unconfirmed();
            } else {
                outcome.value = 'failed';
            }

            return false;
        },
        onNetworkError: () => {
            unconfirmed();

            return false;
        },
    });
}

function unconfirmed(): void {
    outcome.value = 'unconfirmed';
    emit('unconfirmed');
}

function onOpenChange(value: boolean): void {
    if (!value && !form.processing) {
        emit('close');
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent v-if="pack" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{ t('billing.buy_title', { amount: pack.amount }) }}
                </DialogTitle>
                <DialogDescription
                    class="rounded-md border border-dashed p-3 text-foreground"
                >
                    {{ t('billing.simulated_notice') }}
                </DialogDescription>
            </DialogHeader>

            <dl class="divide-y rounded-md border text-sm tabular-nums">
                <div class="flex justify-between gap-4 px-3 py-2">
                    <dt>{{ t('billing.purchase_line') }}</dt>
                    <dd>{{ pack.amount }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-3 py-2">
                    <dt>{{ t('billing.tax') }}</dt>
                    <dd>{{ pack.tax }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-3 py-2 font-semibold">
                    <dt>{{ t('billing.total') }}</dt>
                    <dd>{{ pack.total }}</dd>
                </div>
            </dl>

            <p
                v-if="errorMessage"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errorMessage }}
            </p>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    {{ t('billing.cancel') }}
                </Button>
                <Button
                    type="button"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    @click="pay"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        form.processing
                            ? t('billing.processing')
                            : t('billing.pay', { amount: pack.total })
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
