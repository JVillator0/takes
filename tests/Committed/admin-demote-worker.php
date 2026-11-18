<?php

declare(strict_types=1);

/*
 * One side of SuperAdminDemoteRaceTest (D-086), in its own PHP process: super admin <actorId> takes the super admin
 * role from <targetId> through UpdateAdminUserAction. Right after it takes the super admin lock it pauses, so the
 * other side reaches the same check while this one still holds it.
 *
 *   php tests/Committed/admin-demote-worker.php <actorId> <targetId> <startAt>
 *
 * Prints `ok`, `refused`, or the error class; exits 3 when it booted after the start line.
 */

use App\Actions\Admin\UpdateAdminUserAction;
use App\DTOs\Admin\AdminUserDTO;
use App\Enums\Auth\AdminRole;
use App\Exceptions\Admin\AdminChangeDeniedException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $actorId, $targetId, $startAt] = $argv;

$actor = User::query()->findOrFail((int) $actorId);
$target = User::query()->with('roles')->findOrFail((int) $targetId);

if (microtime(true) >= (float) $startAt) {
    fwrite(STDERR, 'late');
    exit(3);
}

// Hold any row lock this side takes for a while, so the other side has to meet it.
Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
    if (str_contains(strtolower($query->sql), 'for update')) {
        usleep(1_500_000);
    }
});

time_sleep_until((float) $startAt);

try {
    app(UpdateAdminUserAction::class)->execute($actor, $target, AdminUserDTO::fromArray([
        'name' => $target->name,
        'email' => $target->email,
        'roles' => [AdminRole::Operator->value],
        'reason' => 'Race test',
    ]));

    echo 'ok';
} catch (AdminChangeDeniedException) {
    echo 'refused';
} catch (Throwable $exception) {
    echo $exception::class.': '.$exception->getMessage();
    exit(1);
}
