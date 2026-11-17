<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Admin\RecordAdminActivityAction;
use App\Enums\Auth\AdminRole;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Records every write an admin makes from the console (A07, Buxy's RecordAdminWrites): each record created,
 * updated or deleted by table and id, and each role or permission granted or revoked as its own event
 * (`role_attached`, `role_detached`, `permission_attached`, `permission_detached`) with the names. Writes outside the console (a
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

        $admin = $this->consoleAdmin();

        if ($admin !== null) {
            $this->write($admin, $model, $event);
        }
    }

    /**
     * A role or permission granted or revoked (Spatie events): a distinct event per direction, with the names.
     */
    public function permissions(PermissionAttachedEvent|PermissionDetachedEvent|RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        // Guard before the names query: permission events fire app-wide (customer roles, deploy seeders).
        $admin = $this->consoleAdmin();

        if ($admin === null) {
            return;
        }

        [$name, $property, $names] = match (true) {
            $event instanceof RoleAttachedEvent => ['role_attached', 'roles', $this->names(Role::class, $event->rolesOrIds)],
            $event instanceof RoleDetachedEvent => ['role_detached', 'roles', $this->names(Role::class, $event->rolesOrIds)],
            $event instanceof PermissionAttachedEvent => ['permission_attached', 'permissions', $this->names(Permission::class, $event->permissionsOrIds)],
            $event instanceof PermissionDetachedEvent => ['permission_detached', 'permissions', $this->names(Permission::class, $event->permissionsOrIds)],
        };

        $this->write($admin, $event->model, $name, [$property => $names]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function write(User $admin, Model $model, string $event, array $extra = []): void
    {
        $this->recordActivity->execute($admin, $event, $model, [
            'resource' => $model->getTable(),
            'record' => (string) $model->getKey(),
            ...$extra,
        ]);
    }

    /**
     * The admin serving the console right now, or null outside it. Cheap checks first: this guards every Eloquent
     * write and every permission event in the app.
     */
    private function consoleAdmin(): ?User
    {
        if (! Filament::isServing() || Filament::getCurrentPanel()?->getId() !== AdminPanelProvider::ID) {
            return null;
        }

        $admin = auth()->guard(AdminRole::GUARD)->user();

        return $admin instanceof User ? $admin : null;
    }

    /**
     * Spatie passes ids, a model or a collection of models; the audit keeps the names, sorted and comma-joined.
     *
     * @param  class-string<Role>|class-string<Permission>  $modelClass
     */
    private function names(string $modelClass, mixed $modelsOrIds): string
    {
        $items = collect(is_iterable($modelsOrIds) ? $modelsOrIds : [$modelsOrIds]);
        $ids = $items->map(fn (mixed $item): mixed => $item instanceof Model ? $item->getKey() : $item)->filter()->all();

        return $modelClass::query()->whereKey($ids)->orderBy('name')->pluck('name')->implode(',');
    }
}
