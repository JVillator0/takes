<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Admin\RecordAdminActivityAction;
use App\Enums\Auth\AdminRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Records every write an admin makes from the console (A07, Buxy's RecordAdminWrites): each record created,
 * updated or deleted, and each role or permission given or taken, by table and id. Writes outside the console (a
 * customer's request, a job, a command) have no admin serving the panel and are not recorded here.
 */
final readonly class RecordAdminWrites
{
    /** Attributes a sign-in touches; an update that changes only these is not an admin write. */
    private const array BOOKKEEPING_ATTRIBUTES = ['remember_token', 'updated_at'];

    private const array EVENTS = [
        'eloquent.created' => 'created',
        'eloquent.updated' => 'updated',
        'eloquent.deleted' => 'deleted',
    ];

    public function __construct(private RecordAdminActivityAction $recordActivity) {}

    /**
     * Wildcard listener for `eloquent.created: *`, `eloquent.updated: *` and `eloquent.deleted: *`.
     *
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $model = $payload[0] ?? null;
        $event = self::EVENTS[strstr($eventName, ':', true)] ?? null;

        if ($event === null || ! $model instanceof Model || $model instanceof Activity) {
            return;
        }

        if ($event === 'updated' && array_diff(array_keys($model->getChanges()), self::BOOKKEEPING_ATTRIBUTES) === []) {
            return;
        }

        $this->record($model, $event);
    }

    public function permissions(PermissionAttachedEvent|PermissionDetachedEvent|RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        $this->record($event->model, 'updated');
    }

    private function record(Model $model, string $event): void
    {
        $admin = auth()->guard(AdminRole::GUARD)->user();

        if (! $admin instanceof User || ! Filament::isServing() || Filament::getCurrentPanel()?->getId() !== 'admin') {
            return;
        }

        $this->recordActivity->execute($admin, $event, $model, [
            'resource' => $model->getTable(),
            'record' => (string) $model->getKey(),
        ]);
    }
}
