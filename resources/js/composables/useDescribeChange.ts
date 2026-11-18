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
    SuggestionRejectionCode,
} from '@/types';

/** While a replayed request is still running on the server, check again this often. */
const PENDING_POLL_MS = 2000;

/**
 * At most this many checks (about a minute, past the 20 s call timeout).
 * After that the panel offers Check again; the server expires the row anyway.
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
 * State and requests of the P07 panel (spec 8).
 *
 * - The request key is minted per draft and again after a finished request,
 *   and reused on every retry of the same draft, so a retry never pays twice
 *   (D-033, F-08).
 * - Money and copy come formatted from the server; nothing here computes an amount.
 * - Every failure ends in a visible, announced 2-01 sentence (F-19), and the
 *   panel never claims "not charged" for an outcome it could not see: a lost
 *   answer to Suggest is replayed once with the same key, then offered as
 *   Check again (F-18).
 * - Responses that belong to a previous take are dropped (a generation
 *   counter), so another take's price never shows here (F-09, F-15).
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
    /** One instance per kind of request, so cancelling one never aborts another. */
    const quoteHttp = useHttp();
    const pollHttp = useHttp();
    const actionHttp = useHttp();

    const quote = ref<SuggestionQuote | null>(null);
    const quoteState = ref<QuoteState>('loading');
    const suggestion = ref<SuggestionData | null>(null);
    const rejection = ref<SuggestionRejection | null>(null);
    const appliedMessage = ref<string | null>(null);
    const busy = ref(false);
    /** The pending poll stopped (limit or error): the panel offers Check again. */
    const pollFailed = ref(false);
    let pollTimer: number | undefined;
    let pollAttempts = 0;
    /** Bumped when the take changes; a response from an older generation is dropped. */
    let generation = 0;

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

    /** A client-side outcome with its 2-01 sentence (no charge claim). */
    const clientRejection = (
        reason: Extract<SuggestionRejectionCode, 'uncertain' | 'action_failed'>,
    ): SuggestionRejection => ({
        reason,
        message: t(`suggestions.${reason}`),
    });

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
            rejection.value = clientRejection('uncertain');

            return;
        }

        pollAttempts++;
        pollTimer = window.setTimeout(() => void refresh(), PENDING_POLL_MS);
    };

    async function loadQuote(): Promise<void> {
        const current = generation;
        quoteState.value = 'loading';

        try {
            const data = (await quoteHttp.get(
                quoteRoute.url(takeId.value),
            )) as SuggestionQuote;

            if (current === generation) {
                quote.value = data;
                quoteState.value = 'ready';
            }
        } catch (error) {
            if (
                current === generation &&
                !(error instanceof HttpCancelledError)
            ) {
                quote.value = null;
                quoteState.value = 'failed';
            }
        }
    }

    const receive = (data: SuggestionData): void => {
        suggestion.value = data;
        rejection.value = null;
        pollFailed.value = false;
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
        const current = generation;

        if (!suggestion.value) {
            return;
        }

        try {
            const data = (await pollHttp.get(
                showRoute.url(suggestion.value.id),
            )) as SuggestionData;

            if (current === generation) {
                receive(data);
            }
        } catch (error) {
            if (current !== generation || error instanceof HttpCancelledError) {
                return;
            }

            // A failed re-read of a finished proposal (after a purchase)
            // left nothing unconfirmed: keep the proposal as it was, with no
            // "uncertain" sentence and no Check again (spec 8 States).
            if (suggestion.value?.status !== 'pending') {
                return;
            }

            // The suggestion id is kept: Check again resumes from it.
            stopPolling();
            pollFailed.value = true;
            rejection.value =
                rejectionFrom(error) ?? clientRejection('uncertain');
        }
    }

    /**
     * POSTs the draft: the suggestion, a server sentence, 'invalid' for a
     * 422 (field errors show inline; nothing ran), or null for a lost answer.
     */
    async function post(): Promise<
        SuggestionData | SuggestionRejection | 'invalid' | null
    > {
        // Known from this request's own 422, never from errors left by an older one.
        let invalid = false;

        try {
            const data = await form.post(storeRoute.url(takeId.value), {
                onError: () => {
                    invalid = true;
                },
            });

            if (invalid) {
                return 'invalid';
            }

            return data ?? null;
        } catch (error) {
            if (error instanceof HttpCancelledError) {
                throw error;
            }

            return rejectionFrom(error);
        }
    }

    async function suggest(): Promise<void> {
        const current = generation;
        stopPolling();
        suggestion.value = null;
        rejection.value = null;
        appliedMessage.value = null;
        pollFailed.value = false;
        pollAttempts = 0;
        form.clearErrors();

        try {
            let result = await post();

            // No answer (network, 5xx, 419): the call may have run and been
            // charged. Replay once with the same key; the server returns the
            // same suggestion and never pays twice (D-033).
            if (result === null && current === generation) {
                result = await post();
            }

            if (current !== generation) {
                return;
            }

            if (result === 'invalid') {
                return;
            }

            if (result === null) {
                rejection.value = clientRejection('uncertain');
            } else if ('status' in result) {
                receive(result);
            } else {
                rejection.value = result;
            }
        } catch {
            // Cancelled: the take changed.
        }
    }

    /** Check again after an unconfirmed outcome, without paying twice. */
    function checkAgain(): void {
        if (suggestion.value?.status === 'pending') {
            pollFailed.value = false;
            rejection.value = null;
            pollAttempts = 0;
            void refresh();

            return;
        }

        // The draft keeps its request key, so this replays the same request.
        void suggest();
    }

    async function act(
        request: () => Promise<unknown>,
        onDone: (data: unknown) => void,
    ): Promise<void> {
        const current = generation;
        busy.value = true;
        rejection.value = null;

        try {
            const data = await request();

            if (current === generation) {
                onDone(data);
            }
        } catch (error) {
            if (
                current === generation &&
                !(error instanceof HttpCancelledError)
            ) {
                rejection.value =
                    rejectionFrom(error) ?? clientRejection('action_failed');
            }
        } finally {
            busy.value = false;
        }
    }

    async function apply(): Promise<void> {
        const current = suggestion.value;

        if (!current) {
            return;
        }

        await act(
            () => actionHttp.post(applyRoute.url(current.id)),
            (data) => {
                const result = data as SuggestionApplyResult;
                appliedMessage.value = result.message;
                suggestion.value = {
                    ...current,
                    status: 'applied',
                    resultingTakeId: result.takeId,
                };
            },
        );
    }

    async function discard(): Promise<void> {
        const current = suggestion.value;

        if (!current) {
            return;
        }

        await act(
            () => actionHttp.post(discardRoute.url(current.id)),
            (data) => receive(data as SuggestionData),
        );
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

    /** Everything in flight belongs to a take this panel no longer shows. */
    const abandonInFlight = (): void => {
        generation++;
        form.cancel();
        quoteHttp.cancel();
        pollHttp.cancel();
        actionHttp.cancel();
        stopPolling();
        busy.value = false;
    };

    watch(takeId, () => {
        abandonInFlight();
        // The previous take's price must never show, even while loading (F-15).
        quote.value = null;
        startOver();
        form.instruction = '';
        form.request_key = crypto.randomUUID();
    });

    // A purchase (or any balance change) can lift an insufficient_credits denial.
    watch(
        () => page.props.auth?.customer?.available_exact,
        (now, before) => {
            if (before === undefined || now === before) {
                return;
            }

            if (rejection.value?.reason === 'insufficient_credits') {
                rejection.value = null;
            }

            void loadQuote();

            // canAfford is computed by the server: re-read a proposal so
            // Generate take returns after a purchase (spec 8 States).
            if (suggestion.value?.status === 'proposed') {
                void refresh();
            }
        },
    );

    onMounted(() =>
        document.addEventListener('visibilitychange', onVisibilityChange),
    );
    onBeforeUnmount(() => {
        abandonInFlight();
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
        checkAgain,
        apply,
        discard,
        startOver,
        fillChip,
    };
}
