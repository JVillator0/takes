<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Describe a change copy (single source, D-057)
|--------------------------------------------------------------------------
|
| Every string is listed in .claude/specs/2-product-design/01-SPEC.md: the
| "Billing, take and AI strings" table (failed, unavailable, no change,
| charge disclosure, already pending, chips) or the "Additional strings"
| table (keys as written there). Change a string in the spec first, then
| here. Amounts (:amount, :range) are formatted on the server (D-042).
| Customers never see tokens, provider names or the margin (D-023). Shared
| prices reuse `takes.*` (money lines, estimate range, Generate take button).
|
*/

return [

    /* P07 step 1: ask. Chips only fill the field (spec 8 step 1). */
    'label' => 'What should change?',
    'count' => ':count / :max',
    'chips' => [
        'warmer_light' => 'Warmer light',
        'make_it_portrait' => 'Make it portrait',
        'more_detail' => 'More detail',
        'black_and_white' => 'Black and white',
    ],
    'quote' => 'AI suggestion · up to :amount on hold',
    'quote_note' => 'Charged even if you discard the suggestion.',
    'suggest' => 'Suggest change',
    'thinking' => 'Thinking…',

    /* P07 step 2: review the proposal. */
    'review' => 'Review the proposal',
    'diff' => [
        'prompt' => 'Prompt',
        'setting' => 'Setting',
        'before' => 'Before',
        'after' => 'After',
        'size' => 'Size',
        'quality' => 'Quality',
        'references' => 'References',
        'references_with_image' => ':count + take image',
    ],
    'charge_disclosure' => 'Suggestion cost :amount. Charged even if you discard.',
    'new_take' => 'New take: :range',
    'discard' => 'Discard',
    'discarded' => 'Suggestion discarded.',
    'applied' => 'Your new take has started.',

    /* Outcomes (2-01 AI strings). */
    'failed' => 'Suggestion failed. You were not charged.',
    'unavailable' => 'Describe a change is unavailable right now. You were not charged.',
    'no_change' => 'Nothing to change for that request. Try describing a different change. The suggestion cost is charged.',
    'already_pending' => 'A suggestion for this take is already in progress. Wait for it to finish.',
    'base_not_ready' => 'Describe a change works on finished takes.',
    'apply_unavailable' => 'Generating a take from a suggestion is not available yet. Your suggestion is saved.',
    'not_proposed' => 'This suggestion can no longer be used. Describe a new change.',
    'request_key_reused' => 'This request was already sent for another take. Try again.',

];
