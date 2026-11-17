<?php

declare(strict_types=1);

use App\Actions\Suggestions\CreateSuggestionAction;
use App\Exceptions\Billing\TransactionNotAllowedException;
use App\Models\Suggestion;
use App\Models\UsageRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Suggestions;

/*
 * CreateSuggestionAction calls the model between T1 and T2 with no lock held
 * (money rule M-16), so it refuses to run inside a caller's transaction. Here
 * the real transaction level is 0 (no RefreshDatabase wrapper).
 */

it('refuses to run inside a caller\'s transaction, before any call or hold', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $customer = Suggestions::customer();
    $take = Suggestions::baseTake($customer);

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => DB::transaction(fn () => app(CreateSuggestionAction::class)->execute($customer, $take, Suggestions::request())))
        ->toThrow(TransactionNotAllowedException::class)
        ->and(Suggestions::anthropicRequests())->toHaveCount(0)
        ->and(Suggestion::query()->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0);

    expect(app(CreateSuggestionAction::class)->execute($customer, $take, Suggestions::request())->status->value)->toBe('proposed');
});
