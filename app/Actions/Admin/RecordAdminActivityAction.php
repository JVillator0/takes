<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Writes one entry of the console's audit (spec 7, A07): which admin did what, on which table and record. A
 * subject is linked only when its model has a morph alias (the morph map is enforced, so an unmapped model such
 * as a Shield role is kept by table and id instead). Write actions (Adjust credits, Restrict, Reconcile now) call
 * this with their reason in the properties.
 */
final readonly class RecordAdminActivityAction
{
    public const string LOG_NAME = 'admin';

    /**
     * @param  array<string, scalar|null>  $properties
     */
    public function execute(User $admin, string $event, ?Model $subject = null, array $properties = []): void
    {
        $logger = activity(self::LOG_NAME)
            ->causedBy($admin)
            ->event($event)
            ->withProperties($properties);

        if ($subject !== null && Relation::getMorphAlias($subject::class) !== $subject::class) {
            $logger->performedOn($subject);
        }

        $logger->log($event);
    }
}
