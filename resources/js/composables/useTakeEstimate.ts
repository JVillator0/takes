import { HttpCancelledError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import { take as estimateRoute } from '@/routes/estimates';
import type {
    EstimateState,
    TakeEstimate,
    TakeQuality,
    TakeSize,
} from '@/types';

/** Spec 4 "Estimate": requests are debounced 250 ms and the superseded one is cancelled (2-02). */
const DEBOUNCE_MS = 250;

type EstimateInputs = {
    prompt: Ref<string>;
    size: Ref<TakeSize>;
    quality: Ref<TakeQuality>;
    references: Ref<string[]>;
};

/**
 * The composer's live price. Sends the Unicode character count, never the
 * prompt text (`[...prompt].length`, the server's mb_strlen). Keeps the last
 * valid estimate while a newer one loads (shown dimmed), and reads the
 * policy answer that comes with it: a denial (insufficient credits, caps,
 * restriction) is the `denied` state with its 2-01 sentence.
 */
export function useTakeEstimate(inputs: EstimateInputs) {
    const http = useHttp<Record<string, never>, TakeEstimate>({});
    const estimate = shallowRef<TakeEstimate | null>(null);
    const state = ref<EstimateState>('idle');

    // Unicode code points, the server's mb_strlen; never UTF-16 units (spec 4).
    const characters = computed(() => Array.from(inputs.prompt.value).length);
    const referenceCount = computed(
        () => inputs.references.value.filter((url) => url.trim() !== '').length,
    );

    /*
     * Each request takes a number; only the latest one may write. useHttp
     * resets `processing` in its own `finally` before a cancelled request
     * reaches this catch, so `processing` cannot tell a superseded request
     * from a failed one (F-09).
     */
    let latestRequest = 0;

    const fetchEstimate = useDebounceFn(async (): Promise<void> => {
        const request = ++latestRequest;
        http.cancel();

        if (inputs.prompt.value.trim() === '') {
            estimate.value = null;
            state.value = 'idle';

            return;
        }

        try {
            // A 422 (an input the server refuses) resolves to undefined, not a throw.
            const response: TakeEstimate | undefined = await http.get(
                estimateRoute.url({
                    query: {
                        chars: characters.value,
                        size: inputs.size.value,
                        quality: inputs.quality.value,
                        references: referenceCount.value,
                    },
                }),
            );

            if (request !== latestRequest) {
                return;
            }

            if (response === undefined) {
                state.value = 'error';

                return;
            }

            estimate.value = response;
            state.value = response.can?.allowed === false ? 'denied' : 'ready';
        } catch (error) {
            // A superseded request is replaced by the next one; anything
            // else keeps the last price, dimmed, with the 2-01 error line.
            if (
                error instanceof HttpCancelledError ||
                request !== latestRequest
            ) {
                return;
            }

            state.value = 'error';
        }
    }, DEBOUNCE_MS);

    watch(
        [characters, inputs.size, inputs.quality, referenceCount],
        () => {
            if (inputs.prompt.value.trim() !== '') {
                state.value = 'loading';
            }

            void fetchEstimate();
        },
        { immediate: true },
    );

    const deniedMessage = computed(() =>
        state.value === 'denied'
            ? (estimate.value?.can?.message ?? null)
            : null,
    );

    const deniedReason = computed(() =>
        state.value === 'denied' ? (estimate.value?.can?.reason ?? null) : null,
    );

    /** After a purchase or a finished take the same inputs may be allowed now. */
    const refresh = (): void => {
        void fetchEstimate();
    };

    return { estimate, state, deniedMessage, deniedReason, refresh };
}
