/**
 * Props for the take components. They mirror the future TakeResource and
 * estimate endpoint (spec 4). Money is never a number on the client: every
 * amount arrives as a server-formatted string (D-021, D-042), and customers
 * never receive provider names, provider cost or the margin (D-023).
 */

/** Database status (App\Enums\Takes\TakeStatus). */
export type TakeStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/**
 * What the customer sees (2-01 state table). `pending` splits into
 * Submitting and Waiting for capacity by `last_submit_error_kind`.
 */
export type TakePhase =
    | 'submitting'
    | 'waiting_for_capacity'
    | 'queued'
    | 'processing'
    | 'done'
    | 'failed';

/** App\Enums\Takes\TakeSize. */
export type TakeSize = '1024x1024' | '1536x1024' | '1024x1536';

/** App\Enums\Takes\TakeQuality. */
export type TakeQuality = 'low' | 'medium' | 'high';

/** App\Enums\Takes\FailureCode. */
export type FailureCode =
    | 'generation_failed'
    | 'internal_error'
    | 'content_policy'
    | 'daily_limit'
    | 'validation'
    | 'internal'
    | 'provider_busy'
    | 'submit_timeout'
    | 'cancelled';

/** One take in the grid (P04). */
export type TakeCardData = {
    id: number;
    status: TakeStatus;
    phase: TakePhase;
    /** Translated label of `phase`, e.g. "Waiting for capacity". */
    statusLabel: string;
    size: TakeSize;
    quality: TakeQuality;
    prompt: string;
    /** 2-01 money line, 4 decimals: "Up to $0.0863 on hold", "Charged $0.0740", "Not charged". */
    moneyLine: string;
    /** Exact amount shown on hover, e.g. "$0.0740375". */
    moneyExact: string | null;
    /** Why a non-terminal take is where it is (2-01 explanation column). */
    explanation: string | null;
    imageUrl: string | null;
    failureCode: FailureCode | null;
    /** 2-01 failure sentence, ends with "You were not charged." when terminal. */
    failureMessage: string | null;
    parentId: number | null;
    /** Only Waiting for capacity (pending + capacity) can be cancelled. */
    canCancel: boolean;
    /** Try again is hidden for `content_policy` and `internal` (D-064). */
    canRetry: boolean;
};

export type TakeTimelineEntry = {
    /** ISO 8601 timestamp. */
    at: string;
    label: string;
};

export type TakeVersion = {
    id: number;
    label: string;
    imageUrl: string | null;
    current: boolean;
};

export type TakePriceTone = 'charged' | 'on_hold' | 'not_charged';

/** Exact money for the drawer (D-042: money pages show exact amounts). */
export type TakePrice = {
    /** Exact amount, trailing zeros trimmed, at least 2 decimals: "$0.0740375". */
    amount: string;
    label: string;
    tone: TakePriceTone;
    /** "estimate was $0.0677 – $0.0960" */
    estimateLine: string;
    note: string | null;
};

export type TakeParent = {
    id: number;
    prompt: string;
    imageUrl: string | null;
};

/** The take drawer (P06), loaded with a partial reload `only: ['take']`. */
export type TakeDetailData = TakeCardData & {
    /** "Square 1024×1024 · Medium · 1 reference · AI change from #8" */
    paramsLine: string;
    price: TakePrice;
    timeline: TakeTimelineEntry[];
    parent: TakeParent | null;
    versions: TakeVersion[];
    downloadUrl: string | null;
};

/** `GET /estimates/take` response (spec 4, D-021 rounding on the display values). */
export type TakeEstimate = {
    min: string;
    max: string;
    min_display: string;
    max_display: string;
};

export type EstimateState = 'idle' | 'loading' | 'ready' | 'denied' | 'error';

export type TakeFilter = 'all' | 'in_progress' | 'done' | 'failed';

export type TakeFilterCounts = Record<TakeFilter, number>;

/** Balance pill (D-024: customers see only Available and On hold). */
export type BalanceSummary = {
    available: string;
    availableExact: string;
    onHold: string | null;
    onHoldExact: string | null;
};

/** The brief's test keywords offered as chips (D-044); `[overloaded]` is typed by hand. */
export type ScenarioKeyword = 'fail' | 'slow' | 'duplicate' | 'blocked';

export type ComposerErrors = Partial<
    Record<'prompt' | 'size' | 'quality' | 'reference_images', string>
>;
