<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Actions;

use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "View JSON" (A11, 2-03): the raw payload Pixelforge sent for a delivery's event, pretty-printed in a read-only
 * textarea. The textarea wraps long lines (a signed `image_url` runs to hundreds of characters) and scrolls inside
 * its fixed height, so nothing spills over the modal or the table behind it (#163); its content is escaped like
 * any field value. A delivery without a stored event (rejected before recording) has nothing to show.
 */
final class ViewPayloadAction
{
    public const int ROWS = 20;

    public static function make(): Action
    {
        return Action::make('viewPayload')
            ->label(__('console.webhooks.view_payload'))
            ->icon(Heroicon::OutlinedCodeBracket)
            ->link()
            ->visible(fn (WebhookDelivery $record): bool => $record->webhook_event_id !== null)
            ->modalHeading(fn (WebhookDelivery $record): string => $record->event_id)
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('console.webhooks.close'))
            ->fillForm(fn (WebhookDelivery $record): array => ['payload' => self::json($record)])
            ->schema([
                Textarea::make('payload')
                    ->hiddenLabel()
                    ->label(__('console.webhooks.payload'))
                    ->readOnly()
                    ->dehydrated(false)
                    ->rows(self::ROWS)
                    ->extraInputAttributes([
                        'data-testid' => 'webhook-payload',
                        'spellcheck' => 'false',
                        'wrap' => 'soft',
                        // Wrap anywhere (a URL has no spaces) and scroll vertically inside the fixed height.
                        'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; line-height: 1.25rem; white-space: pre-wrap; overflow-wrap: anywhere; overflow-y: auto; resize: vertical;',
                    ]),
            ]);
    }

    public static function json(WebhookDelivery $delivery): string
    {
        return json_encode($delivery->webhookEvent->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
