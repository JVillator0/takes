<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Takes copy (single source, D-057)
|--------------------------------------------------------------------------
|
| Every string here is listed in .claude/specs/2-product-design/01-SPEC.md:
| the state, failure and billing tables, or the "Additional strings" table
| (keys as written there). Change a string in the spec first, then here.
| Keys under `failure` match App\Enums\Takes\FailureCode. A "P0x" note names
| the wireframe sheet where the string appears.
| Customers never see provider names, provider cost or the margin (D-023).
| Denial reasons live in their own file (owned by spec 3-01); only reference them.
|
*/

return [

    /* Status labels (2-01 state table). Keys are the display phase, not the DB status. */
    'status' => [
        'submitting' => 'Submitting',
        'waiting_for_capacity' => 'Waiting for capacity',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'done' => 'Done',
        'failed' => 'Failed',
    ],

    /* Grid filter (2-01 copy rules: the first four statuses group as In progress). */
    'filter' => [
        'label' => 'Filter takes',
        'all' => 'All',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'failed' => 'Failed',
    ],

    /* Money lines (2-01 state table). :amount is formatted on the server (D-042). */
    'money' => [
        'on_hold' => 'Up to :amount on hold',
        'on_hold_not_charged_yet' => 'Up to :amount on hold. Not charged yet.',
        'charged' => 'Charged :amount',
        'not_charged' => 'Not charged',
    ],

    /* Explanations of non-terminal states (2-01 state table). */
    'explanation' => [
        'submitting' => 'Sending your take…',
        'waiting_concurrency' => 'We allow 3 at a time. Yours starts when one finishes.',
        'waiting_busy' => 'The image service is busy. Retrying automatically.',
        'submitting_ambiguous' => 'Taking longer than usual. Retrying automatically.',
        'queued' => 'In line to start.',
        'processing' => 'Rendering your image.',
    ],

    /* Automatic-retry rows of the failure table: not failures, shown with the pending money line (2-01). */
    'retrying' => [
        'rate_limited' => "The image service is busy. We'll retry automatically.",
        'unavailable' => "The image service is unavailable. We'll retry automatically.",
    ],

    /* Failure reasons by failure_code (2-01 failure table, exact sentences). */
    'failure' => [
        'content_policy' => 'This prompt was declined by the content policy. Change it and try again. You were not charged.',
        'validation' => "We couldn't accept these settings. Change them and try again. You were not charged.",
        'daily_limit' => 'Daily limit reached. Try again after 00:00 UTC. You were not charged.',
        'generation_failed' => 'The image could not be generated. You were not charged.',
        'provider_busy' => 'The image service is busy, please try again. You were not charged.',
        'submit_timeout' => "We couldn't reach the image service. You were not charged.",
        'cancelled' => 'Cancelled. You were not charged.',
        'internal' => 'Something went wrong on our side. You were not charged.',
        'internal_error' => 'The image service had a temporary problem. You were not charged.',
    ],

    /* Cross-screen states (2-01). */
    'empty_grid' => 'No takes yet. Describe an image above and press Generate take.',
    'empty_filter' => 'No takes match this filter.',
    'low_balance' => 'Your available credits are below the smallest take (:amount). Buy credits to keep generating.',
    'image_unavailable' => 'This image is no longer available',
    'cancel_unavailable' => 'This take can no longer be cancelled. It is already being sent.',

    'connection' => [
        'live' => 'Live',
        'reconnecting' => 'Reconnecting… Updates may be a few seconds late.',
        'reconnecting_short' => 'Reconnecting…',
    ],

    'service' => [
        'not_configured' => "Image generation isn't configured on this server yet. You can browse, but Generate is disabled.",
    ],

    /* Actions (2-01 state table and P06). */
    'actions' => [
        'remix' => 'Remix',
        'describe_change' => 'Describe a change',
        'download' => 'Download',
        'copy_prompt' => 'Copy prompt',
        'prompt_copied' => 'Prompt copied',
        'try_again' => 'Try again',
        'cancel' => 'Cancel',
        'compare_with_parent' => 'Compare with parent',
        'hide_comparison' => 'Hide comparison',
    ],

    /* Parameters (P04). Keys match App\Enums\Takes\TakeSize and TakeQuality. */
    'sizes' => [
        '1024x1024' => 'Square',
        '1536x1024' => 'Landscape',
        '1024x1536' => 'Portrait',
    ],

    'quality' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],

    /* Take card (P04). */
    'card' => [
        'title' => 'Take #:id',
        'open' => 'Open take #:id',
        'announcement' => 'Take #:id: :status',
        'no_image' => 'No image',
        'remix_of' => 'Remix of #:id',
        'exact_amount' => 'Exact amount :amount',
    ],

    /* Composer (P04, P05, P10). */
    'composer' => [
        'title' => 'New take',
        'prompt' => 'Prompt',
        'prompt_placeholder' => 'Describe your take…',
        'prompt_tip' => 'Tip: mention light, lens and mood',
        'prompt_count' => ':count / :max',
        'prompt_too_long' => 'Prompt is too long: :count / :max characters.',
        'size' => 'Size',
        'quality' => 'Quality',
        'references' => 'Reference images (optional, up to :max)',
        'reference_url' => 'Reference image :number URL',
        'add_reference' => 'Add reference URL',
        'remove_reference' => 'Remove reference image :number',
        'reference_invalid' => 'Reference images must be http(s) URLs.',
        'remixing' => 'Remixing #:id',
        'cancel_remix' => 'Stop remixing #:id',
        'submit' => 'Generate take',
        'submit_with_max' => 'Generate take · up to :amount',
        'submitting' => 'Generating…',
        'shortcut' => 'Press :keys to generate',
    ],

    /* Estimate panel (P04, P05-A). The margin is never shown to customers (D-023). */
    'estimate' => [
        'title' => 'Estimated price',
        'range' => ':min – :max',
        'updates' => 'updates as you type',
        'idle' => 'Describe your take to see its price.',
        'loading' => 'Updating the price…',
        'pay_exact' => 'You pay the exact amount when the take finishes.',
        'failed_free' => 'Failed takes are free.',
        'hold_note' => 'We hold up to :amount from your balance while it runs.',
        'insufficient' => 'This take can cost up to :max. You have :available available.',
        'max_not_average' => 'We check the maximum, not the average, before a take starts.',
        'error' => "We couldn't update the price. Keep typing or try again in a moment.",
    ],

    /* Try a scenario chips (D-044, D-064). Descriptions name no provider (D-023). */
    'scenarios' => [
        'title' => 'Try a scenario',
        'add' => 'Add :keyword to the prompt',
        'fail' => 'Fails while rendering. You are not charged.',
        'slow' => 'Takes 60 to 90 seconds instead of 5 to 30.',
        'duplicate' => 'Every status update arrives twice. You are charged once.',
        'blocked' => 'Declined by the content policy. You are not charged.',
    ],

    /* Take detail drawer (P06). */
    'drawer' => [
        'timeline' => 'Timeline',
        'price' => 'Price',
        'versions' => 'Versions',
        'this_take' => 'This take',
        'parent' => 'Parent #:id',
        'loading' => 'Loading take…',
        'estimate_was' => 'estimate was :range',
        'hold_released' => 'Up to :amount was held while it ran; the rest went back to your balance.',
        'hold_voided' => 'Up to :amount was held while it ran. All of it went back to your balance.',
        'on_hold' => 'on hold',
        'charged' => 'charged',
        'not_charged' => 'not charged',
        'references' => '{0} no references|{1} 1 reference|[2,*] :count references',
        'ai_change_from' => 'AI change from #:id',
        'version' => '#:id',
        'version_current' => '#:id this take',
    ],

    /* Projects (P03). */
    'projects' => [
        'untitled' => 'Untitled project',
        'archived' => "This project is archived. Takes can't be added to it.",
        'sample_prompt' => 'A lighthouse on a cliff at dusk, warm light, cinematic wide shot',
        'title' => 'Projects',
        'search' => 'Search projects',
        'new' => 'New project',
        'name' => 'Name',
        'create' => 'Create project',
        'rename' => 'Rename',
        'actions' => 'Project actions',
        'breadcrumb' => 'Breadcrumb',
        'save' => 'Save',
        'archive' => 'Archive project',
        'archive_confirm' => 'Archive this project? It leaves your projects list; its takes stay in your billing history.',
        'empty' => 'Start your first project. A sample prompt is ready in the composer.',
        'no_results' => 'No projects match your search.',
        'summary' => ':count take · :amount spent|:count takes · :amount spent',
        'in_progress' => ':count in progress',
        'empty_cover' => 'Empty',
        'load_more' => 'Load more takes',
    ],

    /* Toasts on terminal transitions of the customer's own takes (2-02). */
    'toast' => [
        'done' => 'Take #:id is ready',
        'done_detail' => ':charged. The rest of the hold went back to your balance.',
        'failed' => 'Take #:id failed',
    ],

    /* Take timeline lines (P06). */
    'timeline' => [
        'created' => 'Created · up to :amount held',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'done' => 'Done · charged :amount · rest of the hold released',
        'failed' => 'Failed · not charged · hold released',
    ],

];
