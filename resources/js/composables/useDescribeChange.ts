import { HttpCancelledError, HttpResponseError } from '@inertiajs/core';
import { useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    apply as applyRoute,
    discard as discardRoute,
    quote as quoteRoute,
    show as showRoute,
    store as storeRoute,
} from '@/actions/App/Http/Controllers/Suggestions/SuggestionController';
import { useCopy } from '@/composables/useCopy';
import type {
    SuggestionApplyResult,
    SuggestionData,
    SuggestionQuote,
    SuggestionRejection,
} from '@/types';

/** While a replayed request is still running on the server, check again this often. */
const PENDING_POLL_MS = 2000;

/**
 * At most this many checks (about a minute, past the 20 s call timeout).
 * After that the server expires the suggestion and frees its hold anyway.
 */
const PENDING_POLL_LIMIT = 30;

/** Matches GetSuggestionQuoteQuery::MAX_INSTRUCTION_CHARACTERS (validated on the server). */
export const MAX_INSTRUCTION_CHARACTERS = 500;

export type DescribeChangeStep = 'ask' | 'thinking' | 'review' | 'done';

export type QuoteState = 'loading' | 'ready' | 'failed';

const isRejection = (value: unknown): value is SuggestionRejection =>
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Record<string, unknown>).reason === 'string' &&
    typeof (value as Record<string, unknown>).message === 'string';

/** The server's denial or rejection (DenialReason code + 2-01 copy), if the error body carries one. */
const rejectionFrom = (error: unknown): SuggestionRejection | null => {
    if (!(error instanceof HttpResponseError)) {
        return null;
    }

    try {
        const body: unknown = JSON.parse(error.response.data);

        return isRejection(body) ? body : null;
    } catch {
        return null;
    }
};

/**
 * State and requests of the P07 panel (spec 8). The request key is minted
 * per draft on mount and again after a finished request, and reused on every
 * retry of the same draft, so a double click never pays twice (D-033, F-08).
 * Money and copy come formatted from the server; nothing here computes an amount.
 * Every failed request ends in a visible, announced 2-01 sentence (F-19).
 */
