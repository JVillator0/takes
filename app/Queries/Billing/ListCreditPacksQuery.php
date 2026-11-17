<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\CreditPackDTO;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\Config;
use UnexpectedValueException;

/**
 * The credit packs a customer can buy (`takes.purchase_packs_usd`, spec 3-03
 * §5). Priced with Nanodollars::fromWholeDollars, the same conversion
 * PurchaseCreditsAction charges with, so the page and the ledger agree.
 */
final readonly class ListCreditPacksQuery
{
    /**
     * @return list<CreditPackDTO>
     */
    public function execute(): array
    {
        $packs = [];

        foreach (Config::array('takes.purchase_packs_usd') as $usd) {
            if (! is_int($usd)) {
                throw new UnexpectedValueException('takes.purchase_packs_usd must hold whole dollars as integers.');
            }

            $packs[] = CreditPackDTO::fromArray([
                'id' => (string) $usd,
                'amount' => Nanodollars::fromWholeDollars($usd),
                'tax' => Nanodollars::zero(),
            ]);
        }

        return $packs;
    }
}
