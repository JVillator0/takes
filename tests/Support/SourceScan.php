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
 * Known blind spots (by design, keep the scanner explainable):
 * - functions passed as string callables (`array_map('round', ...)`) and
 *   calls through variables (`$fn = 'round'; $fn()`);
 * - queue delays set through APIs not listed in QUEUE_DELAY_CALLS, through
 *   variables holding a queue/job (`$q = Queue::connection(); $q->{$m}()`),
 *   or computed at runtime in a way the token stream cannot see (a delay
 *   returned from a helper the caller does not wrap in `min(..., 900)`).
 *   Runtime limits for those belong in Queue::fake() tests (spec 4).
 */
final class SourceScan
{
    /** Float-producing helpers forbidden in money code (spec 1-01). */
    public const array FLOAT_FUNCTIONS = ['floatval', 'doubleval', 'round', 'number_format'];

    /**
     * Queue APIs that take a delay: method => [0-based argument index,
     * seconds per unit, static calls count]. Facade APIs (`Queue::later()`)
     * are matched on `->` and `::`; instance-only APIs on `->`/`?->` only, so
     * an app factory such as `SubmitGateDecision::releaseAfter()` is not
     * mistaken for the queue middleware (its value is checked where it
     * reaches `release()`).
     *
     * @var array<string, array{0: int, 1: int, 2: bool}>
     */
    public const array QUEUE_DELAY_CALLS = [
        'delay' => [0, 1, true],          // PendingDispatch, Queueable, Mailable, Notification
        'later' => [0, 1, true],          // Queue::later(), Mail::later(), Mailable::later()
        'lateron' => [1, 1, true],        // Queue::laterOn(), Mail::laterOn()
        'release' => [0, 1, false],       // InteractsWithQueue::release()
        'releaseafter' => [0, 1, false],  // RateLimited / WithoutOverlapping middleware
        'backoff' => [0, 60, false],      // ThrottlesExceptions::backoff() takes minutes
    ];

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
     * References to global (root-namespace) classes such as the facade
     * aliases Laravel registers (`\\DB::table()`, `use DB;`). Pest's arch
     * `toUse()` does not see root-namespace names, so these need a scan.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $classes  names without leading backslash
     * @return list<string>
     */
    public static function globalClassReferences(array $paths, array $classes): array
    {
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => [$id, $text, $line]) {
                $imported = $id === T_USE
                    && in_array($tokens[$index + 1][0] ?? null, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    && in_array(ltrim($tokens[$index + 1][1], '\\'), $classes, true);

                if ($imported) {
                    $violations[] = self::relative($file).":{$line} use ".ltrim($tokens[$index + 1][1], '\\');
                } elseif ($id === T_NAME_FULLY_QUALIFIED
                    && ($tokens[$index - 1][0] ?? null) !== T_USE
                    && in_array(ltrim($text, '\\'), $classes, true)) {
                    $violations[] = self::relative($file).":{$line} {$text}";
                }
            }
        }

