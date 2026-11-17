<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Billing\UsageUnitsDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\DTOs\Suggestions\TakeChangeResultDTO;
use App\Enums\Billing\UsageKind;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Exceptions\Suggestions\InvalidProposalException;
use App\Support\Suggestions\TakeChangeProposalParser;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The one paid model call of "Describe a change" (spec 8 "Call"): single
 * shot, 20 s timeout. Callers run it with no lock held and no transaction
 * open (money rule M-16; CreateSuggestionAction calls it between T1 and T2).
 * Never throws for a provider problem: every failure becomes a failure
 * reason, so T2 voids the hold instead of leaving it to the sweeper.
 *
 * Units come only from the response's usage block (spec 8, D-055):
 * `input_text` = every input token the API reports (no prompt caching is
 * requested, so the cache buckets are zero) and `output` = output tokens.
 */
final readonly class ProposeTakeChangeAction
{
    public function __construct(private TakeChangeProposalParser $parser) {}

    public function execute(TakeChangeInputDTO $input): TakeChangeResultDTO
    {
        try {
            $response = (new TakeChangeAgent)->prompt($input->toMessage());
        } catch (Throwable $exception) {
            Log::warning('suggestions.call_failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return TakeChangeResultDTO::failed(SuggestionFailureReason::ProviderError);
        }

        $units = new UsageUnitsDTO([
            UsageKind::InputText->value => $response->usage->inputTokens,
            UsageKind::Output->value => $response->usage->outputTokens,
        ]);
        $externalId = $this->messageId($response);
        $finishReason = $response->steps->last()?->finishReason;

        if ($finishReason === FinishReason::ContentFilter) {
            return $this->failed(SuggestionFailureReason::Refusal, $units, $externalId);
        }

        if ($finishReason !== FinishReason::Stop || ! $response instanceof StructuredAgentResponse) {
            return $this->failed(SuggestionFailureReason::InvalidOutput, $units, $externalId, $finishReason?->value);
        }

        try {
            $proposal = $this->parser->parse($response->structured, $input);
        } catch (InvalidProposalException $exception) {
            return $this->failed(SuggestionFailureReason::InvalidOutput, $units, $externalId, $exception->getMessage());
        }

        return TakeChangeResultDTO::proposed($proposal, $units, $externalId);
    }

    /**
     * Failed = free for the customer (M-13); the provider still billed us
     * these tokens, so they are logged for the admin (never shown to customers).
     */
    private function failed(SuggestionFailureReason $reason, UsageUnitsDTO $units, ?string $externalId, ?string $detail = null): TakeChangeResultDTO
    {
        Log::warning('suggestions.call_unusable', [
            'reason' => $reason->value,
            'detail' => $detail,
            'external_id' => $externalId,
            'tokens' => $units->tokens,
        ]);

        return TakeChangeResultDTO::failed($reason, $units, $externalId);
    }

    /** The provider's message id (`msg_…`), kept on the usage record for reconciliation. */
    private function messageId(AgentResponse $response): ?string
    {
        $id = $response->raw?->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
