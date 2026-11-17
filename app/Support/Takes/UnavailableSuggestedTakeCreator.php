<?php

declare(strict_types=1);

namespace App\Support\Takes;

use App\Contracts\Takes\CreatesSuggestedTake;
use App\DTOs\Suggestions\SuggestedTakeDTO;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;

/**
 * Bound until spec 4's CreateTakeAction is wired behind CreatesSuggestedTake:
 * applying a suggestion then refuses (and the transaction rolls back) instead
 * of inserting a take that no submit job or hold would ever pick up.
 */
final class UnavailableSuggestedTakeCreator implements CreatesSuggestedTake
{
    public function create(Customer $customer, Project $project, SuggestedTakeDTO $take): Take
    {
        throw TakeCreationUnavailableException::notBound();
    }
}
