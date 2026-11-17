<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\ImagePersistOutcome;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies a succeeded take's PNG from Pixelforge's signed URL (valid 24 h)
 * to the default disk, `takes/{customer}/{take}.png` (D-020, D-067): a
 * private local disk locally, object storage in production, never `public`.
 *
 * Only downloads from the configured image host (SSRF guard), streams with a
 * 20 s timeout and a 20 MB cap enforced while reading, and keeps the file
 * only when the response says `image/png` and the bytes start with the PNG
 * signature. No transaction is open during the download (M-16).
 */
final readonly class PersistTakeImageAction
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const int CHUNK_BYTES = 65_536;

    /**
     * @throws ConnectionException|RequestException|RuntimeException on a connection error, a 5xx, 408 or 429, or a failed write (the job retries)
     */
    public function execute(Take $take): ImagePersistOutcome
    {
        $take->refresh();

        if ($take->image_path !== null) {
            return ImagePersistOutcome::AlreadyStored;
        }

        if ($take->status !== TakeStatus::Succeeded || $take->image_url === null) {
            return ImagePersistOutcome::NoImage;
        }

        $host = parse_url($take->image_url, PHP_URL_HOST);
        $scheme = parse_url($take->image_url, PHP_URL_SCHEME);

        if (! is_string($host) || $scheme !== 'https' || strcasecmp($host, config()->string('pixelforge.image_host')) !== 0) {
            Log::warning('Take image not persisted: the URL is not on the image host.', ['take_id' => $take->id, 'host' => $host]);

            return ImagePersistOutcome::WrongHost;
        }

        $response = Http::timeout(config()->integer('pixelforge.images.timeout_seconds'))
            ->connectTimeout(3)
            ->withOptions(['stream' => true, 'allow_redirects' => false])
            ->get($take->image_url);

        // 5xx, 408 and 429 are transient: throw so the job retries with its backoff.
        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            $response->throw();
        }

        if (! $response->successful()) {
            Log::warning('Take image not persisted: the provider URL answered an error.', ['take_id' => $take->id, 'http_status' => $response->status()]);

            return ImagePersistOutcome::Unavailable;
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if ($contentType !== 'image/png') {
            Log::warning('Take image not persisted: not a PNG response.', ['take_id' => $take->id, 'content_type' => $contentType]);

            return ImagePersistOutcome::NotPng;
        }

        $body = $response->toPsrResponse()->getBody();
        $maxBytes = config()->integer('pixelforge.images.max_bytes');
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(self::CHUNK_BYTES);

            if (strlen($contents) > $maxBytes) {
                $body->close();
                Log::warning('Take image not persisted: larger than the limit.', ['take_id' => $take->id, 'max_bytes' => $maxBytes]);

                return ImagePersistOutcome::TooLarge;
            }
        }

        if (! str_starts_with($contents, self::PNG_SIGNATURE)) {
            Log::warning('Take image not persisted: the bytes are not a PNG.', ['take_id' => $take->id]);

            return ImagePersistOutcome::NotPng;
        }

        $path = "takes/{$take->customer_id}/{$take->id}.png";

        if (! Storage::put($path, $contents)) {
            throw new RuntimeException("Could not write the image of take {$take->id}.");
        }

        // Not a status write (M-11): only the persisted copy's path.
        Take::query()->whereKey($take->id)->whereNull('image_path')->update(['image_path' => $path]);

        return ImagePersistOutcome::Stored;
    }
}
