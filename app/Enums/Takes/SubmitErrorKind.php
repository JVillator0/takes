<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * Why a pending take has not been accepted yet (D-025, spec 3-04).
 *
 * capacity: 429/503, definite non-creation; the UI shows "Waiting for capacity" with Cancel.
 * ambiguous: timeout, connection error or other 5xx; the take may exist, no Cancel.
 */
enum SubmitErrorKind: string
{
    case Capacity = 'capacity';
    case Ambiguous = 'ambiguous';
}
