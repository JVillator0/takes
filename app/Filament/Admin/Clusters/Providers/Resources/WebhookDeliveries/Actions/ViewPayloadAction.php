<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Actions;

use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * "View JSON" (A11, 2-03): the raw payload Pixelforge sent for a delivery's event, read-only and escaped. A
 * delivery without a stored event (rejected before recording) has nothing to show.
 */
final class ViewPayloadAction
{
    public static function make(): Action
    {
        return Action::make('viewPayload')
            ->label(__('console.webhooks.view_payload'))
            ->icon(Heroicon::OutlinedCodeBracket)
            ->link()
            ->visible(fn (WebhookDelivery $record): bool => $record->webhook_event_id !== null)
            ->modalHeading(fn (WebhookDelivery $record): string => $record->event_id)
            ->modalSubmitAction(false)
            ->modalContent(fn (WebhookDelivery $record): View => view('filament.admin.webhook-payload', [
                'json' => json_encode($record->webhookEvent->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]));
    }
}
