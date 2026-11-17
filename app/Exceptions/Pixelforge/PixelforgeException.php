<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use RuntimeException;

/**
 * Base of every Pixelforge call failure, so a caller can catch them all.
 * The subclass says what the caller must do (spec 3-04 error table).
 */
abstract class PixelforgeException extends RuntimeException {}
