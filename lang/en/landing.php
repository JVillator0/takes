<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Landing and shell copy (single source, D-057)
|--------------------------------------------------------------------------
|
| P01 strings, listed in the 2-01 "Additional strings" table. Customers never
| see the margin or a provider name (D-023); "failed takes" wording reuses
| takes.estimate.failed_free.
|
*/

return [

    'meta_title' => 'Describe it. Take it. Remix it.',

    'nav' => [
        'label' => 'Sections',
        'how_it_works' => 'How it works',
        'pricing' => 'Pricing',
        'sign_in' => 'Sign in',
        'start_free' => 'Start free',
        'open_app' => 'Open Takes',
    ],

    'hero' => [
        'line_1' => 'Describe it.',
        'line_2' => 'Take it.',
        'line_3' => 'Remix it.',
        'lead' => 'Generate images, iterate on them as versions, and always see the price before you press Generate.',
        'start' => 'Start with :amount free',
        'demo' => 'Try a demo account',
        'no_card' => 'No card. Credits are simulated for this demo.',
    ],

    'example' => [
        'label' => 'Example project with five takes',
        'project' => 'Lighthouse series',
        'count' => ':count takes',
        'take' => 'take #:id',
        'take_warmer' => 'take #:id · warmer',
        'take_portrait' => 'take #:id · portrait',
    ],

    'steps' => [
        'title' => 'How it works',
        'describe_title' => 'Describe',
        'describe' => 'Write a prompt, pick size and quality. The price range shows before you start.',
        'take_title' => 'Take',
        'take' => 'Every generation is a take, saved in a project, with its exact cost.',
        'remix_title' => 'Remix',
        'remix' => 'Branch any take, or describe a change (“warmer, portrait”) and review it before it runs.',
    ],

    'pricing' => [
        'title' => 'Clear pricing',
        'body' => 'Pay per take from prepaid credits. See the price range before you generate, the exact amount after.',
        'welcome' => 'New accounts start with :amount of credits.',
        'credit_rate' => '1 credit = $1.00.',
        'caption' => 'Example prices per take',
        'example_take' => 'Example take',
        'price_range' => 'Price range',
        'square_medium_short' => 'Square · medium · short prompt',
        'landscape_high_two_refs' => 'Landscape · high · 2 references',
    ],

    'footer' => 'Takes is a take-home project. Payments are simulated.',

    'shell' => [
        'skip_to_content' => 'Skip to content',
    ],

];