        return $violations;
    }

    /**
     * Reads of the given superglobals, e.g. `$_ENV` or `$_SERVER`.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $variables  names including the `$`
     * @return list<string>
     */
    public static function variableReferences(array $paths, array $variables): array
    {
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            foreach (self::significantTokens($file) as [$id, $text, $line]) {
                if ($id === T_VARIABLE && in_array($text, $variables, true)) {
                    $violations[] = self::relative($file).":{$line} {$text}";
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
     * Writes to the given columns: `->col = …` / `->col += …`, an array key
     * `'col' =>` (update, forceFill, create), and `increment('col')` /
     * `decrement('col')`. Files under `$except` (relative prefixes) are skipped.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $columns
     * @param  list<string>  $except
     * @return list<string>
     */
    public static function columnWrites(array $paths, array $columns, array $except = []): array
    {
        $assignments = ['=', '+=', '-=', '*=', '/=', '??='];
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $relative = self::relative($file);

            foreach ($except as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => [$id, $text, $line]) {
                $next = $tokens[$index + 1][1] ?? '';

                if ($id === T_STRING && in_array($text, $columns, true) && self::isMemberAccess($tokens, $index) && in_array($next, $assignments, true)) {
                    $violations[] = "{$relative}:{$line} ->{$text} {$next}";

                    continue;
                }

                if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = trim($text, '\'"');
                $column = str_contains($literal, '.') ? substr($literal, (int) strrpos($literal, '.') + 1) : $literal;

                if (! in_array($column, $columns, true)) {
                    continue;
                }

                $callee = strtolower($tokens[$index - 2][1] ?? '');

                if ($next === '=>' || (($tokens[$index - 1][1] ?? '') === '(' && in_array($callee, ['increment', 'decrement', 'incrementquietly', 'decrementquietly'], true))) {
                    $violations[] = "{$relative}:{$line} writes {$literal}";
                }
            }
        }

        return $violations;
    }

    /**
     * Statements that both name the usage record (`UsageRecord`, or the
     * `usage_records` table) and take a row lock on it.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function usageRecordLocks(array $paths): array
    {
        $violations = [];

        foreach (self::phpFiles($paths) as $file) {
            $statement = [];

            foreach (self::significantTokens($file) as $token) {
                if ($token[1] !== ';') {
                    $statement[] = $token;

                    continue;
                }

                $namesUsage = false;
                $locks = false;

                foreach ($statement as [$id, $text]) {
                    $namesUsage = $namesUsage
                        || (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_ends_with($text, 'UsageRecord'))
                        || ($id === T_CONSTANT_ENCAPSED_STRING && trim($text, '\'"') === 'usage_records');
                    $locks = $locks || ($id === T_STRING && in_array($text, ['lockForUpdate', 'sharedLock'], true));
                }

                if ($namesUsage && $locks) {
                    $violations[] = self::relative($file).':'.$statement[0][2].' locks a usage record';
                }

                $statement = [];
            }
        }

        return $violations;
    }

    /**
     * Delay arguments of the QUEUE_DELAY_CALLS methods (called with `->`,
     * `?->` or `::`) and `->delay = ...` assignments that are above
     * `$maxSeconds` or cannot be verified statically.
     *
     * Accepted values: no argument, an integer literal, a `self::`/`static::`
     * integer constant of the enclosing class, or `min(...)` with at least one
     * accepted value among its arguments (the result can't exceed it). That
     * last form is the sanctioned way to cap a computed delay, e.g. spec 4's
     * `$this->release(min($retryAfter + $jitter, 900))`. Anything else
     * (`now()->addMinutes(5)`, variables, other expressions) is reported.
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

                if ($id !== T_STRING || ! self::isMemberAccess($tokens, $index)) {
                    continue;
                }

                $method = strtolower($text);
                $next = $tokens[$index + 1][1] ?? null;

                if ($method === 'delay' && $next === '=') {
                    $value = self::tokensUntil($tokens, $index + 2, ';');
                    $call = 'delay = '.self::source($value);
                    $unit = 1;
                } elseif (isset(self::QUEUE_DELAY_CALLS[$method])
                    && $next === '('
                    && (self::QUEUE_DELAY_CALLS[$method][2] || ($tokens[$index - 1][0] ?? null) !== T_DOUBLE_COLON)) {
                    [$position, $unit] = self::QUEUE_DELAY_CALLS[$method];
                    $arguments = self::callArguments($tokens, $index + 1);
                    $value = $arguments[$position] ?? [];
                    $call = $text.'('.implode(', ', array_map(self::source(...), $arguments)).')';
                } else {
                    continue;
                }

                $seconds = self::resolveSeconds($value, $class);

                if ($seconds === null) {
                    $violations[] = self::relative($file).":{$line} {$call}: not an integer literal, class constant or min(..., N)";
                } elseif ($seconds * $unit > $maxSeconds) {
                    $violations[] = self::relative($file).":{$line} {$call}: ".($seconds * $unit)."s > {$maxSeconds}s";
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
     * Top-level arguments of the call whose parenthesis is at `$open`.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     * @return list<list<array{0: int, 1: string, 2: int}>>
     */
    private static function callArguments(array $tokens, int $open): array
    {
        $depth = 0;
        $arguments = [];
        $current = [];

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i][1];

            if (in_array($text, ['(', '[', '{'], true) || $tokens[$i][0] === T_CURLY_OPEN || $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $tokens[$i];
        }

        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * Tokens from `$start` up to (not including) the first top-level `$end`.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private static function tokensUntil(array $tokens, int $start, string $end): array
    {
        $depth = 0;
        $result = [];

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($depth === 0 && $text === $end) {
                break;
            }

            $depth += match ($text) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };

            $result[] = $tokens[$i];
        }

        return $result;
    }

    /**
     * Index of the parenthesis that closes the one at `$open`, or -1.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function closingParenthesis(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $depth += match ($tokens[$i][1]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function source(array $tokens): string
    {
        return implode('', array_column($tokens, 1));
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $argument
     */
    private static function resolveSeconds(array $argument, ?string $class): ?int
    {
        if ($argument === []) {
            return 0;
        }

        if (in_array($argument[0][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($argument[0][1], '\\')) === 'min'
            && ($argument[1][1] ?? null) === '('
            && self::closingParenthesis($argument, 1) === count($argument) - 1) {
            $bounds = array_filter(array_map(
                fn (array $candidate): ?int => self::resolveSeconds($candidate, $class),
                self::callArguments($argument, 1),
            ), is_int(...));

            return $bounds === [] ? null : min($bounds);
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
