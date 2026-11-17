<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

/**
 * Why a webhook signature was rejected (W-02, W-03). Logged only: a rejected
 * request answers 401 and writes nothing (D-056), so this is not an outcome.
 */
enum SignatureFailure: string
{
    /** PIXELFORGE_WEBHOOK_SECRET is empty: nothing can be verified. */
    case SecretMissing = 'secret_missing';
    case HeaderMissing = 'header_missing';

    /** `t` is absent, not an integer or not positive. */
    case InvalidTimestamp = 'invalid_timestamp';

    /** No non-empty `v1` value. */
    case SignatureMissing = 'signature_missing';

    /** `|now − t|` is above the tolerance. */
    case Stale = 'stale';

    /** No `v1` matches the HMAC of the raw body. */
    case Mismatch = 'mismatch';
}
