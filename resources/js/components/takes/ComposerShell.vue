<script setup lang="ts">
import {
    LoaderCircle,
    Plus,
    RectangleHorizontal,
    RectangleVertical,
    Square,
    X,
} from '@lucide/vue';
import { computed, useId } from 'vue';
import EstimatePanel from '@/components/takes/EstimatePanel.vue';
import ScenarioChips from '@/components/takes/ScenarioChips.vue';
import ServiceNotConfiguredBanner from '@/components/takes/ServiceNotConfiguredBanner.vue';
import { Button } from '@/components/ui/button';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type {
    ComposerErrors,
    DenialReason,
    EstimateState,
    TakeEstimate,
    TakeQuality,
    TakeSize,
} from '@/types';

type Props = {
    estimate: TakeEstimate | null;
    estimateState: EstimateState;
    /** Available credits (4 decimals) for the insufficient-balance state. */
    availableDisplay?: string | null;
    /** Denial code when `estimateState` is `denied` (F-17); picks the extra lines. */
    deniedReason?: DenialReason | null;
    /** Server denial sentence (`denials.*`) when `estimateState` is `denied`. */
    deniedMessage?: string | null;
    /** From the shared `pixelforge.configured` prop; false shows the banner and blocks submit. */
    serviceConfigured?: boolean;
    /** Inertia `form.processing`: the create request is in flight. */
    processing?: boolean;
    disabled?: boolean;
    /** Why submit is blocked; also rendered by the `disabled-reason` slot. */
    disabledReason?: string | null;
    /** Puts Buy credits next to the blocked button (P05-A). */
    showBuyCredits?: boolean;
    /** Parent take id when the composer was filled by Remix. */
    remixingFrom?: number | null;
    /** Server validation errors keyed by request field. */
    errors?: ComposerErrors;
    maxPromptChars?: number;
    maxReferences?: number;
};

const props = withDefaults(defineProps<Props>(), {
    availableDisplay: null,
    deniedReason: null,
    deniedMessage: null,
    serviceConfigured: true,
    processing: false,
    disabled: false,
    disabledReason: null,
    showBuyCredits: false,
    remixingFrom: null,
    errors: () => ({}),
    maxPromptChars: 4000,
    maxReferences: 4,
});

const prompt = defineModel<string>('prompt', { default: '' });
const size = defineModel<TakeSize>('size', { default: '1024x1024' });
const quality = defineModel<TakeQuality>('quality', { default: 'medium' });
const references = defineModel<string[]>('references', {
    default: () => [],
});

const emit = defineEmits<{
    /**
     * Carries the estimate whose max was on the button: submit cannot be
     * emitted without a current server price (F-15, 2-01 principle 1).
     */
    submit: [estimate: TakeEstimate];
    buyCredits: [];
    cancelRemix: [];
}>();

const slots = defineSlots<{
    'disabled-reason'?: () => unknown;
}>();

const { t } = useCopy();
const ids = {
    prompt: useId(),
    count: useId(),
    tip: useId(),
    promptError: useId(),
    reason: useId(),
    estimateMessage: useId(),
    pricePending: useId(),
    sizeError: useId(),
    qualityError: useId(),
    referenceError: useId(),
    referencesError: useId(),
    banner: useId(),
};

const sizes: { value: TakeSize; icon: typeof Square }[] = [
    { value: '1024x1024', icon: Square },
    { value: '1536x1024', icon: RectangleHorizontal },
    { value: '1024x1536', icon: RectangleVertical },
];
const qualities: TakeQuality[] = ['low', 'medium', 'high'];

const formatNumber = (value: number): string => value.toLocaleString('en-US');

/** Unicode characters, never UTF-16 units: matches the server's mb_strlen (spec 4). */
const promptLength = computed(() => [...prompt.value].length);
const promptTooLong = computed(() => promptLength.value > props.maxPromptChars);

const promptError = computed(() =>
    promptTooLong.value
        ? t('takes.composer.prompt_too_long', {
              count: formatNumber(promptLength.value),
              max: formatNumber(props.maxPromptChars),
          })
        : (props.errors.prompt ?? null),
);

const isHttpUrl = (value: string): boolean => {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
};

const referenceInvalid = (value: string): boolean =>
    value.trim() !== '' && !isHttpUrl(value.trim());

/** Links a reference input to its own error and the server's list error (F-20). */
const referenceDescribedBy = (index: number): string | undefined => {
    const describedBy = [
        referenceInvalid(references.value[index] ?? '')
            ? `${ids.referenceError}-${index}`
            : null,
        props.errors.reference_images ? ids.referencesError : null,
    ].filter((id): id is string => id !== null);

    return describedBy.length > 0 ? describedBy.join(' ') : undefined;
};

const hasInvalidReference = computed(() =>
    references.value.some(referenceInvalid),
);

/**
 * The price Generate may run at: only a settled server estimate. Idle (no
 * price yet), loading (the price may change), error and denied never qualify.
 */
const currentEstimate = computed<TakeEstimate | null>(() =>
    props.estimateState === 'ready' ? props.estimate : null,
);

