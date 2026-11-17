/**
 * Live status (spec 5). `take.updated` on `App.Models.Customer.{id}` mirrors
 * App\DTOs\Takes\TakeLiveUpdateDTO field by field. Money arrives formatted by
 * the server (4 decimals plus the exact string for hover); never parse it.
 */

/** App\Enums\Takes\TakeStatus. */
export type LiveTakeStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type LiveBalance = {
    available: string;
    availableExact: string;
    onHold: string;
    onHoldExact: string;
};

export type TakeLiveUpdate = {
    take: {
        id: string;
        projectId: string;
        status: LiveTakeStatus;
        failureCode: string | null;
        updatedAt: string | null;
    };
    balance: LiveBalance;
};

/**
 * How the page is getting updates: `live` over the socket, `polling` while
 * the socket is down (the 2-01 Reconnecting indicator), `idle` when nothing
 * is in flight and the socket is down.
 */
export type LiveConnectionState = 'live' | 'polling' | 'idle';
