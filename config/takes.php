<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Takes
|--------------------------------------------------------------------------
|
| Spec 3-01 owns this file and adds the remaining keys. Money values are
| integers (nanodollars) or integer dollars, never floats (D-066).
|
*/

return [

    /*
     * Credit packs a customer can buy, in whole US dollars (spec 3-03 §5,
     * D-051). No env override; specs 4 and 6 only read it.
     */
    'purchase_packs_usd' => [5, 10, 25],

];
