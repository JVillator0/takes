<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Token-based source scanning for rules Pest's arch API cannot express
 * (functions inside non-class files, literals, casts, call arguments).
 *
 * Scanning tokens instead of grepping avoids false positives in comments,
 * strings and identifiers such as `$roundTrip` or `->round()`. Covered by
 * tests/Unit/SourceScanTest.php.
 *
 * Known blind spots (by design, keep the scanner explainable): functions
 * passed as string callables (`array_map('round', ...)`) and calls through
 * variables (`$fn = 'round'; $fn()`).
 */
final class SourceScan
{
    /** Float-producing helpers forbidden in money code (spec 1-01). */
    public const array FLOAT_FUNCTIONS = ['floatval', 'doubleval', 'round', 'number_format'];

    /**
     * Every `.php` file under the given paths. Relative paths resolve against
     * the project root; absolute paths are used as-is. Missing paths are
     * skipped, so rules pass while a namespace is still empty.
     *
     * @param  list<string>  $paths
     * @return list<string> absolute file paths, sorted
     */
    public static function phpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : self::root().DIRECTORY_SEPARATOR.$path;

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
     * casts, float literals and the float-producing helpers (called bare,
     * fully qualified or imported with `use function`).
     *
     * @param  list<string>  $paths
     * @return list<string> human-readable violations ("file:line description")
     */
    public static function floatViolations(array $paths): array
    {
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => [$id, $text, $line]) {
                $description = match (true) {
                    $id === T_DOUBLE_CAST => "float cast {$text}",
                    $id === T_DNUMBER => "float literal {$text}",
                    $id === T_STRING && strtolower($text) === 'float' && ! self::isMemberAccess($tokens, $index) => 'float type',
                    default => self::functionReference($tokens, $index, self::FLOAT_FUNCTIONS),
                };

                if ($description !== null) {
                    $violations[] = self::relative($file).":{$line} {$description}";
                }
            }
        }

        return $violations;
    }

    /**
     * References to global functions by name: calls (`env(`, `\env(`) and
     * `use function env [as alias]` imports. Method and static calls with the
     * same name (`->env(`, `::env(`) are not references.
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

            foreach (array_keys($tokens) as $index) {
                $description = self::functionReference($tokens, $index, $functions);

                if ($description !== null) {
                    $violations[] = self::relative($file).":{$tokens[$index][2]} {$description}";
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

            foreach (array_keys($tokens) as $index) {
                if (self::functionReference($tokens, $index, ['env']) === 'env()'
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
     * `->delay(...)` / `->release(...)` (also `?->` and `::`) whose argument is
     * above `$maxSeconds` or cannot be verified statically. Accepted
     * arguments: none, an integer literal, or a `self::`/`static::` integer
     * constant of the enclosing class. Anything else (`now()->addMinutes(5)`,
     * variables, expressions) is reported, because the queue cap cannot be
     * proven for it.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function queueDelayViolations(array $paths, int $maxSeconds): array
    {
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);
            $namespace = '';
            $class = null;

            foreach ($tokens as $index => [$id, $text, $line]) {
                if ($id === T_NAMESPACE && in_array($tokens[$index + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$index + 1][1];
                }

                if (in_array($id, [T_CLASS, T_TRAIT, T_ENUM], true)
                    && ! self::isMemberAccess($tokens, $index)
                    && ($tokens[$index + 1][0] ?? null) === T_STRING) {
                    $class = ltrim($namespace.'\\'.$tokens[$index + 1][1], '\\');
                }

                if ($id !== T_STRING
                    || ! in_array(strtolower($text), ['delay', 'release'], true)
                    || ! self::isMemberAccess($tokens, $index)
                    || ($tokens[$index + 1][1] ?? null) !== '(') {
                    continue;
                }

                $argument = self::callArgument($tokens, $index + 1);
                $seconds = self::resolveSeconds($argument, $class);
                $call = strtolower($text).'('.implode('', array_column($argument, 1)).')';

                if ($seconds === null) {
                    $violations[] = self::relative($file).":{$line} {$call}: not an integer literal or class constant";
                } elseif ($seconds > $maxSeconds) {
                    $violations[] = self::relative($file).":{$line} {$call}: {$seconds}s > {$maxSeconds}s";
                }
            }
        }

        return $violations;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Describes the token at `$index` if it references one of `$functions`
     * as a global function: a call (bare or fully qualified) or a
     * `use function` import (aliased or not). Null otherwise.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     * @param  list<string>  $functions  lowercase names
     */
    private static function functionReference(array $tokens, int $index, array $functions): ?string
    {
        [$id, $text] = $tokens[$index];

        if ($id === T_USE && ($tokens[$index + 1][0] ?? null) === T_FUNCTION) {
            $imported = strtolower($tokens[$index + 2][1] ?? '');
            $basename = substr($imported, (int) strrpos('\\'.$imported, '\\'));

            return in_array($basename, $functions, true) ? "use function {$basename}" : null;
        }

        if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
            return null;
        }

        $name = strtolower(ltrim($text, '\\'));

        if (! in_array($name, $functions, true)
            || ($tokens[$index + 1][1] ?? null) !== '('
            || self::isMemberAccess($tokens, $index)
            || in_array($tokens[$index - 1][0] ?? null, [T_FUNCTION, T_NEW, T_CONST], true)) {
            return null;
        }

        return "{$name}()";
    }

    /**
     * Tokens between the parenthesis at `$open` and its matching close.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private static function callArgument(array $tokens, int $open): array
    {
        $depth = 0;
        $argument = [];

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($text === '(') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $argument[] = $tokens[$i];
        }

        return $argument;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $argument
     */
    private static function resolveSeconds(array $argument, ?string $class): ?int
    {
        if ($argument === []) {
            return 0;
        }

        if (count($argument) === 1 && $argument[0][0] === T_LNUMBER) {
            return intval(str_replace('_', '', $argument[0][1]), 0);
        }

        if (count($argument) === 3
            && $class !== null
            && in_array(strtolower($argument[0][1]), ['self', 'static'], true)
            && $argument[1][0] === T_DOUBLE_COLON
            && $argument[2][0] === T_STRING
            && class_exists($class)) {
            $value = (new ReflectionClass($class))->getConstant($argument[2][1]);

            return is_int($value) ? $value : null;
        }

        return null;
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
    private static function isMemberAccess(array $tokens, int $index): bool
    {
        return in_array($tokens[$index - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    private static function relative(string $file): string
    {
        $root = self::root().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
