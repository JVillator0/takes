import { router, usePage, usePoll } from '@inertiajs/vue3';
import type { ConnectionStatus } from '@laravel/echo-vue';
import {
    echoIsConfigured,
    useConnectionStatus,
    useEcho,
} from '@laravel/echo-vue';
import { useDocumentVisibility } from '@vueuse/core';
import {
    computed,
    onMounted,
    onScopeDispose,
    ref,
    shallowRef,
    toValue,
    watch,
} from 'vue';
import type { ComputedRef, MaybeRefOrGetter, Ref, ShallowRef } from 'vue';
import type { LiveConnectionState, TakeLiveUpdate } from '@/types/live';
import type { BalanceSummary } from '@/types/takes';

/** Props refreshed by every live update, poll and reconnect (W-20). */
const DEFAULT_ONLY = ['takes', 'counts'];

/**
 * Always reloaded with them: the shared `auth.customer` carries the balance
 * pill, so polling and catch-up keep it current too (W-20).
 */
const BALANCE_PROP = 'auth';

/** How long the first handshake may take before a missing socket counts as Reconnecting. */
const CONNECT_GRACE_MS = 10_000;

/** Polling interval while the socket is down (spec 5, W-20). */
const POLL_INTERVAL_MS = 4000;

/** Several updates in a burst (queued → processing → succeeded) cost one reload. */
const RELOAD_DEBOUNCE_MS = 250;

export type UseTakeUpdatesOptions = {
    /** The signed-in customer; their private channel carries every take update. */
    customerId: number | null;
    /** True while any take on the page is pending, queued or processing. */
    hasActiveTakes: MaybeRefOrGetter<boolean>;
    /** Page props to reload; defaults to `takes` and `counts` (`auth` is always added). */
    only?: string[];
};

export type UseTakeUpdatesReturn = {
    state: ComputedRef<LiveConnectionState>;
    connected: ComputedRef<boolean>;
    polling: ComputedRef<boolean>;
    /**
     * Balance pill strings from the newest socket payload, null until one
     * arrives and again whenever `auth.customer` is refreshed (then the shared
     * prop is the newer source).
     */
    balance: ShallowRef<BalanceSummary | null>;
    /** The last `take.updated` payload, for the aria-live announcement. */
    lastUpdate: ShallowRef<TakeLiveUpdate | null>;
};

/**
 * Live take status for a workspace page (spec 5, D-028, F-10).
 *
 * Listens to `take.updated` on `App.Models.Customer.{id}` and refreshes the
 * cards with a scoped partial reload, so the card copy and money lines keep
 * one source (the server's take resource).
 *
 * The page counts as live only while the socket is connected AND the private
 * channel is subscribed: a rejected `/broadcasting/auth`, the window before
 * `subscription_succeeded`, a dropped socket, a server that does not
 * broadcast (`broadcasting.live` false) or Echo not configured at all all
 * mean "not live". Then, while a take is in flight and the tab is visible,
 * it polls the same props every 4 s. Every successful subscription (the
 * first one and each one after a reconnect) reloads once to catch up on
 * broadcasts missed meanwhile (W-20). There is no env switch.
 *
 * Every reload also refreshes `auth`, which carries the balance pill; the
 * socket's balance is only shown until the next `auth` refresh. An older
 * balance (by `balanceAt`) never replaces a newer one, and an older update of
 * a take (by its `updatedAt`) never replaces a newer one of the same take.
 *
 * Call it once per page, from `setup`; listeners, the poll, the grace timer
 * and any pending reload are dropped when the page's scope is disposed.
 */