/** A previous max is on the button but a newer one is pending or failed (2-02: dimmed). */
const priceIsStale = computed(
    () =>
        props.estimate !== null &&
        (props.estimateState === 'loading' || props.estimateState === 'error'),
);

const canSubmit = computed(
    () =>
        currentEstimate.value !== null &&
        props.serviceConfigured &&
        !props.disabled &&
        !props.processing &&
        props.estimateState !== 'denied' &&
        prompt.value.trim() !== '' &&
        !promptTooLong.value &&
        !hasInvalidReference.value,
);

const submitLabel = computed(() => {
    if (props.processing) {
        return t('takes.composer.submitting');
    }

    return props.estimate
        ? t('takes.composer.submit_with_max', {
              amount: props.estimate.max_display,
          })
        : t('takes.composer.submit');
});

/**
 * Everything that explains a blocked Generate, so a screen reader hears why
 * when it lands on the aria-disabled button.
 */
const submitDescribedBy = computed(() => {
    const describedBy = [
        props.serviceConfigured ? null : ids.banner,
        props.estimateState === 'loading' ? ids.pricePending : null,
        props.estimateState === 'idle' ||
        props.estimateState === 'denied' ||
        props.estimateState === 'error'
            ? ids.estimateMessage
            : null,
        promptError.value ? ids.promptError : null,
        props.disabledReason || slots['disabled-reason'] ? ids.reason : null,
    ].filter((id): id is string => id !== null);

    return describedBy.length > 0 ? describedBy.join(' ') : undefined;
});

const submit = (): void => {
    if (canSubmit.value && currentEstimate.value) {
        emit('submit', currentEstimate.value);
    }
};

const onPromptKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        submit();
    }
};

const addScenario = (keyword: string): void => {
    if (prompt.value.toLowerCase().includes(keyword)) {
        return;
    }

    const current = prompt.value.trimEnd();
    prompt.value = current === '' ? keyword : `${current} ${keyword}`;
};

const updateReference = (index: number, value: string): void => {
    references.value = references.value.map((reference, position) =>
        position === index ? value : reference,
    );
};

const addReference = (): void => {
    if (references.value.length < props.maxReferences) {
        references.value = [...references.value, ''];
    }
};

const removeReference = (index: number): void => {
    references.value = references.value.filter(
        (_, position) => position !== index,
    );
};

const segmentClass =
    'flex flex-1 cursor-pointer flex-col items-center justify-center gap-0.5 px-2 py-2 text-center text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50 has-[:focus-visible]:ring-inset';
</script>

