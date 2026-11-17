<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Token-based source scanning for rules Pest's arch API cannot express
 * (functions inside non-class files, literals, casts).
 *
 * Scanning tokens instead of grepping avoids false positives in comments,
 * strings and identifiers such as `$roundTrip` or `->round()`.
 */
final class SourceScan
{
    /**
     * Every `.php` file under the given paths (relative to the project root).
     * Missing paths are skipped, so rules pass while a namespace is still empty.
     *
     * @param  list<string>  $paths
     * @return list<string> absolute file paths, sorted
     */
    public static function phpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (is_file($absolute) && str_ends_with($absolute, '.php')) {
                $files[] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Float usages that are forbidden in money code: the `float` type, float
     * casts, float literals and the float-producing helpers.
     *
     * @param  list<string>  $paths
     * @return list<string> human-readable violations ("file:line description")
     */
    public static function floatViolations(array $paths): array
    {
        $forbiddenCalls = ['floatval', 'doubleval', 'round', 'number_format'];
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => $token) {
                [$id, $text, $line] = $token;
                $lower = strtolower($text);

                $description = match (true) {
                    $id === T_DOUBLE_CAST => "float cast {$text}",
                    $id === T_DNUMBER => "float literal {$text}",
                    $id === T_STRING && $lower === 'float' && ! self::isMemberAccess($tokens, $index) => 'float type',
                    $id === T_STRING && in_array($lower, $forbiddenCalls, true) && self::isFunctionCall($tokens, $index) => "{$lower}()",
                    default => null,
                };

                if ($description !== null) {
                    $violations[] = self::relative($file).":{$line} {$description}";
                }
            }
        }

        return $violations;
    }

    /**
     * Global function calls by name, e.g. `env(` (not `->env(` or `::env(`).
     *
     * @param  list<string>  $paths
     * @param  list<string>  $functions
     * @return list<string>
     */
    public static function functionCalls(array $paths, array $functions): array
    {
        $functions = array_map(strtolower(...), $functions);
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => [$id, $text, $line]) {
                $name = strtolower(ltrim($text, '\\'));

                if (($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED)
                    && in_array($name, $functions, true)
                    && self::isFunctionCall($tokens, $index)) {
                    $violations[] = self::relative($file).":{$line} {$name}()";
                }
            }
        }

        return $violations;
    }

    /**
     * Literal first arguments of `env('KEY', ...)` calls.
     *
     * @param  list<string>  $paths
     * @return list<string> unique keys, sorted
     */
    public static function envKeys(array $paths): array
    {
        $keys = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => [$id, $text]) {
                if (($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED)
                    && strtolower(ltrim($text, '\\')) === 'env'
                    && self::isFunctionCall($tokens, $index)
                    && ($tokens[$index + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                    $keys[] = trim($tokens[$index + 2][1], '\'"');
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}> tokens without whitespace/comments;
     *                                                single-char tokens get id 0
     */
    private static function significantTokens(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $tokens = [];
        $line = 1;

        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1], $token[2]];

                continue;
            }

            $tokens[] = [0, $token, $line];
        }

        return $tokens;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function isFunctionCall(array $tokens, int $index): bool
    {
        return ($tokens[$index + 1][1] ?? null) === '('
            && ! self::isMemberAccess($tokens, $index)
            && ($tokens[$index - 1][0] ?? null) !== T_FUNCTION;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function isMemberAccess(array $tokens, int $index): bool
    {
        return in_array($tokens[$index - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    private static function relative(string $file): string
    {
        return ltrim(str_replace(base_path(), '', $file), DIRECTORY_SEPARATOR);
    }
}
