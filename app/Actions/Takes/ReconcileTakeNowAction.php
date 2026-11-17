<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Admin\RecordAdminActivityAction;
use App\Jobs\ReconcileTakeJob;
use App\Models\Take;
use App\Models\User;

/**
 * Studio › Take view and the A04 drill-down's **Reconcile now** (D-058): runs the same reconcile job the polling
 * path uses, synchronously, for one take, so any change settles through the normal path (idempotent). Records the
 * admin, the take and the status before and after in the console audit, and returns the take as it is now.
 */
final readonly class ReconcileTakeNowAction
{
    public const string EVENT = 'reconciled';

    public function __construct(private RecordAdminActivityAction $recordActivity) {}

    public function execute(User $admin, Take $take): Take
    {
        $statusBefore = $take->status;

        ReconcileTakeJob::dispatchSync($take->id);

        $take->refresh();

        $this->recordActivity->execute($admin, self::EVENT, $take, [
            'resource' => $take->getTable(),
            'record' => $take->id,
            'status_before' => $statusBefore->value,
            'status_after' => $take->status->value,
        ]);

        return $take;
    }
}
