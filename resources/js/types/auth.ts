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
    on_hold: string;
    avatar?: string;
};

export type Auth = {
    customer: Customer | null;
};

/** One entry of a `can` object: `{ allowed: false, reason: 'insufficient_credits', message }`. */
export type Ability = {
    allowed: boolean;
    reason: DenialReason | null;
    message: string | null;
};

/** Estimate-independent abilities shared with every customer page. */
export type SharedAbilities = {
    createProject: Ability;
    purchaseCredits: Ability;
};

/** A seeded login on the P02 demo panel (only when DEMO_LOGIN_ENABLED=true). */
export type DemoLogin = {
    key: string;
    label: string;
    email: string;
    password: string;
};
