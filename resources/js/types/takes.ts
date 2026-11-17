import type { Ability } from '@/types/auth';

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

/** One take in the grid (P04). Mirrors App\Http\Resources\TakeResource. */
export type TakeCardData = {
    /** The take's ulid, used in URLs only. */
    id: string;
    /** "Take #n": its position in its project, in creation order. */
    number: number | null;
    projectId: string;
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
    parentId: string | null;
    parentNumber: number | null;
    /** Only Waiting for capacity (pending + capacity) can be cancelled. */
    canCancel: boolean;
    /** Try again is hidden for `content_policy` and `internal` (D-064). */
    canRetry: boolean;
    /** ISO 8601. */
    createdAt: string | null;
};

export type TakeTimelineEntry = {
    /** ISO 8601 timestamp. */
    at: string;
    label: string;
};

export type TakeVersion = {
    id: string;
    number: number | null;
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
    id: string;
    number: number | null;
    prompt: string;
    imageUrl: string | null;
};

/** The take drawer (P06), loaded with a partial reload `only: ['take']`. Mirrors TakeDetailResource. */
export type TakeDetailData = TakeCardData & {
    /** "Square 1024×1024 · Medium · 1 reference · AI change from #8" */
    paramsLine: string;
    referenceImages: string[];
    price: TakePrice | null;
    timeline: TakeTimelineEntry[];
    parent: TakeParent | null;
    versions: TakeVersion[];
    downloadUrl: string | null;
    /** This take's image as a remix reference; null disables it (image no longer available). */
    remixReferenceUrl: string | null;
};

/** `GET /estimates/take` response (spec 4, D-021 rounding on the display values). */
export type TakeEstimate = {
    /** Exact (D-042). */
    min: string;
    max: string;
    /** 4 decimals: min rounded down, max rounded up (D-021). */
    min_display: string;
    max_display: string;
    references?: number;
    /** TakePolicy::create for this estimate (spec 3-01 `can`). */
    can?: Ability;
};

export type EstimateState = 'idle' | 'loading' | 'ready' | 'denied' | 'error';

export type TakeFilter = 'all' | 'in_progress' | 'done' | 'failed';

export type TakeFilterCounts = Record<TakeFilter, number>;

/**
 * Balance pill (D-024: customers see only Available and On hold). The server
 * always sends both, "$0.0000" included, so the client formats no money.
 */
export type BalanceSummary = {
    available: string;
    availableExact: string;
    onHold: string;
    onHoldExact: string;
};

/** The brief's test keywords offered as chips (D-044); `[overloaded]` is typed by hand. */
export type ScenarioKeyword = 'fail' | 'slow' | 'duplicate' | 'blocked';

export type ComposerErrors = Partial<
    Record<'prompt' | 'size' | 'quality' | 'reference_images', string>
>;

/** The workspace's composer limits and the empty-state sample prompt (P03, P04). */
export type ComposerConfig = {
    maxPromptChars: number;
    maxReferences: number;
    samplePrompt: string | null;
};

/** The smallest possible take's max price, for the low-balance banner (2-01). */
export type SmallestTake = {
    max: string;
    below: boolean;
};

/** The project heading on P04. */
export type WorkspaceProject = {
    id: string;
    name: string;
    takeCount: number;
    spend: string;
    spendExact: string;
};

/** A project card on P03. Mirrors App\Http\Resources\ProjectResource. */
export type ProjectSummary = {
    id: string;
    name: string;
    takeCount: number;
    inProgressCount: number;
    spend: string;
    spendExact: string;
    coverUrl: string | null;
    /** ISO 8601. */
    updatedAt: string | null;
};

/** A credit pack in the Buy credits modal (D-026), formatted by the server. */
export type CreditPack = {
    package: number;
    amount: string;
};
