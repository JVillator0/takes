<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Filament\Admin\Resources\Customers\Actions\AdjustCreditsAction;
use App\Filament\Admin\Resources\Customers\Actions\RestrictCustomerAction;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Resources\Pages\ViewRecord;

/**
 * A05: one customer's money (available, on hold, lifetime spend, profit, takes) with the ledger and takes tabs.
 */
final class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        $customer = $this->getRecord();

        return $customer instanceof Customer ? $customer->name : __('console.customers.single');
    }

    protected function getHeaderActions(): array
    {
        return [
            AdjustCreditsAction::make(),
            RestrictCustomerAction::make(),
        ];
    }
}
