<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { computed, onMounted, toRef, useId } from 'vue';
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
    suggestion,
    rejection,
    appliedMessage,
    busy,
    step,
    loadQuote,
    suggest,
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
const canSuggest = computed(
    () =>
        form.instruction.trim() !== '' &&
        !form.processing &&
        !blocked.value &&
        step.value === 'ask',
);

/** One polite sentence per state change (F-19). */
const announcement = computed(() => {
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

onMounted(() => {
    if (props.initialSuggestion) {
        suggestion.value = props.initialSuggestion;
    }

    if (props.initialQuote) {
        quote.value = props.initialQuote;
    } else {
        void loadQuote();
    }
});

const tryAgain = (): void => startOver();

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
        :aria-busy="step === 'thinking' ? true : undefined"
        :aria-label="t('takes.actions.describe_change')"
    >
        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ announcement }}
        </p>

        <!-- Step 1 · ask (also shown, read-only, while thinking). -->
        <form
            v-if="step === 'ask' || step === 'thinking'"
            class="flex flex-col gap-3"
            @submit.prevent="suggest"
        >
            <label :for="ids.instruction" :class="sectionLabel">
                {{ t('suggestions.label') }}
            </label>
            <textarea
                :id="ids.instruction"
                v-model="form.instruction"
                name="instruction"
                rows="3"
                :readonly="step === 'thinking'"
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
                <p class="text-xs text-muted-foreground">
                    {{ t('suggestions.quote_note') }}
                </p>
            </div>

            <p
                v-if="quote?.denial || rejection"
                class="rounded-md border border-amber-500/60 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/60 dark:text-amber-200"
            >
                {{ rejection?.message ?? quote?.denial?.message }}
            </p>
            <Button
                v-if="rejection?.reason === 'insufficient_credits'"
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

            <!-- Failed: not charged (2-01). -->
            <div
                v-if="suggestion?.status === 'failed'"
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
        </form>

        <!-- Step 2 · review the proposal. -->
        <div
            v-else-if="step === 'review' && suggestion?.diff"
            class="flex flex-col gap-4"
        >
            <h3 :class="sectionLabel">{{ t('suggestions.review') }}</h3>

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
                            class="bg-emerald-100 text-emerald-900 no-underline dark:bg-emerald-950/60 dark:text-emerald-200"
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
                            <ins v-if="row.changed" class="no-underline">{{
                                row.after
                            }}</ins>
                            <template v-else>{{ row.after }}</template>
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

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="suggestion.newTake?.canAfford && !suggestion.noChange"
                    type="button"
                    size="lg"
                    :disabled="busy"
                    @click="onApply"
                >
                    <Spinner v-if="busy" />
                    {{ suggestion.newTake.generate }}
                </Button>
                <Button
                    v-else-if="
                        suggestion.newTake && !suggestion.newTake.canAfford
                    "
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
            <p class="text-sm">
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
