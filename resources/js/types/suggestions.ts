/**
 * "Describe a change" (spec 8, P07). Mirrors App\Http\Resources\SuggestionResource
 * field by field. Money arrives as server-formatted strings (D-042) and the
 * customer never receives tokens, provider names or provider cost (D-023).
 */

/** App\Enums\Suggestions\SuggestionStatus. */
export type SuggestionStatus =
    | 'pending'
    | 'proposed'
    | 'applied'
    | 'discarded'
    | 'failed';

export type WordDiffSegment = {
    type: 'same' | 'added' | 'removed';
    text: string;
};

export type SuggestionDiffRow = {
    key: 'size' | 'quality' | 'references';
    label: string;
    before: string;
    after: string;
    changed: boolean;
};

export type SuggestionData = {
    id: string;
    status: SuggestionStatus;
    instruction: string;
    rationale: string | null;
    /** 2-01 copy for failed and no-change proposals. */
    message: string | null;
    noChange: boolean;
    /** "Up to $0.0006 on hold" while pending. */
    hold: string | null;
    /** Exact suggestion charge once settled, with the 2-01 disclosure. */
    charge: { exact: string; disclosure: string } | null;
    diff: {
        prompt: { changed: boolean; segments: WordDiffSegment[] };
        rows: SuggestionDiffRow[];
    } | null;
    /** The new take's range and the Generate take label, while it can be applied. */
    newTake: { range: string; generate: string; canAfford: boolean } | null;
    resultingTakeId: string | null;
};

/** `GET /takes/{take}/suggestions/quote`. */
export type SuggestionQuote = {
    /** "AI suggestion · up to $0.0008 on hold" */
    quote: string;
    denial: { reason: string; message: string } | null;
};

/** Error bodies of the suggestion endpoints: a DenialReason or a rejection code. */
export type SuggestionRejection = {
    reason: string;
    message: string;
};
