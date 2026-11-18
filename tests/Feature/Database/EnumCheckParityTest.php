<?php

declare(strict_types=1);

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Enums\Billing\PurchaseStatus;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Enums\Webhooks\WebhookOutcome;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The PHP backed enum is the source of every varchar + CHECK list (spec 3-02).
 * The migrations spell the lists out (history must not change when an enum
 * does), so this test keeps both sides identical.
 *
 * @return list<string>
 */
function checkConstraintValues(string $constraint): array
{
    $definition = DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);

    expect($definition)->toBeString("Missing CHECK constraint [{$constraint}].");

    // A one-value IN list is stored as `= 'value'::text`, longer ones as varchar arrays.
    preg_match_all("/'([^']+)'::(?:character varying|text)/", (string) $definition, $matches);

    $values = $matches[1];
    sort($values);

    return $values;
}

it('keeps each CHECK list identical to its enum', function (string $constraint, string $enum): void {
    /** @var class-string<BackedEnum> $enum */
    $values = array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    sort($values);

    expect(checkConstraintValues($constraint))->toBe($values);
})->with([
    ['takes_status_check', TakeStatus::class],
    ['takes_source_check', TakeSource::class],
    ['takes_size_check', TakeSize::class],
    ['takes_quality_check', TakeQuality::class],
    ['takes_failure_code_check', FailureCode::class],
    ['takes_last_submit_error_kind_check', SubmitErrorKind::class],
    ['takes_attention_reason_check', AttentionReason::class],
    ['suggestions_status_check', SuggestionStatus::class],
    ['suggestions_failure_reason_check', SuggestionFailureReason::class],
    ['webhook_deliveries_outcome_check', WebhookOutcome::class],
    ['purchases_status_check', PurchaseStatus::class],
    ['invoices_status_check', InvoiceStatus::class],
    ['usage_records_provider_check', Provider::class],
    ['usage_records_status_check', UsageStatus::class],
    ['ledger_entries_type_check', LedgerEntryType::class],
]);

it('only allows billable types that are in the morph map', function (): void {
    expect(checkConstraintValues('usage_records_billable_type_check'))->toBe(['suggestion', 'take'])
        ->and(Relation::getMorphedModel('take'))->not->toBeNull()
        ->and(Relation::getMorphedModel('suggestion'))->not->toBeNull();
});
