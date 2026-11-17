import { router, usePoll } from '@inertiajs/vue3';
import type { ConnectionStatus } from '@laravel/echo-vue';
import {
    echoIsConfigured,
    useConnectionStatus,
    useEcho,
} from '@laravel/echo-vue';
import { useDebounceFn, useDocumentVisibility } from '@vueuse/core';
import { computed, ref, shallowRef, toValue, watch } from 'vue';
import type { ComputedRef, MaybeRefOrGetter, Ref, ShallowRef } from 'vue';
import type { LiveConnectionState, TakeLiveUpdate } from '@/types/live';
import type { BalanceSummary } from '@/types/takes';

/** Props refreshed by every live update, poll and reconnect (W-20). */
const DEFAULT_ONLY = ['takes', 'counts'];

/** Polling interval while the socket is down (spec 5, W-20). */
const POLL_INTERVAL_MS = 4000;

/** Several updates in a burst (queued → processing → succeeded) cost one reload. */
const RELOAD_DEBOUNCE_MS = 250;

export type UseTakeUpdatesOptions = {
    /** The signed-in customer; their private channel carries every take update. */
    customerId: number | null;
    /** True while any take on the page is pending, queued or processing. */
    hasActiveTakes: MaybeRefOrGetter<boolean>;
    /** Page props to reload; defaults to `takes` and `counts`. */
    only?: string[];
};

export type UseTakeUpdatesReturn = {
    state: ComputedRef<LiveConnectionState>;
    connected: ComputedRef<boolean>;
    polling: ComputedRef<boolean>;
    /** Latest balance pill strings from the server, null until the first update. */
    balance: ShallowRef<BalanceSummary | null>;
    /** The last `take.updated` payload, for the aria-live announcement. */
    lastUpdate: ShallowRef<TakeLiveUpdate | null>;
};

/**
 * Live take status for a workspace page (spec 5, D-028, F-10).
 *
 * Listens to `take.updated` on `App.Models.Customer.{id}` and refreshes the
 * cards with a scoped partial reload, so the card copy and money lines keep
 * one source (the server's take resource). Whenever the socket is not
 * connected (Reverb down, reconnecting, or Echo not configured at all, as
 * with `BROADCAST_CONNECTION=log`) and a take is in flight and the tab is
 * visible, it polls the same props every 4 s instead. When the socket comes
 * back it stops polling and reloads once. There is no env switch.
 *
 * Call it once per page, from `setup`; listeners are removed on unmount.
 */
export function useTakeUpdates(
    projectId: MaybeRefOrGetter<string | null>,
    options: UseTakeUpdatesOptions,
): UseTakeUpdatesReturn {
    const only = options.only ?? DEFAULT_ONLY;
    const live = echoIsConfigured() && options.customerId !== null;

    const balance = shallowRef<BalanceSummary | null>(null);
    const lastUpdate = shallowRef<TakeLiveUpdate | null>(null);

    const reload = (): void => {
        router.reload({ only });
    };
    const reloadSoon = useDebounceFn(reload, RELOAD_DEBOUNCE_MS);

    const status: Ref<ConnectionStatus> = live
        ? useConnectionStatus()
        : ref<ConnectionStatus>('disconnected');

    if (live) {
        useEcho<TakeLiveUpdate>(
            `App.Models.Customer.${String(options.customerId)}`,
            '.take.updated',
            (payload) => {
                balance.value = payload.balance;
                lastUpdate.value = payload;

                const current = toValue(projectId);

                if (current === null || payload.take.projectId === current) {
                    void reloadSoon();
                }
            },
        );
    }

    const connected = computed(() => live && status.value === 'connected');
    const visibility = useDocumentVisibility();

    const polling = computed(
        () =>
            !connected.value &&
            visibility.value === 'visible' &&
            toValue(options.hasActiveTakes),
    );

    const poll = usePoll(POLL_INTERVAL_MS, { only }, { autoStart: false });

    watch(
        polling,
        (shouldPoll) => {
            if (shouldPoll) {
                poll.start();
            } else {
                poll.stop();
            }
        },
        { immediate: true },
    );

    // Back from a disconnect: catch up on whatever happened meanwhile. The
    // first connection after page load needs no reload.
    let hasConnected = false;

    watch(connected, (isConnected) => {
        if (!isConnected) {
            return;
        }

        if (hasConnected) {
            reload();
        }

        hasConnected = true;
    });

    const state = computed<LiveConnectionState>(() => {
        if (connected.value) {
            return 'live';
        }

        return polling.value ? 'polling' : 'idle';
    });

    return { state, connected, polling, balance, lastUpdate };
}
