<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, toRef, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useCopy } from '@/composables/useCopy';
import {
    MAX_INSTRUCTION_CHARACTERS,
    useDescribeChange,
} from '@/composables/useDescribeChange';
import type { SuggestionData, SuggestionQuote } from '@/types';

type Props = {
    /** The base take (ULID). */
    takeId: string;
    /** Gallery and tests only: render a state without calling the server. */
    initialSuggestion?: SuggestionData | null;
    initialQuote?: SuggestionQuote | null;
};

const props = withDefaults(defineProps<Props>(), {
    initialSuggestion: null,
    initialQuote: null,
});

const emit = defineEmits<{
    applied: [takeId: string];
    buyCredits: [];
}>();

const { t } = useCopy();
const ids = {
    instruction: useId(),
    count: useId(),
    quote: useId(),
    error: useId(),
};

const {
    form,
    quote,
    quoteState,
    suggestion,
    rejection,
    appliedMessage,
    busy,
    pollFailed,
    step,
    loadQuote,
    suggest,
    checkAgain,
    apply,
    discard,
    startOver,
    fillChip,
} = useDescribeChange(toRef(props, 'takeId'));

/** 2-01 chips (D-064); a chip fills the field with its own words. */
const chips = [
    'warmer_light',
    'make_it_portrait',
    'more_detail',
    'black_and_white',
] as const;

const instructionError = computed(() => form.errors.instruction ?? null);
const blocked = computed(
    () => quote.value?.denial !== null && quote.value?.denial !== undefined,
);
/** Price before action (F-15): no Suggest until the hold price is on screen. */
const canSuggest = computed(
    () =>
        form.instruction.trim() !== '' &&
        !form.processing &&
        quoteState.value === 'ready' &&
        quote.value !== null &&
        !blocked.value &&
        step.value === 'ask',
);
const page = usePage();
/** Buy credits only for a customer the purchase policy allows (F-20; StudioLayout gates the same way). */
const canPurchase = computed(
    () => page.props.auth.can?.purchaseCredits.allowed === true,
);
const needsCredits = computed(
    () =>
        canPurchase.value &&
        (rejection.value?.reason === 'insufficient_credits' ||
            quote.value?.denial?.reason === 'insufficient_credits'),
);
/** The sentence above Suggest: the request's rejection first, then the quote's denial. */
const notice = computed(
    () => rejection.value?.message ?? quote.value?.denial?.message ?? null,
);

/** One polite sentence per state change (F-19). */
const announcement = computed(() => {
    if (instructionError.value) {
        return instructionError.value;
    }

    if (rejection.value) {
        return rejection.value.message;
    }

    switch (step.value) {
        case 'thinking':
            return t('suggestions.thinking');
        case 'review':
            return suggestion.value?.message ?? t('suggestions.review');
        case 'done':
            return (
                appliedMessage.value ??
                (suggestion.value?.status === 'discarded'
                    ? t('suggestions.discarded')
                    : '')
            );
        default:
            return suggestion.value?.message ?? '';
    }
});

// Fixtures are applied before the step watcher exists, so a first render
// never moves focus.
if (props.initialSuggestion) {
    suggestion.value = props.initialSuggestion;
}

if (props.initialQuote) {
    quote.value = props.initialQuote;
    quoteState.value = 'ready';
}

onMounted(() => {
    if (!props.initialQuote) {
        void loadQuote();
    }
});

/** Each step's heading; focus moves there when the step changes (F-20). */
const stepHeading = ref<HTMLElement | null>(null);
const instructionField = ref<HTMLTextAreaElement | null>(null);

watch(step, async () => {
    await nextTick();

    // A validation error sends focus to the field it is about instead.
    if (instructionError.value) {
        instructionField.value?.focus();

        return;
    }

    stepHeading.value?.focus();
});

const tryAgain = (): void => startOver();

/** No answer to a paid request (or the poll stopped): never a charge claim, offer Check again. */
const uncertain = computed(() => rejection.value?.reason === 'uncertain');

/** Generate take is offered only when the new take can actually be created and paid for. */
const canGenerate = computed(
    () =>
        suggestion.value?.newTake?.canAfford === true &&
        !suggestion.value.noChange &&
        !suggestion.value.baseImageUnavailable &&
        rejection.value?.reason !== 'insufficient_credits',
);
/** Buy credits replaces Generate take when the new take is unaffordable (spec 8 States). */
const offerCredits = computed(
    () =>
        canPurchase.value &&
        suggestion.value !== null &&
        !suggestion.value.noChange &&
        !suggestion.value.baseImageUnavailable &&
        (suggestion.value.newTake?.canAfford === false ||
            rejection.value?.reason === 'insufficient_credits'),
);

