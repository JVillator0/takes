<?php

declare(strict_types=1);

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\BillingDemo;

/*
 * D-023 / money rule M-23 on the billing page and the invoice: customer props
 * carry prices only (charged, on hold, spend by feature), never provider
 * names, provider cost, tokens, rates, model ids, markup or margin. The demo
 * dataset has settled image and AI usage, so every such field exists in the
 * database and would show up here if a resource leaked it.
 */

const FORBIDDEN_BILLING_WORDS = '/pixelforge|anthropic|claude|haiku|provider|cost|token|rate|markup|margin|units|external_id|billing_date|gen_/i';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Every key (nested ones too) and scalar value of the page props, except the
 * lang copy (scanned by LandingPageTest), a shared prop owned elsewhere.
 *
 * @param  array<array-key, mixed>  $props
 */
function billingPropsText(array $props, bool $root = true): string
{
    if ($root) {
        unset($props['copy']);
    }

    $text = [];

    foreach ($props as $key => $value) {
        $text[] = (string) $key;
        $text[] = is_array($value) ? billingPropsText($value, false) : (is_scalar($value) ? (string) $value : '');
    }

    return implode(' ', $text);
}

it('never sends provider data to the billing page', function (): void {
    ['customer' => $ana] = BillingDemo::ana();

    $props = $this->actingAs($ana, 'customer')
        ->get(route('billing.show'))
        ->assertOk()
        ->inertiaProps();

    expect(billingPropsText($props))->not->toMatch(FORBIDDEN_BILLING_WORDS)
        ->and($props['activity']['rows'])->toHaveCount(11);
});

it('never sends provider data to the invoice', function (): void {
    ['customer' => $ana] = BillingDemo::ana();

    $props = $this->actingAs($ana, 'customer')
        ->get(route('billing.invoices.show', Invoice::query()->sole()))
        ->assertOk()
        ->inertiaProps();

    expect(billingPropsText($props))->not->toMatch(FORBIDDEN_BILLING_WORDS);
});
