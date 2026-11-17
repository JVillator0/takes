<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Schemas;

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\ProjectResource;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Filament\Admin\Support\Money;
use App\Models\Take;
use App\Models\UsageRecord;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A06 (normative): one take, to prove exactness and debug its life. Money is printed as raw integer nanodollars on
 * purpose (the interview walkthrough checks cost × 1.25 by hand), next to the usage record's state and the one
 * ledger row a take writes (its charge; the hold is the open usage record, D-024). Webhook deliveries are the
 * relation manager under this schema.
 */
final class TakeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                self::summary(),
                self::money(),
                self::timeline(),
                self::failure(),
            ]);
    }

    private static function summary(): Section
    {
        return Section::make(__('console.takes.sections.summary'))
            ->columns(2)
            ->schema([
                TextEntry::make('id')->label(__('console.takes.fields.id'))->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('console.takes.status'))->badge(),
                TextEntry::make('pixelforge_id')->label(__('console.takes.fields.pixelforge_id'))->fontFamily('mono')->placeholder('-')->copyable(),
                TextEntry::make('idempotency_key')->label(__('console.takes.fields.idempotency_key'))->fontFamily('mono')->copyable(),
                TextEntry::make('customer.name')->label(__('console.common.customer')),
                TextEntry::make('project.name')->label(__('console.common.project'))
                    ->url(fn (Take $record): string => ProjectResource::getUrl('view', ['record' => $record->project_id])),
                TextEntry::make('source')->label(__('console.takes.source'))->badge(),
                TextEntry::make('size')->label(__('console.takes.size_quality'))
                    ->formatStateUsing(fn (Take $record): string => $record->size->value.' · '.$record->quality->value),
                TextEntry::make('parent_take_id')->label(__('console.takes.fields.parent'))->fontFamily('mono')->placeholder('-')
                    ->url(fn (Take $record): ?string => $record->parent_take_id === null ? null : TakeResource::getUrl('view', ['record' => $record->parent_take_id])),
                TextEntry::make('suggestion_id')->label(__('console.takes.fields.suggestion'))->fontFamily('mono')->placeholder('-'),
                TextEntry::make('prompt')->label(__('console.takes.fields.prompt'))->columnSpanFull(),
                TextEntry::make('reference_images')->label(__('console.takes.fields.reference_images'))
                    ->state(fn (Take $record): int => count($record->reference_images)),
                TextEntry::make('attention_reason')->label(__('console.takes.fields.attention_reason'))
                    ->formatStateUsing(fn (Take $record): string => $record->attention_reason->value ?? '-')
                    ->placeholder('-')->color('danger'),
            ]);
    }

    private static function money(): Section
    {
        return Section::make(__('console.takes.sections.money'))
            ->columns(1)
            ->schema([
                self::usageEntry('estimate_cost', __('console.takes.fields.estimate_cost'),
                    fn (UsageRecord $usage): string => Money::nanos($usage->min_cost_nanos).' / '.Money::nanos($usage->max_cost_nanos)),
                self::usageEntry('estimate_charge', __('console.takes.fields.estimate_charge'),
                    fn (UsageRecord $usage): string => Money::nanos($usage->min_charge_nanos).' / '.Money::nanos($usage->max_charge_nanos)),
                self::usageEntry('tokens', __('console.takes.fields.tokens'), fn (UsageRecord $usage): string => implode(' / ', array_map(
                    fn (?int $tokens): string => $tokens === null ? '-' : Money::grouped($tokens),
                    [$usage->input_text_tokens, $usage->input_image_tokens, $usage->output_tokens, $usage->reasoning_tokens],
                ))),
                self::usageEntry('provider_cost', __('console.takes.fields.provider_cost'), fn (UsageRecord $usage): string => Money::nanos($usage->provider_cost_nanos)),
                TextEntry::make('charge')
                    ->label(fn (Take $record): string => __('console.takes.fields.charge', ['bps' => Money::grouped($record->usageRecord->markup_bps ?? 0)]))
                    ->state(fn (Take $record): string => Money::nanos($record->usageRecord?->charge_nanos))
                    ->inlineLabel()->alignEnd()->fontFamily('mono'),
                self::usageEntry('rates', __('console.takes.fields.rates'), fn (UsageRecord $usage): string => collect($usage->rates)
                    ->map(fn (int $rate, string $kind): string => $kind.' '.Money::grouped($rate))
                    ->implode(' · ')),
                TextEntry::make('usage_status')->label(__('console.takes.fields.usage_status'))
                    ->state(fn (Take $record): mixed => $record->usageRecord?->status)
                    ->badge()->inlineLabel()->alignEnd()->placeholder('-'),
                self::usageEntry('held', __('console.takes.fields.held'), fn (UsageRecord $usage): string => $usage->status === UsageStatus::Open
                    ? Money::nanos($usage->max_charge_nanos)
                    : '-'),
                self::usageEntry('billing_date', __('console.takes.fields.billing_date'), fn (UsageRecord $usage): string => $usage->billing_date?->toDateString() ?? '-'),
                self::usageEntry('charge_entry', __('console.takes.fields.charge_entry'), function (UsageRecord $usage): string {
                    $entry = $usage->chargeEntry;

                    return $entry === null ? '-' : __('console.takes.fields.charge_entry_value', ['id' => $entry->id, 'amount' => Money::nanos($entry->amount_nanos)]);
                }),
            ]);
    }

    private static function timeline(): Section
    {
        return Section::make(__('console.takes.sections.timeline'))
            ->columns(2)
            ->schema([
                TextEntry::make('created_at')->label(__('console.takes.fields.created_at'))->dateTime('Y-m-d H:i:s \U\T\C'),
                TextEntry::make('submitted_at')->label(__('console.takes.fields.submitted_at'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder('-'),
                TextEntry::make('started_at')->label(__('console.takes.fields.started_at'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder('-'),
                TextEntry::make('completed_at')->label(__('console.takes.fields.completed_at'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder('-'),
                TextEntry::make('last_event_at')->label(__('console.takes.fields.last_event_at'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder('-'),
                TextEntry::make('submit_attempts')->label(__('console.takes.fields.submit_attempts')),
                TextEntry::make('last_submit_error')->label(__('console.takes.fields.last_submit_error'))->placeholder('-')->columnSpanFull(),
            ]);
    }

    private static function failure(): Section
    {
        return Section::make(__('console.takes.sections.failure'))
            ->visible(fn (Take $record): bool => $record->status === TakeStatus::Failed)
            ->columns(2)
            ->schema([
                TextEntry::make('failure_code')
                    ->label(__('console.takes.fields.failure_code'))
                    ->formatStateUsing(fn (Take $record): string => $record->failure_code->value ?? '-')
                    ->badge()->color('danger'),
                TextEntry::make('failure_message')->label(__('console.takes.fields.failure_message'))->placeholder('-'),
            ]);
    }

    /**
     * A money row read from the take's usage record, right-aligned like A06; `-` before the record exists.
     *
     * @param  Closure(UsageRecord): string  $value
     */
    private static function usageEntry(string $name, string $label, Closure $value): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state(fn (Take $record): string => $record->usageRecord === null ? '-' : $value($record->usageRecord))
            ->inlineLabel()
            ->alignEnd()
            ->fontFamily('mono');
    }
}
