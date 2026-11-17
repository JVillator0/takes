import type { BalanceSummary, TakeStatus } from '@/types/takes';

/**
 * Live status (spec 5). `take.updated` on `App.Models.Customer.{id}` mirrors
 * App\DTOs\Takes\TakeLiveUpdateDTO field by field. Money arrives formatted by
 * the server (4 decimals plus the exact string for hover); never parse it.
 */
export type TakeLiveUpdate = {
    take: {
        id: string;
        projectId: string;
        status: TakeStatus;
        failureCode: string | null;
        updatedAt: string | null;
    };
    balance: BalanceSummary;
};

/**
 * How the page is getting updates: `live` over the socket, `polling` while
 * the socket is down (the 2-01 Reconnecting indicator), `idle` when nothing
 * is in flight and the socket is down.
 */
export type LiveConnectionState = 'live' | 'polling' | 'idle';