<template>
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold">
                {{ t('takes.composer.title') }}
            </h2>
            <span
                v-if="remixingFrom"
                class="inline-flex items-center gap-1 rounded-full border bg-muted py-0.5 pr-1 pl-2.5 text-xs font-medium"
            >
                {{ t('takes.composer.remixing', { id: remixingFrom }) }}
                <button
                    type="button"
                    class="rounded-full p-0.5 outline-none hover:bg-background focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :aria-label="
                        t('takes.composer.cancel_remix', { id: remixingFrom })
                    "
                    @click="emit('cancelRemix')"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>
        </div>

        <ServiceNotConfiguredBanner
            v-if="!serviceConfigured"
            :id="ids.banner"
        />

        <div class="flex flex-col gap-1.5">
            <label
                :for="ids.prompt"
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('takes.composer.prompt') }}
            </label>
            <textarea
                :id="ids.prompt"
                v-model="prompt"
                name="prompt"
                rows="4"
                :placeholder="t('takes.composer.prompt_placeholder')"
                :aria-invalid="promptError ? true : undefined"
                :aria-describedby="
                    [ids.tip, ids.count, promptError ? ids.promptError : '']
                        .filter(Boolean)
                        .join(' ')
                "
                class="min-h-24 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30"
                @keydown="onPromptKeydown"
            />
            <div class="flex items-center justify-between gap-2 text-xs">
                <span :id="ids.tip" class="text-muted-foreground">
                    {{ t('takes.composer.prompt_tip') }}
                </span>
                <span
                    :id="ids.count"
                    :class="[
                        'tabular-nums',
                        promptTooLong
                            ? 'font-medium text-red-700 dark:text-red-300'
                            : 'text-muted-foreground',
                    ]"
                >
                    {{
                        t('takes.composer.prompt_count', {
                            count: formatNumber(promptLength),
                            max: formatNumber(maxPromptChars),
                        })
                    }}
                </span>
            </div>
            <p
                v-if="promptError"
                :id="ids.promptError"
                class="text-sm text-red-700 dark:text-red-300"
            >
                {{ promptError }}
            </p>
            <ScenarioChips :disabled="processing" @add="addScenario" />
        </div>

        <fieldset class="flex flex-col gap-1.5">
            <legend
                class="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('takes.composer.size') }}
            </legend>
            <div class="flex overflow-hidden rounded-md border">
                <label
                    v-for="option in sizes"
                    :key="option.value"
                    :class="cn(segmentClass, 'border-l first:border-l-0')"
                >
                    <input
                        v-model="size"
                        type="radio"
                        name="size"
                        :value="option.value"
                        :aria-invalid="errors.size ? true : undefined"
                        :aria-describedby="
                            errors.size ? ids.sizeError : undefined
                        "
                        class="sr-only"
                    />
                    <span class="inline-flex items-center gap-1">
                        <component
                            :is="option.icon"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ t(`takes.sizes.${option.value}`) }}
                    </span>
                    <span class="text-xs tabular-nums opacity-80">
                        {{ option.value.replace('x', '×') }}
                    </span>
                </label>
            </div>
            <p
                v-if="errors.size"
                :id="ids.sizeError"
                class="text-sm text-red-700 dark:text-red-300"
            >
                {{ errors.size }}
            </p>
        </fieldset>

        <fieldset class="flex flex-col gap-1.5">
            <legend
                class="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('takes.composer.quality') }}
            </legend>
            <div class="flex overflow-hidden rounded-md border">
                <label
                    v-for="option in qualities"
                    :key="option"
                    :class="cn(segmentClass, 'border-l first:border-l-0')"
                >
                    <input
                        v-model="quality"
                        type="radio"
                        name="quality"
                        :value="option"
                        :aria-invalid="errors.quality ? true : undefined"
                        :aria-describedby="
                            errors.quality ? ids.qualityError : undefined
                        "
                        class="sr-only"
                    />
                    {{ t(`takes.quality.${option}`) }}
                </label>
            </div>
            <p
                v-if="errors.quality"
                :id="ids.qualityError"
                class="text-sm text-red-700 dark:text-red-300"
            >
                {{ errors.quality }}
            </p>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend
                class="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('takes.composer.references', { max: maxReferences }) }}
            </legend>
            <div
                v-for="(reference, index) in references"
                :key="index"
                class="flex flex-col gap-1"
            >
                <div class="flex items-center gap-2">
                    <input
                        type="url"
                        inputmode="url"
                        :name="`reference_images[${index}]`"
                        :value="reference"
                        placeholder="https://"
                        :aria-label="
                            t('takes.composer.reference_url', {
                                number: index + 1,
                            })
                        "
                        :aria-invalid="
                            referenceInvalid(reference) ||
                            errors.reference_images
                                ? true
                                : undefined
                        "
                        :aria-describedby="referenceDescribedBy(index)"
                        class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                        @input="
                            updateReference(
                                index,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :aria-label="
                            t('takes.composer.remove_reference', {
                                number: index + 1,
                            })
                        "
                        @click="removeReference(index)"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>
                <p
                    v-if="referenceInvalid(reference)"
                    :id="`${ids.referenceError}-${index}`"
                    class="text-sm text-red-700 dark:text-red-300"
                >
                    {{ t('takes.composer.reference_invalid') }}
                </p>
            </div>
            <p
                v-if="errors.reference_images"
                :id="ids.referencesError"
                class="text-sm text-red-700 dark:text-red-300"
            >
                {{ errors.reference_images }}
            </p>
            <Button
                v-if="references.length < maxReferences"
                type="button"
                variant="ghost"
                size="sm"
                class="self-start text-muted-foreground"
                @click="addReference"
            >
                <Plus aria-hidden="true" />
                {{ t('takes.composer.add_reference') }}
            </Button>
        </fieldset>

        <EstimatePanel
            :estimate="estimate"
            :state="estimateState"
            :available-display="availableDisplay"
            :denied-reason="deniedReason"
            :denied-message="deniedMessage"
            :message-id="ids.estimateMessage"
        />

        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    size="lg"
                    :aria-disabled="!canSubmit"
                    :aria-describedby="submitDescribedBy"
                    :class="[
                        'min-w-0 flex-1 tabular-nums',
                        !canSubmit && 'cursor-not-allowed opacity-50',
                    ]"
                >
                    <LoaderCircle
                        v-if="processing"
                        class="motion-safe:animate-spin"
                        aria-hidden="true"
                    />
                    <span
                        :class="[
                            'truncate transition-opacity',
                            priceIsStale && 'opacity-50',
                        ]"
                    >
                        {{ submitLabel }}
                    </span>
                </Button>
                <Button
                    v-if="showBuyCredits"
                    type="button"
                    size="lg"
                    class="bg-emerald-700 text-white hover:bg-emerald-700/90 dark:bg-emerald-600 dark:hover:bg-emerald-600/90"
                    @click="emit('buyCredits')"
                >
                    {{ t('billing.buy_credits') }}
                </Button>
            </div>
            <!-- Hidden: read only through Generate's aria-describedby while the price loads. -->
            <span :id="ids.pricePending" hidden>
                {{ t('takes.estimate.loading') }}
            </span>
            <div
                v-if="disabledReason || $slots['disabled-reason']"
                :id="ids.reason"
                class="text-sm text-muted-foreground"
            >
                <slot name="disabled-reason">{{ disabledReason }}</slot>
            </div>
            <p class="hidden text-xs text-muted-foreground sm:block">
                {{ t('takes.composer.shortcut', { keys: '⌘/Ctrl + Enter' }) }}
            </p>
        </div>
    </form>
</template>
