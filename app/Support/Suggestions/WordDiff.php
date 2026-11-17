<?php

declare(strict_types=1);

namespace App\Support\Suggestions;

/**
 * The prompt word diff of a proposal (spec 8 step 3, P07): the server
 * computes it so the drawer only renders segments (no logic in templates).
 * Longest common subsequence over words, whitespace kept with each word.
 */
final class WordDiff
{
    /** Above this many LCS cells the diff degrades to "all removed, all added". */
    private const int MAX_CELLS = 1_000_000;

    /**
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    public static function segments(string $before, string $after): array
    {
        $old = self::words($before);
        $new = self::words($after);
        $oldCount = count($old);
        $newCount = count($new);

        if ($oldCount * $newCount > self::MAX_CELLS) {
            return self::merge([
                ...array_map(fn (string $word): array => ['type' => 'removed', 'text' => $word], $old),
                ...array_map(fn (string $word): array => ['type' => 'added', 'text' => $word], $new),
            ]);
        }

        // lengths[i][j] = LCS length of old[i..] and new[j..].
        $lengths = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = self::same($old[$i], $new[$j])
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $segments = [];
        $i = 0;
        $j = 0;

        while ($i < $oldCount && $j < $newCount) {
            if (self::same($old[$i], $new[$j])) {
                $segments[] = ['type' => 'same', 'text' => $new[$j]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $segments[] = ['type' => 'removed', 'text' => $old[$i++]];
            } else {
                $segments[] = ['type' => 'added', 'text' => $new[$j++]];
            }
        }

        while ($i < $oldCount) {
            $segments[] = ['type' => 'removed', 'text' => $old[$i++]];
        }

        while ($j < $newCount) {
            $segments[] = ['type' => 'added', 'text' => $new[$j++]];
        }

        return self::merge($segments);
    }

    /**
     * Words with their trailing whitespace ("dusk, " stays one token).
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /** Equal ignoring the trailing whitespace, so a reflowed line is not a change. */
    private static function same(string $a, string $b): bool
    {
        return rtrim($a) === rtrim($b);
    }

    /**
     * @param  list<array{type: 'same'|'added'|'removed', text: string}>  $segments
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function merge(array $segments): array
    {
        $merged = [];
        $current = null;

        foreach ($segments as $segment) {
            if ($current !== null && $current['type'] === $segment['type']) {
                $current = ['type' => $current['type'], 'text' => $current['text'].$segment['text']];

                continue;
            }

            if ($current !== null) {
                $merged[] = $current;
            }

            $current = $segment;
        }

        if ($current !== null) {
            $merged[] = $current;
        }

        return $merged;
    }
}