const onApply = async (): Promise<void> => {
    await apply();

    if (
        suggestion.value?.status === 'applied' &&
        suggestion.value.resultingTakeId
    ) {
        emit('applied', suggestion.value.resultingTakeId);
    }
};

const sectionLabel =
    'text-xs font-semibold tracking-wide text-muted-foreground uppercase';
const priceBox = 'rounded-md border border-dashed px-3 py-2.5 text-sm';
</script>

<template>
    <section
        class="flex flex-col gap-4"
        :aria-label="t('takes.actions.describe_change')"
    >
        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ announcement }}
        </p>

        <!-- Step 1 · ask (also shown, read-only, while thinking). -->
        <form
            v-if="step === 'ask' || step === 'thinking'"
            class="flex flex-col gap-3"
            :aria-busy="step === 'thinking' ? true : undefined"
            @submit.prevent="suggest"
        >
            <h3 ref="stepHeading" tabindex="-1" class="sr-only">
                {{ t('takes.actions.describe_change') }}
            </h3>
            <label :for="ids.instruction" :class="sectionLabel">
                {{ t('suggestions.label') }}
            </label>
            <textarea
                :id="ids.instruction"
                ref="instructionField"
                v-model="form.instruction"
                name="instruction"
                rows="3"
                :maxlength="MAX_INSTRUCTION_CHARACTERS"
                :readonly="step === 'thinking' || uncertain"
                :aria-invalid="instructionError ? true : undefined"
                :aria-describedby="
                    [ids.count, ids.quote, instructionError ? ids.error : '']
                        .filter(Boolean)
                        .join(' ')
                "
                class="min-h-20 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30"
            />
            <div
                class="flex items-center justify-between text-xs text-muted-foreground"
            >
                <span :id="ids.count">
                    {{
                        t('suggestions.count', {
                            count: form.instruction.length,
                            max: MAX_INSTRUCTION_CHARACTERS,
                        })
                    }}
                </span>
            </div>
            <p
                v-if="instructionError"
                :id="ids.error"
                class="text-sm text-destructive"
            >
                {{ instructionError }}
            </p>

            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="chip in chips"
                    :key="chip"
                    type="button"
                    :disabled="step === 'thinking'"
                    class="rounded-full border bg-background px-3 py-1 text-sm transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50"
                    @click="fillChip(t(`suggestions.chips.${chip}`))"
                >
                    {{ t(`suggestions.chips.${chip}`) }}
                </button>
            </div>

            <div :id="ids.quote" :class="priceBox">
                <template v-if="step === 'thinking' && suggestion?.hold">
                    <p>{{ suggestion.hold }}</p>
                </template>
                <template v-else-if="quote">
                    <p>{{ quote.quote }}</p>
                </template>
                <template v-else-if="quoteState === 'failed'">
                    <p>{{ t('suggestions.unavailable') }}</p>
                </template>
                <Skeleton v-else class="h-4 w-1/2" />
                <p class="text-xs text-muted-foreground">
                    {{ t('suggestions.quote_note') }}
                </p>
            </div>

            <p
                v-if="notice"
                class="rounded-md border border-amber-500/60 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200"
            >
                {{ notice }}
            </p>
            <Button
                v-if="needsCredits"
                type="button"
                variant="outline"
                @click="emit('buyCredits')"
            >
                {{ t('billing.buy_credits') }}
            </Button>

            <div v-if="step === 'thinking'" class="flex flex-col gap-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-1/2" />
            </div>

            <Button type="submit" size="lg" :disabled="!canSuggest">
                <Spinner v-if="step === 'thinking'" />
                <Sparkles v-else aria-hidden="true" />
                {{
                    step === 'thinking'
                        ? t('suggestions.thinking')
                        : t('suggestions.suggest')
                }}
            </Button>

            <!-- Failed (the server voided it: not charged, 2-01). -->
            <div
                v-if="step === 'ask' && suggestion?.status === 'failed'"
                class="flex flex-col gap-2 rounded-md border border-red-600/40 px-3 py-2.5 text-sm text-red-800 dark:border-red-400/40 dark:text-red-200"
            >
                <p>{{ suggestion.message }}</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="self-start"
                    @click="tryAgain"
                >
                    {{ t('takes.actions.try_again') }}
                </Button>
            </div>

            <!-- Unconfirmed: the sentence above makes no charge claim. -->
            <Button
                v-if="step === 'ask' && uncertain"
                type="button"
                variant="outline"
                class="self-start"
                @click="checkAgain"
            >
                {{ t('suggestions.check_again') }}
            </Button>
        </form>

        <!-- Step 2 · review the proposal. -->
        <div
            v-else-if="step === 'review' && suggestion?.diff"
            class="flex flex-col gap-4"
        >
            <h3
                ref="stepHeading"
                tabindex="-1"
                :class="[sectionLabel, 'outline-none']"
            >
                {{ t('suggestions.review') }}
            </h3>

            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium">
                    {{ t('suggestions.diff.prompt') }}
                </span>
                <p
                    class="rounded-md border px-3 py-2 text-sm leading-relaxed break-words"
                >
                    <template
                        v-for="(segment, index) in suggestion.diff.prompt
                            .segments"
                        :key="index"
                    >
                        <del
                            v-if="segment.type === 'removed'"
                            class="bg-red-100 text-red-900 decoration-red-700 dark:bg-red-950/60 dark:text-red-200"
                            >{{ segment.text }}</del
                        >
                        <ins
                            v-else-if="segment.type === 'added'"
                            class="bg-emerald-100 text-emerald-900 underline decoration-emerald-700 underline-offset-2 dark:bg-emerald-950/60 dark:text-emerald-200"
                            >{{ segment.text }}</ins
                        >
                        <span v-else>{{ segment.text }}</span>
                    </template>
                </p>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th scope="col" :class="[sectionLabel, 'py-1.5']">
                            {{ t('suggestions.diff.setting') }}
                        </th>
                        <th scope="col" :class="[sectionLabel, 'py-1.5']">
                            {{ t('suggestions.diff.before') }}
                        </th>
                        <th scope="col" :class="[sectionLabel, 'py-1.5']">
                            {{ t('suggestions.diff.after') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in suggestion.diff.rows"
                        :key="row.key"
                        class="border-b last:border-b-0"
                    >
                        <th scope="row" class="py-2 text-left font-normal">
                            {{ row.label }}
                        </th>
                        <td class="py-2">{{ row.before }}</td>
                        <td
                            class="py-2"
                            :class="
                                row.changed
                                    ? 'bg-emerald-50 font-medium dark:bg-emerald-950/40'
                                    : ''
                            "
                        >
                            <ins
                                v-if="row.changed"
                                class="underline decoration-emerald-700 underline-offset-2"
                                >{{ row.after }}</ins
                            >
                            <template v-else>{{ row.after }}</template>
                            <span
                                v-if="row.note"
                                class="mt-0.5 block text-xs font-normal text-amber-800 dark:text-amber-200"
                                >{{ row.note }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <p
                v-if="suggestion.rationale"
                class="text-sm text-muted-foreground italic"
            >
                “{{ suggestion.rationale }}”
            </p>

            <p
                v-if="suggestion.noChange && suggestion.message"
                class="rounded-md border px-3 py-2 text-sm"
            >
                {{ suggestion.message }}
            </p>

            <div :class="priceBox">
                <p v-if="suggestion.charge">
                    {{ suggestion.charge.disclosure }}
                </p>
                <p
                    v-if="suggestion.newTake"
                    class="mt-1 text-xl font-semibold tabular-nums"
                >
                    {{ suggestion.newTake.range }}
                </p>
            </div>

            <p
                v-if="rejection"
                class="rounded-md border border-amber-500/60 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200"
            >
                {{ rejection.message }}
            </p>
            <!-- not_proposed: "Describe a new change" needs a way back to the ask step. -->
            <Button
                v-if="rejection?.reason === 'not_proposed'"
                type="button"
                variant="outline"
                class="self-start"
                @click="startOver"
            >
                {{ t('takes.actions.describe_change') }}
            </Button>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canGenerate && suggestion.newTake"
                    type="button"
                    size="lg"
                    :disabled="busy"
                    @click="onApply"
                >
                    <Spinner v-if="busy" />
                    {{ suggestion.newTake.generate }}
                </Button>
                <Button
                    v-else-if="offerCredits"
                    type="button"
                    size="lg"
                    @click="emit('buyCredits')"
                >
                    {{ t('billing.buy_credits') }}
                </Button>
                <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    :disabled="busy"
                    @click="discard"
                >
                    {{ t('suggestions.discard') }}
                </Button>
            </div>
        </div>

        <!-- Step 3 · done (applied or discarded). -->
        <div v-else class="flex flex-col gap-3">
            <p ref="stepHeading" tabindex="-1" class="text-sm outline-none">
                {{
                    appliedMessage ??
                    (suggestion?.status === 'discarded'
                        ? t('suggestions.discarded')
                        : '')
                }}
            </p>
            <p v-if="suggestion?.charge" class="text-sm text-muted-foreground">
                {{ suggestion.charge.disclosure }}
            </p>
            <Button
                type="button"
                variant="outline"
                class="self-start"
                @click="startOver"
            >
                {{ t('takes.actions.describe_change') }}
            </Button>
        </div>
    </section>
</template>
