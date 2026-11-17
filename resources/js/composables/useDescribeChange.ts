import { HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    apply as applyRoute,
    discard as discardRoute,
    quote as quoteRoute,
    show as showRoute,
    store as storeRoute,
} from '@/actions/App/Http/Controllers/Suggestions/SuggestionController';
import type {
    SuggestionData,
    SuggestionQuote,
    SuggestionRejection,
} from '@/types';

/** While a replayed request is still running on the server, check again this often. */
const PENDING_POLL_MS = 2000;

/** Matches GetSuggestionQuoteQuery::MAX_INSTRUCTION_CHARACTERS (validated on the server). */
export const MAX_INSTRUCTION_CHARACTERS = 500;

export type DescribeChangeStep = 'ask' | 'thinking' | 'review' | 'done';

const isRejection = (value: unknown): value is SuggestionRejection =>
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Record<string, unknown>).reason === 'string' &&
    typeof (value as Record<string, unknown>).message === 'string';

/** The server's denial or rejection (DenialReason code + 2-01 copy), if the error carries one. */
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
 */
export function useDescribeChange(takeId: Ref<string>) {
    const form = useHttp<
        { instruction: string; request_key: string },
        SuggestionData
    >({
        instruction: '',
        request_key: crypto.randomUUID(),
    });
    const http = useHttp();

    const quote = ref<SuggestionQuote | null>(null);
    const suggestion = ref<SuggestionData | null>(null);
    const rejection = ref<SuggestionRejection | null>(null);
    const appliedMessage = ref<string | null>(null);
    const busy = ref(false);
    let pollTimer: number | undefined;

    const step = computed<DescribeChangeStep>(() => {
        if (form.processing || suggestion.value?.status === 'pending') {
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

    const stopPolling = (): void => {
        window.clearTimeout(pollTimer);
        pollTimer = undefined;
    };

    const receive = (data: SuggestionData): void => {
        suggestion.value = data;
        stopPolling();

        if (data.status === 'pending') {
            pollTimer = window.setTimeout(
                () => void refresh(),
                PENDING_POLL_MS,
            );

            return;
        }

        // The request finished: the next draft gets a new key.
        form.request_key = crypto.randomUUID();
    };

    const fail = (error: unknown): void => {
        rejection.value = rejectionFrom(error);

        if (rejection.value === null && !(error instanceof HttpResponseError)) {
            throw error;
        }
    };

    async function loadQuote(): Promise<void> {
        try {
            quote.value = (await http.get(
                quoteRoute.url(takeId.value),
            )) as SuggestionQuote;
        } catch {
            quote.value = null;
        }
    }

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
            fail(error);
        }
    }

    async function suggest(): Promise<void> {
        rejection.value = null;
        appliedMessage.value = null;

        try {
            const data = await form.post(storeRoute.url(takeId.value));

            if (data) {
                receive(data);
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
            )) as { takeId: string; message: string };
            appliedMessage.value = result.message;
            suggestion.value = {
                ...suggestion.value,
                status: 'applied',
                resultingTakeId: result.takeId,
            };

            return;
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

    /** Back to the ask step with the same instruction (failed or discarded). */
    function startOver(): void {
        stopPolling();
        suggestion.value = null;
        rejection.value = null;
        appliedMessage.value = null;
    }

    /** Chips only fill the field; nothing is sent (spec 8 step 1). */
    function fillChip(text: string): void {
        form.instruction = text;
    }

    watch(takeId, () => {
        startOver();
        form.instruction = '';
        form.request_key = crypto.randomUUID();
        void loadQuote();
    });

    onBeforeUnmount(stopPolling);

    return {
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
    };
}
