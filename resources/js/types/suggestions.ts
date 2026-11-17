import type { DenialReason } from './denials';

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
    /** Why the row cannot apply as proposed, e.g. the 2-01 "image no longer available". */
    note: string | null;
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
    /** The proposal adds the base image but it has no usable URL: apply is refused. */
    baseImageUnavailable: boolean;
};

/** `GET /takes/{take}/suggestions/quote`. */
export type SuggestionQuote = {
    /** "AI suggestion · up to $0.0008 on hold" */
    quote: string;
    denial: { reason: DenialReason; message: string } | null;
};

/**
 * Codes of the suggestion endpoints besides DenialReason (SuggestionController),
 * plus the client's own outcomes: `uncertain` (no answer to a paid request,
 * never a charge claim) and `action_failed` (apply or discard got no answer).
 */
export type SuggestionRejectionCode =
    | 'already_pending'
    | 'base_not_ready'
    | 'request_key_reused'
    | 'not_proposed'
    | 'image_unavailable'
    | 'apply_unavailable'
    | 'uncertain'
    | 'action_failed';

export type SuggestionRejectionReason = DenialReason | SuggestionRejectionCode;

/** Error bodies of the suggestion endpoints: a DenialReason or a rejection code. */
export type SuggestionRejection = {
    reason: SuggestionRejectionReason;
    message: string;
};

/** `POST /suggestions/{suggestion}/apply`: the new take and the 2-01 result line. */
export type SuggestionApplyResult = {
    takeId: string;
    message: string;
};