export function useDescribeChange(takeId: Ref<string>) {
    const { t } = useCopy();
    const page = usePage();
    const form = useHttp<
        { instruction: string; request_key: string },
        SuggestionData
    >({
        instruction: '',
        request_key: crypto.randomUUID(),
    });
    const http = useHttp();

    const quote = ref<SuggestionQuote | null>(null);
    const quoteState = ref<QuoteState>('loading');
    const suggestion = ref<SuggestionData | null>(null);
    const rejection = ref<SuggestionRejection | null>(null);
    const appliedMessage = ref<string | null>(null);
    const busy = ref(false);
    /** The pending poll gave up (limit or error): the panel leaves Thinking. */
    const pollFailed = ref(false);
    let pollTimer: number | undefined;
    let pollAttempts = 0;

    const step = computed<DescribeChangeStep>(() => {
        if (
            form.processing ||
            (suggestion.value?.status === 'pending' && !pollFailed.value)
        ) {
            return 'thinking';
        }

        if (suggestion.value?.status === 'proposed') {
            return 'review';
        }

        if (
            suggestion.value?.status === 'applied' ||
            suggestion.value?.status === 'discarded'
        ) {
            return 'done';
        }

        return 'ask';
    });

    /** The 2-01 fallback for an error without a server sentence (5xx, 419, network). */
    const unavailable = (): SuggestionRejection => ({
        reason: 'unavailable',
        message: t('suggestions.unavailable'),
    });

    const fail = (error: unknown): void => {
        if (error instanceof HttpCancelledError) {
            return;
        }

        rejection.value = rejectionFrom(error) ?? unavailable();
    };

    const stopPolling = (): void => {
        window.clearTimeout(pollTimer);
        pollTimer = undefined;
    };

    const schedulePoll = (): void => {
        stopPolling();

        // Paused while the tab is hidden; visibilitychange resumes it.
        if (document.visibilityState === 'hidden') {
            return;
        }

        if (pollAttempts >= PENDING_POLL_LIMIT) {
            pollFailed.value = true;
            rejection.value = unavailable();

            return;
        }

        pollAttempts++;
        pollTimer = window.setTimeout(() => void refresh(), PENDING_POLL_MS);
    };

    async function loadQuote(): Promise<void> {
        quoteState.value = 'loading';

        try {
            quote.value = (await http.get(
                quoteRoute.url(takeId.value),
            )) as SuggestionQuote;
            quoteState.value = 'ready';
        } catch {
            quote.value = null;
            quoteState.value = 'failed';
        }
    }

    const receive = (data: SuggestionData): void => {
        suggestion.value = data;
        stopPolling();

        if (data.status === 'pending') {
            schedulePoll();

            return;
        }

        // The request finished: the next draft gets a new key and a fresh
        // quote (the balance, the daily count or the budget changed).
        pollAttempts = 0;
        form.request_key = crypto.randomUUID();
        void loadQuote();
    };

    async function refresh(): Promise<void> {
        if (!suggestion.value) {
            return;
        }

        try {
            receive(
                (await http.get(
                    showRoute.url(suggestion.value.id),
                )) as SuggestionData,
            );
        } catch (error) {
            stopPolling();
            pollFailed.value = true;
            fail(error);
        }
    }

    async function suggest(): Promise<void> {
        rejection.value = null;
        appliedMessage.value = null;
        pollFailed.value = false;
        pollAttempts = 0;

        try {
            const data = await form.post(storeRoute.url(takeId.value));

            if (data) {
                receive(data);
            } else if (!form.hasErrors) {
                // useHttp resolves a 422 to undefined; one without field
                // errors still needs a sentence.
                rejection.value = unavailable();
            }
        } catch (error) {
            fail(error);
        }
    }

    async function apply(): Promise<void> {
        if (!suggestion.value) {
            return;
        }

        busy.value = true;
        rejection.value = null;

        try {
            const result = (await http.post(
                applyRoute.url(suggestion.value.id),
            )) as SuggestionApplyResult;
            appliedMessage.value = result.message;
            suggestion.value = {
                ...suggestion.value,
                status: 'applied',
                resultingTakeId: result.takeId,
            };
        } catch (error) {
            fail(error);
        } finally {
            busy.value = false;
        }
    }

    async function discard(): Promise<void> {
        if (!suggestion.value) {
            return;
        }

        busy.value = true;
        rejection.value = null;

        try {
            receive(
                (await http.post(
                    discardRoute.url(suggestion.value.id),
                )) as SuggestionData,
            );
        } catch (error) {
            fail(error);
        } finally {
            busy.value = false;
        }
    }

    /** Back to the ask step with the same instruction, and a fresh quote. */
    function startOver(): void {
        stopPolling();
        pollFailed.value = false;
        pollAttempts = 0;
        suggestion.value = null;
        rejection.value = null;
        appliedMessage.value = null;
        void loadQuote();
    }

    /** Chips only fill the field; nothing is sent (spec 8 step 1). */
    function fillChip(text: string): void {
        form.instruction = text;
    }

    const onVisibilityChange = (): void => {
        if (
            document.visibilityState === 'visible' &&
            suggestion.value?.status === 'pending' &&
            !pollFailed.value &&
            pollTimer === undefined
        ) {
            void refresh();
        }
    };

    watch(takeId, () => {
        startOver();
        form.instruction = '';
        form.request_key = crypto.randomUUID();
    });

    // A purchase (or any balance change) can lift an insufficient_credits denial.
    watch(
        () => page.props.auth?.customer?.available_exact,
        (now, before) => {
            if (before !== undefined && now !== before) {
                void loadQuote();
            }
        },
    );

    onMounted(() =>
        document.addEventListener('visibilitychange', onVisibilityChange),
    );
    onBeforeUnmount(() => {
        stopPolling();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    return {
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
        apply,
        discard,
        startOver,
        fillChip,
    };
}
