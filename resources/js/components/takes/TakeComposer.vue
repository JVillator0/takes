<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, toRef, watch } from 'vue';
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
    /** Exact available credits; a change re-checks the estimate's policy answer. */
    availableExact?: string | null;
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
/** Remix or Try again left out earlier references that no longer resolve (2-01). */
const referencesNotice = ref<string | null>(null);

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

/**
 * A denial from the create itself (the policy re-checked under lock), with
 * its 2-01 copy. Kept apart from the form's field errors: DenialResponse
 * sends it under `denial`, which is not a form field (F-04).
 */
const submitDenial = ref<string | null>(null);

/*
 * A purchase or a finished take changes the available balance; the same
 * inputs may be allowed now, so a stale insufficient-credits denial (from
 * the estimate or from the create) must not keep blocking Generate (F-15).
 * Watches the workspace's live balance and the shared `auth` one.
 */
watch(
    [
        () => props.availableExact,
        () => page.props.auth.customer?.available_exact,
    ],
    (now, before) => {
        if (now[0] === before[0] && now[1] === before[1]) {
            return;
        }

        submitDenial.value = null;
        refresh();
    },
);

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
            submitDenial.value = null;
            form.parent_take_id = null;
            remixingFrom.value = null;
            referenceUnavailable.value = false;
            referencesNotice.value = null;
            refresh();
            emit('created');
        },
        // A denial under lock (credits, caps): the estimate shows it with its code.
        onError: (errors) => {
            submitDenial.value = errors.denial ?? null;
            refresh();
        },
    });
};

const cancelRemix = (): void => {
    form.parent_take_id = null;
    remixingFrom.value = null;
    referenceUnavailable.value = false;
    referencesNotice.value = null;
    mintKey();
};

/**
 * Remix (spec 4 "Remix and reference images"): the parent's prompt, size
 * and quality, its own image as the first reference when it is still
 * available (persisted copy or a valid provider URL), then its references.
 * At the reference limit the last of the parent's references is dropped,
 * never the parent's image.
 */
const remix = (take: TakeDetailData): void => {
    form.clearErrors();
    submitDenial.value = null;
    form.prompt = take.prompt;
    form.size = take.size;
    form.quality = take.quality;
    form.reference_images = [
        ...(take.remixReferenceUrl ? [take.remixReferenceUrl] : []),
        ...take.referenceImages,
    ].slice(0, props.config.maxReferences);
    form.parent_take_id = take.id;
    remixingFrom.value = take.number;
    referenceUnavailable.value = take.remixReferenceUrl === null;
    referencesNotice.value = take.referencesNotice;
    mintKey();
};

/** Try again (D-044): the same inputs and lineage, a new key; the customer presses Generate. */
const tryAgain = (take: TakeDetailData): void => {
    form.clearErrors();
    submitDenial.value = null;
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
    referencesNotice.value = take.referencesNotice;
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
        :service-configured="page.props.imageService.configured"
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
        <template
            v-if="referenceUnavailable || referencesNotice"
            #disabled-reason
        >
            <p v-if="referenceUnavailable" role="status">
                {{ t('takes.image_unavailable') }}
            </p>
            <p v-if="referencesNotice" role="status">
                {{ referencesNotice }}
            </p>
            <p v-if="submitDenial">{{ submitDenial }}</p>
        </template>
    </ComposerShell>
</template>
