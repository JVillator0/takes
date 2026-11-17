<?php

declare(strict_types=1);

use App\Enums\Auth\DemoAccount;
use App\Enums\Auth\DenialReason;
use Illuminate\Support\Facades\Lang;

/*
| D-057: customer copy lives in lang/en only. Every enum case the UI shows
| has its line, so the code fallbacks never reach a customer.
*/

it('has a lang/en/denials.php line for every DenialReason', function (DenialReason $reason): void {
    expect(Lang::has('denials.'.$reason->value))->toBeTrue()
        ->and($reason->message())->not->toBe($reason->value);
})->with(DenialReason::cases());

it('has a lang/en/demo.php label for every DemoAccount', function (DemoAccount $account): void {
    expect(Lang::has('demo.accounts.'.$account->value))->toBeTrue();
})->with(DemoAccount::cases());

it('interpolates the support email into account_restricted', function (): void {
    config(['takes.support_email' => 'help@takes.test']);

    expect(DenialReason::AccountRestricted->message())->toBe('Your account is restricted. Email help@takes.test for help.');
});
