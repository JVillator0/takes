<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Enums\Webhooks\WebhookOutcome;
use App\Events\TakeUpdated;
use App\Models\LedgerEntry;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\Takes;

/*
| W-09: the handler's transaction catches nothing. When settlement fails, the
| event row rolls back with everything else and the endpoint answers 5xx, so
| Pixelforge's retry is treated as a new event and settles exactly once.
*/

beforeEach(function (): void {
    PixelforgeWebhook::configure();
    Queue::fake();
    Event::fake([TakeUpdated::class]);
});

it('rolls back the event when settlement throws, and the retry settles once', function (): void {
    $take = Takes::in('processing');
    $event = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_rollback_000000001');
    Event::fake([TakeUpdated::class]);

    // SettleUsageAction's charge insert fails inside the handler transaction.
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fail_charge() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'settlement unavailable';
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER fail_charge BEFORE INSERT ON ledger_entries FOR EACH ROW EXECUTE FUNCTION fail_charge();
        SQL);

    PixelforgeWebhook::post($this, $event, attempt: 1)->assertServerError();

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0)
        ->and($take->refresh()->status)->toBe(TakeStatus::Processing)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Open);
    Event::assertNotDispatched(TakeUpdated::class);

    DB::unprepared('DROP TRIGGER fail_charge ON ledger_entries; DROP FUNCTION fail_charge();');

    PixelforgeWebhook::post($this, $event, attempt: 2)->assertOk()->assertExactJson(['status' => 'applied']);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->sole()->outcome)->toBe(WebhookOutcome::Applied)
        ->and(WebhookDelivery::query()->sole()->attempt)->toBe(2)
        ->and($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(LedgerEntry::query()->where('type', 'charge')->count())->toBe(1);
});
