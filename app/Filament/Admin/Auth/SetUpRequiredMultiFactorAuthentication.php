<?php

declare(strict_types=1);

namespace App\Filament\Admin\Auth;

use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication as BaseSetUpPage;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;

/**
 * Filament's "set up MFA" page, refused to admins the panel's MFA closure exempts (D-013, D-072). The route exists
 * for every admin because the closure answers true at route registration; without this, an evaluator signed in as
 * the public auditor could enrol an authenticator app and lock every other evaluator out of the shared login.
 */
final class SetUpRequiredMultiFactorAuthentication extends BaseSetUpPage
{
    #[Unexposed]
    public function mount(): void
    {
        if (! self::isRequiredForCurrentAdmin()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        parent::mount();
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return self::isRequiredForCurrentAdmin() ? parent::content($schema) : $schema->components([]);
    }

    private static function isRequiredForCurrentAdmin(): bool
    {
        return Filament::getCurrentPanel()?->isMultiFactorAuthenticationRequired() === true;
    }
}
