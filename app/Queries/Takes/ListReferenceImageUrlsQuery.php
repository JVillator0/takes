<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Models\Take;
use App\Support\Takes\TakeImageUrl;

/**
 * Turns reference URLs that point at one of the customer's own take images
 * into a URL that works now (spec 4 remix source order, spec 8 References).
 * Such a reference is a short-lived URL issued earlier: a temporary URL of
 * the persisted copy (`takes/{customer}/{take}.png`, 60 min) or Pixelforge's
 * signed image URL (24 h). Its path is the stable identity of the take image
 * (the customer and take ids, or the generation id), so the stored URL is
 * never trusted as is: it is re-issued through TakeImageUrl::forReference
 * (persisted copy, then the provider URL while valid) whenever references
 * are attached (create, remix, Try again, AI apply) or shown (the drawer).
 * Any other URL (the customer's own http(s) references) passes through
 * unchanged.
 *
 * A take image with no usable URL any more is refused, so a take is never
 * created and held with a dead reference; with `$dropUnavailable` it is left
 * out instead (display, and checking a proposal before apply).
 */
final class ListReferenceImageUrlsQuery
{
    /** `…/takes/{customer}/{take}.png`, the persisted copy's path on any disk. */
    private const string PERSISTED_PATH = '#/takes/(\d+)/([0-9a-z]{26})\.png$#i';

    /** `/images/{gen_id}.png` on the configured image host. */
    private const string PROVIDER_PATH = '#^/images/(gen_[A-Za-z0-9]+)\.png$#';

    /**
     * @param  list<string>  $references
     * @return list<string>
     *
     * @throws BaseImageUnavailableException when a reference names a take image that has no usable URL (unless dropped)
     */
    public function execute(int $customerId, array $references, bool $dropUnavailable = false): array
    {
        $urls = [];

        foreach ($references as $url) {
            $take = $this->takeFor($customerId, $url);

            if ($take === null) {
                $urls[] = $url;

                continue;
            }

            $reissued = TakeImageUrl::forReference($take);

            if ($reissued !== null) {
                $urls[] = $reissued;
            } elseif (! $dropUnavailable) {
                throw BaseImageUnavailableException::forTake($take->id);
            }
        }

        return $urls;
    }

    /** The customer's take whose image this URL is, or null for any other URL. */
    private function takeFor(int $customerId, string $url): ?Take
    {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($path)) {
            return null;
        }

        if (preg_match(self::PERSISTED_PATH, $path, $persisted) === 1 && (int) $persisted[1] === $customerId) {
            return Take::query()->whereKey(strtolower($persisted[2]))->where('customer_id', $customerId)->first();
        }

        if (is_string($host)
            && strcasecmp($host, config()->string('pixelforge.image_host')) === 0
            && preg_match(self::PROVIDER_PATH, $path, $provider) === 1) {
            return Take::query()->where('pixelforge_id', $provider[1])->where('customer_id', $customerId)->first();
        }

        return null;
    }
}
