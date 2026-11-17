<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use RuntimeException;

/**
 * The admin console's Pixelforge request budget (AdminRequestBudget) has no room for this check right now.
 */
final class AdminRequestBudgetExhaustedException extends RuntimeException {}
