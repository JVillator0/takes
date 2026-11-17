<?php

declare(strict_types=1);

use App\DTOs\Pixelforge\CreateGenerationDTO;
use App\DTOs\Pixelforge\GenerationPageDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\DTOs\Pixelforge\PricingDTO;
use App\DTOs\Pixelforge\RatesDTO;
use App\DTOs\Pixelforge\UsageDayDTO;
use App\DTOs\Pixelforge\UsageReportDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeStatus;
use Carbon\CarbonImmutable;
use Tests\Support\PixelforgeFixture;

describe('GenerationSnapshotDTO', function (): void {
    it('parses a queued generation: usage is null until it succeeds', function (): void {
        $snapshot = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('queued'));

        expect($snapshot->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($snapshot->status)->toBe(TakeStatus::Queued)
            ->and($snapshot->usage)->toBeNull()
            ->and($snapshot->imageUrl)->toBeNull()
            ->and($snapshot->errorCode)->toBeNull()
            ->and($snapshot->startedAt)->toBeNull()
            ->and($snapshot->completedAt)->toBeNull()
            ->and($snapshot->metadata)->toBe(['order_id' => '1042'])
            ->and($snapshot->replayed)->toBeFalse()
            ->and($snapshot->createdAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z')
            ->and($snapshot->createdAt->getTimezone()->getName())->toBe('UTC');
    });

    it('parses the rates strings into nanodollars per 1M tokens', function (): void {
        $rates = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('queued'))->rates;

        expect($rates)->toBeInstanceOf(RatesDTO::class)
            ->and($rates?->toNanosPerMillion())->toBe([
                'input_text' => 5_000_000_000,
                'input_image' => 10_000_000_000,
                'output' => 40_000_000_000,
                'reasoning' => 12_500_000_000,
            ]);
    });

    it('parses a succeeded generation with its usage, image and timestamps', function (): void {
        $snapshot = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('succeeded'));

        expect($snapshot->status)->toBe(TakeStatus::Succeeded)
            ->and($snapshot->isTerminal())->toBeTrue()
            ->and($snapshot->usage?->toUnits())->toBe([
                'input_text' => 12,
                'input_image' => 765,
                'output' => 6450,
                'reasoning' => 3184,
            ])
            ->and($snapshot->usage?->totalTokens)->toBe(10411)
            ->and($snapshot->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png')
            ->and($snapshot->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
            ->and($snapshot->startedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
            ->and($snapshot->completedAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
    });

    it('parses a failed generation with its error code', function (): void {
        $snapshot = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('failed'));

        expect($snapshot->status)->toBe(TakeStatus::Failed)
            ->and($snapshot->usage)->toBeNull()
            ->and($snapshot->errorCode)->toBe('internal_error')
            ->and($snapshot->errorMessage)->toContain('You were not charged');
    });

    it('normalizes timestamps with an offset to UTC', function (): void {
        $snapshot = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('queued', [
            'created_at' => '2026-11-04T08:03:11-06:00',
        ]));

        expect($snapshot->createdAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z')
            ->and($snapshot->createdAt->getTimezone()->getName())->toBe('UTC');
    });

    it('carries the replay flag and our take id from metadata', function (): void {
        $snapshot = GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('queued', [
            'metadata' => ['app_take_id' => '01JTAKE', 'app_customer_id' => '7'],
        ]), replayed: true);

        expect($snapshot->replayed)->toBeTrue()
            ->and($snapshot->appTakeId())->toBe('01JTAKE');
    });

    it('keeps a missing rates object as null, so settlement can fetch the generation', function (): void {
        $payload = PixelforgeFixture::generation('queued');
        unset($payload['rates']);

        expect(GenerationSnapshotDTO::fromArray($payload)->rates)->toBeNull();
    });

    it('rejects payloads that break the documented contract', function (array $overrides): void {
        expect(fn () => GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('succeeded', $overrides)))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'local-only pending status' => [['status' => 'pending']],
        'unknown status' => [['status' => 'cancelled']],
        'succeeded without usage' => [['usage' => null]],
        'succeeded without image' => [['image_url' => null]],
        'succeeded without completed_at' => [['completed_at' => null]],
        'usage before success' => [['status' => 'processing']],
        'rates as JSON numbers' => [['rates' => ['input_text_tokens' => 5, 'input_image_tokens' => 10, 'output_tokens' => 40, 'reasoning_tokens' => 12.5]]],
        'rate with ten decimals' => [['rates' => ['input_text_tokens' => '5.0000000001', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
        'negative token count' => [['usage' => ['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 0]]],
        'id without the gen_ prefix' => [['id' => 'evt_123']],
        'unparseable timestamp' => [['created_at' => 'yesterday']],
        'non-string metadata value' => [['metadata' => ['order_id' => 1042]]],
    ]);

    it('rejects a failed generation without an error', function (): void {
        expect(fn () => GenerationSnapshotDTO::fromArray(PixelforgeFixture::generation('failed', ['error' => null])))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('GenerationPageDTO', function (): void {
    it('parses a page and its cursor', function (): void {
        $page = GenerationPageDTO::fromArray([
            'object' => 'list',
            'data' => [PixelforgeFixture::generation('succeeded'), PixelforgeFixture::generation('failed', ['id' => 'gen_2'])],
            'has_more' => true,
            'next_cursor' => 'eyJjcmVhdGVkX2F0Ijoi',
        ]);

        expect($page->generations)->toHaveCount(2)
            ->and($page->generations[1]->id)->toBe('gen_2')
            ->and($page->hasMore)->toBeTrue()
            ->and($page->nextCursor)->toBe('eyJjcmVhdGVkX2F0Ijoi');
    });

    it('has no cursor on the last page', function (): void {
        $page = GenerationPageDTO::fromArray(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]);

        expect($page->generations)->toBe([])
            ->and($page->nextCursor)->toBeNull();
    });
});

describe('PricingDTO', function (): void {
    it('parses the real GET /v1/pricing response', function (): void {
        $pricing = PricingDTO::fromArray(PixelforgeFixture::json('pricing'));

        expect($pricing->rates->reasoning->toInt())->toBe(12_500_000_000)
            ->and($pricing->charactersPerTextToken)->toBe(4)
            ->and($pricing->textTokenVarianceBps)->toBe(1500)
            ->and($pricing->tokensPerReferenceImage)->toBe(765)
            ->and($pricing->outputTokensFor(TakeQuality::High, TakeSize::Landscape))->toBe(6450)
            ->and($pricing->outputTokensFor(TakeQuality::Low, TakeSize::Square))->toBe(280)
            ->and($pricing->reasoningRangeFor(TakeQuality::Medium))->toBe(['min' => 200, 'max' => 2000]);
    });

    it('accepts the variance as a decimal string (the config fallback)', function (): void {
        $payload = PixelforgeFixture::json('pricing');
        $payload['tokens']['text_token_variance'] = '0.15';

        expect(PricingDTO::fromArray($payload)->textTokenVarianceBps)->toBe(1500);
    });

    it('rejects a table missing a quality or size', function (): void {
        $payload = PixelforgeFixture::json('pricing');
        unset($payload['tokens']['output']['high']['1024x1536']);

        expect(fn () => PricingDTO::fromArray($payload))->toThrow(InvalidArgumentException::class);
    });
});

describe('usage', function (): void {
    it('parses the nine-decimal cost of a day exactly', function (): void {
        $day = UsageDayDTO::fromArray([
            'date' => '2026-11-01',
            'generations' => 1,
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
            'cost_usd' => '0.373875000',
        ]);

        expect($day->cost->toInt())->toBe(373_875_000)
            ->and($day->date->format('Y-m-d H:i:s e'))->toBe('2026-11-01 00:00:00 UTC');
    });

    it('rejects a cost with more than nine decimals or a bad date', function (array $overrides): void {
        $day = array_replace(PixelforgeFixture::json('usage')['data'][0], $overrides);

        expect(fn () => UsageDayDTO::fromArray($day))->toThrow(InvalidArgumentException::class);
    })->with([
        'ten decimals' => [['cost_usd' => '0.1690800001']],
        'number instead of string' => [['cost_usd' => 0.16908]],
        'impossible date' => [['date' => '2026-02-30']],
    ]);

    it('parses the documented report and sums its days exactly', function (): void {
        $report = UsageReportDTO::fromArray(PixelforgeFixture::json('usage'));

        expect($report->days)->toHaveCount(2)
            ->and($report->startDate->format('Y-m-d'))->toBe('2026-11-01')
            ->and($report->endDate->format('Y-m-d'))->toBe('2026-11-02')
            ->and($report->totalCost()->toInt())->toBe(169_080_000)
            ->and($report->totalGenerations())->toBe(3)
            ->and($report->forDate(CarbonImmutable::parse('2026-11-02', 'UTC'))?->cost->isZero())->toBeTrue();
    });

    it('refuses to merge reports that leave a gap', function (): void {
        $first = UsageReportDTO::fromArray(PixelforgeFixture::json('usage'));
        $later = new UsageReportDTO(CarbonImmutable::parse('2026-11-04', 'UTC'), CarbonImmutable::parse('2026-11-04', 'UTC'), []);

        expect(fn () => UsageReportDTO::merge([$first, $later]))->toThrow(InvalidArgumentException::class);
    });
});

describe('CreateGenerationDTO', function (): void {
    it('round-trips the documented request body with our metadata', function (): void {
        $payload = [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['app_take_id' => '01JTAKE', 'app_customer_id' => '42'],
        ];

        expect(CreateGenerationDTO::fromArray($payload)->toArray())->toBe($payload);
    });

    it('counts the prompt limit in Unicode characters', function (): void {
        $make = fn (string $prompt): CreateGenerationDTO => new CreateGenerationDTO($prompt, TakeSize::Square, TakeQuality::Low, [], 't', 'c');

        expect(strlen($make(str_repeat('é', 4000))->prompt))->toBe(8000)
            ->and(fn () => $make(str_repeat('🎬', 4001)))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $make(''))->toThrow(InvalidArgumentException::class);
    });

    it('accepts at most four reference images', function (): void {
        expect(fn () => new CreateGenerationDTO('x', TakeSize::Square, TakeQuality::Low, array_fill(0, 5, 'https://example.com/a.png'), 't', 'c'))
            ->toThrow(InvalidArgumentException::class);
    });
});
