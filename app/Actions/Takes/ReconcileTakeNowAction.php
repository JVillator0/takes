<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Admin\RecordAdminActivityAction;
use App\DTOs\Takes\ReconcileResultDTO;
use App\Models\Take;
use App\Models\User;

/**
 * Studio › Take view and the A04 drill-down's **Reconcile now** (D-058): one reconcile poll through spec 5's
 * ReconcileTakeAction (the same one the polling job runs, but one-off: no chain job, no queue), so any change
 * settles through the normal path (idempotent). Audits the admin, the take, the status before and after and what
 * the poll found.
 */
final readonly class ReconcileTakeNowAction
{
    public const string EVENT = 'reconciled';

    public function __construct(
        private ReconcileTakeAction $reconcileTake,
        private RecordAdminActivityAction $recordActivity,
    ) {}

    /**
     * Must run with no transaction open: ReconcileTakeAction reads Pixelforge first (M-16). The take is refreshed
     * in place, so the caller sees its status after the poll.
     */
    public function execute(User $admin, Take $take): ReconcileResultDTO
    {
        $statusBefore = $take->status;

        $result = $this->reconcileTake->execute($take);

        $take->refresh();

        $this->recordActivity->execute($admin, self::EVENT, $take, [
            'resource' => $take->getTable(),
            'record' => $take->id,
            'status_before' => $statusBefore->value,
            'status_after' => $take->status->value,
            'reconcile' => $result->status->value,
        ]);

        return $result;
    }
}
