<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use LogicException;

/**
 * Thrown before any HTTP call when PIXELFORGE_API_KEY is empty. Callers check
 * PixelforgeClient::isConfigured() first; reaching this is a bug (issue 4).
 */
final class PixelforgeNotConfigured extends LogicException
{
    public function __construct()
    {
        parent::__construct('Pixelforge is not configured: PIXELFORGE_API_KEY is empty.');
    }
}