export function useTakeUpdates(
    projectId: MaybeRefOrGetter<string | null>,
    options: UseTakeUpdatesOptions,
): UseTakeUpdatesReturn {
    const only = [
        ...new Set([...(options.only ?? DEFAULT_ONLY), BALANCE_PROP]),
    ];
    const page = usePage();
    const serverBroadcasts = page.props.broadcasting?.live === true;
    const live =
        serverBroadcasts && echoIsConfigured() && options.customerId !== null;

    const balance = shallowRef<BalanceSummary | null>(null);
    const lastUpdate = shallowRef<TakeLiveUpdate | null>(null);

    // A refreshed `auth.customer` (any reload, another action on the page) is
    // at least as new as a socket balance: drop the socket copy so the pill
    // falls back to the shared prop instead of hiding it.
    watch(
        () => page.props.auth.customer,
        () => {
            balance.value = null;
        },
    );

    /*
     * Queued broadcasts may arrive out of order. Freshness is tracked per take
     * (`take.updatedAt`) and, separately, for the balance (`balanceAt`, when
     * the server read it): a newer balance is never dropped because another
     * take's update was newer, and an older one never replaces it. ISO-8601
     * Zulu strings of one format sort as time.
     */
    const takeUpdatedAt = new Map<string, string>();
    let balanceAt: string | null = null;

    const isNewerTakeUpdate = (payload: TakeLiveUpdate): boolean => {
        const last = takeUpdatedAt.get(payload.take.id);
        const incoming = payload.take.updatedAt;

        if (incoming === null) {
            return last === undefined;
        }

        if (last !== undefined && incoming < last) {
            return false;
        }

        takeUpdatedAt.set(payload.take.id, incoming);

        return true;
    };

    const isNewerBalance = (payload: TakeLiveUpdate): boolean => {
        if (balanceAt !== null && payload.balanceAt < balanceAt) {
            return false;
        }

        balanceAt = payload.balanceAt;

        return true;
    };

    let disposed = false;
    let pendingReload: ReturnType<typeof setTimeout> | null = null;

    const cancelPendingReload = (): void => {
        if (pendingReload !== null) {
            clearTimeout(pendingReload);
            pendingReload = null;
        }
    };

    const reload = (): void => {
        cancelPendingReload();

        if (!disposed) {
            // A background refresh must not wipe validation errors on the page.
            router.reload({ only, preserveErrors: true });
        }
    };

    const reloadSoon = (): void => {
        cancelPendingReload();
        pendingReload = setTimeout(reload, RELOAD_DEBOUNCE_MS);
    };

    const status: Ref<ConnectionStatus> = live
        ? useConnectionStatus()
        : ref<ConnectionStatus>('disconnected');

    /** The private channel answered `subscription_succeeded` on the current socket. */
    const subscribed = ref(false);
    /** It did so at least once on this page, or the auth was refused: the handshake is over. */
    const settled = ref(false);

    if (live) {
        const { channel } = useEcho<TakeLiveUpdate, 'reverb'>(
            `App.Models.Customer.${String(options.customerId)}`,
            '.take.updated',
            (payload) => {
                if (isNewerBalance(payload)) {
                    balance.value = payload.balance;
                }

                if (isNewerTakeUpdate(payload)) {
                    lastUpdate.value = payload;
                }

                const current = toValue(projectId);

                if (current === null || payload.take.projectId === current) {
                    reloadSoon();
                }
            },
        );

        const subscription = channel().subscription;

        const onSubscribed = (): void => {
            if (disposed) {
                return;
            }

            subscribed.value = true;
            settled.value = true;
            reload();
        };
        const onSubscriptionError = (): void => {
            if (!disposed) {
                subscribed.value = false;
                settled.value = true;
            }
        };

        // Bound on the shared Pusher channel by name, so the handlers (and
        // this page's closure) are removed when the page goes away.
        subscription.bind('pusher:subscription_succeeded', onSubscribed);
        subscription.bind('pusher:subscription_error', onSubscriptionError);

        onScopeDispose(() => {
            subscription.unbind('pusher:subscription_succeeded', onSubscribed);
            subscription.unbind(
                'pusher:subscription_error',
                onSubscriptionError,
            );
        });

        // A channel kept alive by the previous page is already subscribed and
        // will not announce it again: catch up once, now that this page is up.
        if (subscription.subscribed) {
            subscribed.value = true;
            settled.value = true;
            onMounted(reload);
        }

        // A dropped socket drops the subscription; the next
        // `subscription_succeeded` marks it live again and reloads. A failed
        // or lost first attempt ends the handshake grace, so the Reconnecting
        // indicator shows (W-20).
        let attempted = status.value === 'connecting';

        watch(status, (current) => {
            if (current !== 'connected') {
                subscribed.value = false;
            }

            if (current === 'connecting') {
                attempted = true;
            } else if (
                current === 'failed' ||
                (attempted &&
                    (current === 'disconnected' || current === 'reconnecting'))
            ) {
                settled.value = true;
            }
        });

        // Reverb unreachable or a wrong host may never fire either event:
        // the grace period is bounded.
        const graceTimer = setTimeout(() => {
            settled.value = true;
        }, CONNECT_GRACE_MS);

        onScopeDispose(() => {
            clearTimeout(graceTimer);
        });
    }

    const connected = computed(
        () => live && status.value === 'connected' && subscribed.value,
    );
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

    onScopeDispose(() => {
        disposed = true;
        cancelPendingReload();
    });

    const state = computed<LiveConnectionState>(() => {
        if (connected.value) {
            return 'live';
        }

        // The first handshake (socket + /broadcasting/auth) is not a lost
        // connection: poll quietly, without the Reconnecting indicator.
        if (live && !settled.value) {
            return 'connecting';
        }

        return polling.value ? 'polling' : 'idle';
    });

    return { state, connected, polling, balance, lastUpdate };
}
