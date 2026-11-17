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
}>();

const { t } = useCopy();

const form = useForm({
    package: 0,
    idempotency_key: '',
});

/** The server's answer was not a success (lost response, 409, 429, 5xx). */
const failed = ref(false);

const open = computed(() => props.pack !== null);

watch(
    () => props.pack,
    (pack) => {
        if (pack === null) {
            return;
        }

        form.clearErrors();
        form.package = pack.usd;
        form.idempotency_key = crypto.randomUUID();
        failed.value = false;
    },
    { immediate: true },
);

const errorMessage = computed(() => {
    const firstError = Object.values(form.errors)[0];

    return firstError ?? (failed.value ? t('billing.purchase_failed') : null);
});

function pay(): void {
    if (form.processing || props.pack === null) {
        return;
    }

    failed.value = false;

    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // Keep the page and the dialog: the same key is safe to retry.
        onHttpException: () => {
            failed.value = true;

            return false;
        },
        onNetworkError: () => {
            failed.value = true;

            return false;
        },
    });
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
                    <dd>{{ pack.amount }}</dd>
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
                            : t('billing.pay', { amount: pack.amount })
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
