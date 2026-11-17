<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, toRef } from 'vue';
import ComposerShell from '@/components/takes/ComposerShell.vue';
import { useBuyCredits } from '@/composables/useBuyCredits';
import { useCopy } from '@/composables/useCopy';
import { useTakeEstimate } from '@/composables/useTakeEstimate';
import { store as storeTake } from '@/routes/takes';
import type {
    ComposerConfig,
    ComposerErrors,
    DenialReason,
    TakeDetailData,
    TakeQuality,
    TakeSize,
} from '@/types';

/**
 * The workspace composer (P04, P05, P10; spec 4 "Create take"): the shell
 * from the UI foundation, wired to the estimate endpoint and the create
 * route. The idempotency key is minted per draft, on mount and after each
 * successful create, and sent unchanged on every retry of that draft, so a
 * double click or a resubmit is one take and one hold (D-033, F-08).
 */
type Props = {
    projectId: string;
    config: ComposerConfig;
    /** Available credits, 4 decimals, for the insufficient-balance line. */
    availableDisplay: string | null;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    created: [];
}>();

const page = usePage();
const { t } = useCopy();
const buyCredits = useBuyCredits();

const form = useForm({
    prompt: props.config.samplePrompt ?? '',
    size: '1024x1024' as TakeSize,
    quality: 'medium' as TakeQuality,
    reference_images: [] as string[],
    idempotency_key: '',
    parent_take_id: null as string | null,
});

/** The parent's number while the draft is a remix (the "Remixing #n" chip). */
const remixingFrom = ref<number | null>(null);
/** Remix of a take whose image can no longer be used as a reference (2-01). */
const referenceUnavailable = ref(false);

const mintKey = (): void => {
    form.idempotency_key = crypto.randomUUID();
};

onMounted(mintKey);

const { estimate, state, deniedMessage, deniedReason, refresh } =
    useTakeEstimate({
        prompt: toRef(form, 'prompt'),
        size: toRef(form, 'size'),
        quality: toRef(form, 'quality'),
        references: toRef(form, 'reference_images'),
    });

const errors = computed<ComposerErrors>(() => {
    const referenceError = Object.entries(form.errors).find(([key]) =>
        key.startsWith('reference_images'),
    )?.[1];

    return {
        prompt: form.errors.prompt,
        size: form.errors.size,
        quality: form.errors.quality,
        reference_images: referenceError,
    };
});

/** A denial from the create itself (the policy re-checked under lock), with its 2-01 copy. */
const submitDenial = computed(
    () => (form.errors as Record<string, string | undefined>).denial ?? null,
);

const showBuyCredits = computed(
    () =>
        page.props.auth.can?.purchaseCredits.allowed === true &&
        deniedReason.value === ('insufficient_credits' as DenialReason),
);

const submit = (): void => {
    form.post(storeTake(props.projectId).url, {
        preserveScroll: true,
        onSuccess: () => {
            // A new draft: a new key; the inputs stay for the next variation.
            mintKey();
            form.parent_take_id = null;
            remixingFrom.value = null;
            referenceUnavailable.value = false;
            refresh();
            emit('created');
        },
        // A denial under lock (credits, caps): the estimate shows it with its code.
        onError: () => refresh(),
    });
};

const cancelRemix = (): void => {
    form.parent_take_id = null;
    remixingFrom.value = null;
    referenceUnavailable.value = false;
    mintKey();
};

/**
 * Remix (spec 4 "Remix and reference images"): the parent's prompt, size
 * and quality, its references, and its own image as one more reference when
 * it is still available (persisted copy or a valid provider URL).
 */
const remix = (take: TakeDetailData): void => {
    form.clearErrors();
    form.prompt = take.prompt;
    form.size = take.size;
    form.quality = take.quality;
    form.reference_images = [
        ...take.referenceImages,
        ...(take.remixReferenceUrl ? [take.remixReferenceUrl] : []),
    ].slice(0, props.config.maxReferences);
    form.parent_take_id = take.id;
    remixingFrom.value = take.number;
    referenceUnavailable.value = take.remixReferenceUrl === null;
    mintKey();
};

/** Try again (D-044): the same inputs and lineage, a new key; the customer presses Generate. */
const tryAgain = (take: TakeDetailData): void => {
    form.clearErrors();
    form.prompt = take.prompt;
    form.size = take.size;
    form.quality = take.quality;
    form.reference_images = [...take.referenceImages].slice(
        0,
        props.config.maxReferences,
    );
    form.parent_take_id = take.parentId;
    remixingFrom.value = take.parentNumber;
    referenceUnavailable.value = false;
    mintKey();
};

defineExpose({ remix, tryAgain, refresh });
</script>

<template>
    <ComposerShell
        v-model:prompt="form.prompt"
        v-model:size="form.size"
        v-model:quality="form.quality"
        v-model:references="form.reference_images"
        :estimate="estimate"
        :estimate-state="state"
        :available-display="availableDisplay"
        :denied-reason="state === 'denied' ? deniedReason : null"
        :denied-message="deniedMessage"
        :service-configured="page.props.pixelforge.configured"
        :processing="form.processing"
        :disabled-reason="submitDenial"
        :show-buy-credits="showBuyCredits"
        :remixing-from="remixingFrom"
        :errors="errors"
        :max-prompt-chars="config.maxPromptChars"
        :max-references="config.maxReferences"
        @submit="submit"
        @buy-credits="buyCredits.open()"
        @cancel-remix="cancelRemix"
    >
        <template v-if="referenceUnavailable" #disabled-reason>
            <p>{{ t('takes.image_unavailable') }}</p>
            <p v-if="submitDenial">{{ submitDenial }}</p>
        </template>
    </ComposerShell>
</template>
