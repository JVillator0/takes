<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Suggestions\CreateSuggestionDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\Enums\Billing\Provider;
use App\Models\Customer;
use App\Models\Take;
use App\Support\Pricing\AiPricing;
use App\Support\Pricing\SuggestionEstimator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fixtures for "Describe a change" tests (spec 8). The model is faked at the
 * HTTP boundary (Http::fake on the Messages API), so every test runs the
 * real laravel/ai request building and response parsing (D-076), including
 * the usage block the charge is settled from.
 */
final class Suggestions
{
    public const string BASE_PROMPT = 'Lighthouse at dusk, warm light, cinematic wide shot, detailed crashing waves';

    public const string ANTHROPIC_URL = 'api.anthropic.com/*';

    /** The spec 8 worked example: 1,480 + 212 tokens ⇒ cost 254,000 ⇒ charge 317,500 nanodollars. */
    public const int EXAMPLE_INPUT_TOKENS = 1_480;

    public const int EXAMPLE_OUTPUT_TOKENS = 212;

    public const int EXAMPLE_CHARGE = 317_500;

    /** A customer allowed to suggest, funded through the real ledger. */
    public static function customer(int $nanos = 1_000_000_000): Customer
    {
        $customer = Customer::factory()->withRole()->create();
        Billing::fund($customer, $nanos);

        return $customer->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function baseTake(Customer $customer, array $overrides = []): Take
    {
        return Take::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'prompt' => self::BASE_PROMPT,
            'reference_images' => ['https://images.example/ref-1.png'],
            ...$overrides,
        ]);
    }

    public static function request(string $instruction = 'Make it portrait, golden hour', ?string $requestKey = null): CreateSuggestionDTO
    {
        return new CreateSuggestionDTO($instruction, $requestKey ?? (string) Str::uuid());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function proposal(array $overrides = []): array
    {
        return [
            'prompt' => 'Lighthouse at golden hour, low sun, cinematic portrait shot, detailed crashing waves',
            'size' => '1024x1536',
            'quality' => 'high',
            'keep_references' => true,
            'add_base_image_as_reference' => true,
            'rationale' => 'Switched to portrait and high quality for wave detail; kept your composition.',
            ...$overrides,
        ];
    }

    /**
     * A Messages API response body as the API returns it.
     *
     * @param  array<string, mixed>|null  $structured  the JSON the model wrote; null for no content
     * @return array<string, mixed>
     */
    public static function messageBody(
        ?array $structured,
        int $inputTokens = self::EXAMPLE_INPUT_TOKENS,
        int $outputTokens = self::EXAMPLE_OUTPUT_TOKENS,
        string $stopReason = 'end_turn',
    ): array {
        return [
            'id' => 'msg_01TestSuggestion',
            'type' => 'message',
            'role' => 'assistant',
            'model' => TakeChangeAgent::MODEL,
            'content' => $structured === null ? [] : [['type' => 'text', 'text' => json_encode($structured)]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => $inputTokens,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'output_tokens' => $outputTokens,
                'output_tokens_details' => ['thinking_tokens' => 0],
            ],
        ];
    }

    /**
     * Fakes the model with one body (or a callback) and Pixelforge pricing
     * with the fixture; any other request fails the test.
     *
     * @param  array<string, mixed>|callable(Request): mixed  $anthropic
     */
    public static function fake(array|callable $anthropic): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::ANTHROPIC_URL => is_callable($anthropic) ? $anthropic : Http::response($anthropic),
            '*/v1/pricing' => Http::response(PixelforgeFixture::json('pricing')),
        ]);
    }

    /** The hold this request must place, by the spec 8 formula. */
    public static function expectedEstimate(Take $take, string $instruction): EstimateDTO
    {
        $text = (new TakeChangeAgent)->fixedRequestText().TakeChangeInputDTO::forTake($take, $instruction)->toMessage();

        return app(SuggestionEstimator::class)->estimate($text, app(AiPricing::class)->rates(Provider::Anthropic, TakeChangeAgent::MODEL));
    }

    /** @return list<Request> the requests sent to the Messages API */
    public static function anthropicRequests(): array
    {
        return array_values(array_map(
            fn (array $pair): Request => $pair[0],
            Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com'))->all(),
        ));
    }
}
