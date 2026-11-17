<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Takes\ApplyTakeSnapshotAction;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\Provider;
use App\Models\Take;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\DB;

/**
 * Take fixtures for the transition tests (spec 3-02 issue 3). Every take
 * starts the way a real one does: pending, with the default hold open on a
 * customer funded with $10.00, and moves only through the real actions.
 */
final class Takes
{
    public const int FUNDED_NANOS = 10_000_000_000;

    /**
     * The docs' succeeded generation (tests/Fixtures/Pixelforge/generation-succeeded.json):
     * 12 × 5,000 + 765 × 10,000 + 6,450 × 40,000 + 3,184 × 12,500 = 305,510,000 nanodollars.
     */
    public const int DOCS_COST = 305_510_000;

    /** DOCS_COST × 1.25, exact: 381,887,500 nanodollars ($0.3818875). */
    public const int DOCS_CHARGE = 381_887_500;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function pendingWithHold(array $attributes = []): Take
    {
        $customer = Billing::fundedCustomer(self::FUNDED_NANOS);
        $take = Take::factory()->create(['customer_id' => $customer->id, ...$attributes]);

        DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $take,
            Provider::Pixelforge,
            Billing::estimate(),
            Billing::pixelforgeRates(),
        ));

        return $take->refresh();
    }

    /** A fresh pending take moved to $status by a provider snapshot (`failed` is a provider failure). */
    public static function in(string $status): Take
    {
        $take = self::pendingWithHold();

        if ($status !== 'pending') {
            app(ApplyTakeSnapshotAction::class)->execute($take, self::snapshot($take, $status));
        }

        return $take->refresh();
    }

    public static function generationId(Take $take): string
    {
        return $take->pixelforge_id ?? 'gen_'.strtolower($take->id);
    }

    /**
     * The documented generation object at $status, for this take: its
     * generation id and the metadata our create request sends.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function snapshot(Take $take, string $status, array $overrides = [], bool $replayed = false): GenerationSnapshotDTO
    {
        $fixture = $status === 'processing' ? 'queued' : $status;

        return GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation($fixture, [
            'id' => self::generationId($take),
            'status' => $status,
            'metadata' => ['app_take_id' => $take->id, 'app_customer_id' => (string) $take->customer_id],
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            ...$overrides,
        ]), $replayed);
    }

    public static function usage(Take $take): UsageRecord
    {
        return UsageRecord::query()
            ->where('billable_type', $take->getMorphClass())
            ->where('billable_id', $take->id)
            ->sole();
    }
}
