import type { DenialReason } from '@/types/denials';

/**
 * The signed-in customer, shared on every page as `auth.customer` (spec 3-01).
 * Money arrives as server-formatted strings; the frontend never does money math.
 */
export type Customer = {
    id: number;
    name: string;
    email: string;
    available: string;
    /** Exact amount for the pill's tooltip (D-042). */
    available_exact: string;
    on_hold: string;
    on_hold_exact: string;
};

/** One entry of a `can` object: `{ allowed: false, reason: 'insufficient_credits', message }`. */
export type Ability = {
    allowed: boolean;
    reason: DenialReason | null;
    message: string | null;
};

/** Estimate-independent abilities shared with every customer page as `auth.can`. */
export type SharedAbilities = {
    createProject: Ability;
    purchaseCredits: Ability;
    /** Denied with `demo_account_locked` for shared demo logins (D-072). */
    updateAccount: Ability;
};

/** Shared `auth`: page-level `can` props never clash with `auth.can`. */
export type Auth = {
    customer: Customer | null;
    can: SharedAbilities | null;
};

/** A seeded login on the P02 demo panel (only when DEMO_LOGIN_ENABLED=true). */
export type DemoLogin = {
    key: string;
    label: string;
    email: string;
    password: string;
};
