<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Widgets;

use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Clusters\Providers\Pages\PixelforgeUsage;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Actions\ReconcileTakeAction;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\Take;
use Filament\Actions\Action;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Reactive;

/**
 * The **Find takes** drill-down of Providers › Pixelforge usage (A04, D-034), as a nested Filament table (#163): one
 * row per generation in the four groups, the take linked to its view, and **Reconcile now** (D-058, the A06 action,
 * #136, configured by ReconcileTakeAction::configure) on a row whose take is still open. Never on an orphan success: its take failed free, nothing to reconcile.
 * The rows come from the page (a reactive prop the client cannot write); this widget never calls Pixelforge.
 */
final class PixelforgeDiscrepancies extends TableWidget
{
    public const array GROUPS = ['theirs_not_ours', 'ours_not_settled', 'amount_differs', 'orphan_success'];

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /** @var array{date: string, truncated: bool, groups: array<string, list<array{generation: string, take: string|null, theirs: string, ours: string}>>}|null */
    #[Reactive]
    public ?array $findResult = null;

    /** @var list<string>|null memoized by openTakeIds(), never client state */
    private ?array $openTakeIds = null;

    public static function canView(): bool
    {
        return PixelforgeUsage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->heading(fn (): string => __('console.pixelforge.find_heading', ['date' => $this->findResult['date'] ?? '']))
            ->description(fn (): mixed => ($this->findResult['truncated'] ?? false) ? __('console.pixelforge.truncated') : null)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('console.pixelforge.nothing'))
            ->columns([
                TextColumn::make('group_label')->label(__('console.pixelforge.group'))->badge()
                    ->color(fn (array $record): string => $record['group'] === 'orphan_success' ? 'gray' : 'warning'),
                TextColumn::make('generation')->label(__('console.pixelforge.generation'))->fontFamily('mono')->copyable(),
                TextColumn::make('take')->label(__('console.pixelforge.take'))->fontFamily('mono')->placeholder('-')
                    ->url(fn (array $record): ?string => $record['take'] !== null ? TakeResource::getUrl('view', ['record' => $record['take']]) : null),
                TextColumn::make('theirs')->label(__('console.pixelforge.their_cost'))->fontFamily('mono')->alignment(Alignment::End),
                TextColumn::make('ours')->label(__('console.pixelforge.our_cost'))->fontFamily('mono')->alignment(Alignment::End),
            ])
            ->recordActions([
                ReconcileTakeAction::configure(Action::make('reconcile'))
                    ->visible(fn (array $record): bool => $this->canReconcile($record))
                    ->action(function (array $record): void {
                        $take = is_string($record['take'] ?? null) ? Take::query()->find($record['take']) : null;

                        if ($take !== null) {
                            ReconcileTakeAction::reconcile($take);
                        }
                    }),
            ]);
    }

    /**
     * Reconcile now is offered on a row with a known take that is not final, outside the orphan group.
     *
     * @param  array<string, mixed>  $record
     */
    public function canReconcile(array $record): bool
    {
        if (($record['group'] ?? null) === 'orphan_success' || ! is_string($record['take'] ?? null)) {
            return false;
        }

        return in_array($record['take'], $this->openTakeIds(), true);
    }

    /**
     * The non-final takes of the drill-down, read with one query and memoized per request (no query per row).
     *
     * @return list<string>
     */
    private function openTakeIds(): array
    {
        if ($this->openTakeIds !== null) {
            return $this->openTakeIds;
        }

        $ids = [];

        foreach ($this->findResult['groups'] ?? [] as $group => $rows) {
            foreach ($group === 'orphan_success' ? [] : $rows as $row) {
                if ($row['take'] !== null) {
                    $ids[] = $row['take'];
                }
            }
        }

        /** @var list<string> $open */
        $open = $ids === [] ? [] : Take::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $this->openTakeIds = $open;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (self::GROUPS as $group) {
            foreach ($this->findResult['groups'][$group] ?? [] as $index => $row) {
                $rows[] = [
                    ArrayRecord::getKeyName() => $group.'-'.$index,
                    'group' => $group,
                    'group_label' => __('console.pixelforge.groups.'.$group),
                    ...$row,
                ];
            }
        }

        return $rows;
    }
}
