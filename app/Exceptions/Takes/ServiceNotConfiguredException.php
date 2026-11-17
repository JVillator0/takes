<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use RuntimeException;

/**
 * A take was requested while the image service has no API key (spec 3-04
 * issue 4). The composer is disabled in that state (spec 4 issue 8); this
 * is the server-side guarantee that no take is created or held.
 */
final class ServiceNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Image generation is not configured on this server.');
    }
}
