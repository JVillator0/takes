<?php

declare(strict_types=1);

use Tests\Support\SourceScan;

/*
| Fixture tests for the tokenizer behind the money float scan, the env()
| rule, the .env.example coverage test and the queue delay cap. Each fixture
| is written to a temp file so the real scans never see it.
*/

beforeEach(function (): void {
    $this->fixtureDir = sys_get_temp_dir().'/source-scan-'.bin2hex(random_bytes(6));
    mkdir($this->fixtureDir);

    $this->fixture = function (string $code): string {
        $path = $this->fixtureDir.'/Fixture'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $code);

        return $path;
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->fixtureDir.'/*.php') ?: []);
    rmdir($this->fixtureDir);
});

describe('floatViolations', function (): void {
    it('flags', function (string $code, string $expected): void {
        $violations = SourceScan::floatViolations([($this->fixture)($code)]);

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toEndWith($expected);
    })->with([
        'bare round()' => ['<?php round(1, 2);', ':1 round()'],
        'fully qualified \round()' => ['<?php \round(1);', ':1 round()'],
        'fully qualified \number_format()' => ['<?php \number_format(1);', ':1 number_format()'],
        'namespaced file, bare floatval()' => ["<?php\nnamespace App\\Support\\Money;\nfloatval('1');", ':3 floatval()'],
        'doubleval()' => ['<?php doubleval(1);', ':1 doubleval()'],
        'round() as first-class callable' => ['<?php $f = round(...);', ':1 round()'],
        'aliased import' => ['<?php use function round as r;', ':1 use function round'],
        'qualified import' => ['<?php use function \number_format;', ':1 use function number_format'],
        'float literal' => ['<?php $x = 1.5;', ':1 float literal 1.5'],
        'exponent literal' => ['<?php $x = 1e9;', ':1 float literal 1e9'],
        '(float) cast' => ['<?php $x = (float) $y;', ':1 float cast (float)'],
        '(double) cast' => ['<?php $x = (double) $y;', ':1 float cast (double)'],
        'return type' => ['<?php function f(): int|float { return 1; }', ':1 float type'],
        'nullable parameter type' => ['<?php function f(?float $x): void {}', ':1 float type'],
        'property type' => ['<?php class A { public float $x = 0; }', ':1 float type'],
    ]);

    it('reports one violation per occurrence', function (): void {
        $file = ($this->fixture)("<?php\nfunction f(float \$x): float\n{\n    return round(\$x * 1.5);\n}");

        expect(SourceScan::floatViolations([$file]))->toHaveCount(4);
    });

    it('ignores', function (string $code): void {
        expect(SourceScan::floatViolations([($this->fixture)($code)]))->toBe([]);
    })->with([
        'identifiers containing round' => ['<?php $roundTrip = 1; $rounding = 2;'],
        'method call ->round()' => ['<?php $money->round();'],
        'nullsafe method call ?->round()' => ['<?php $money?->round();'],
        'static call ::round()' => ['<?php Money::round(1);'],
        'method declaration round()' => ['<?php class A { public function round(): int { return 1; } }'],
        'namespaced function Foo\round()' => ['<?php Foo\round(1);'],
        'line comment' => ['<?php // round(1.5) returns a float'],
        'doc comment' => ['<?php /** @return float round(1.5) */ function f(): int { return 1; }'],
        'string' => ["<?php \$s = 'round(1.5) float (float) number_format(1)';"],
        'integer arithmetic' => ['<?php $x = intdiv(10, 3) + 10 % 3 + 1_000_000_000;'],
        'property named float' => ['<?php $x->float;'],
        'import of another function' => ['<?php use function intdiv as d;'],
    ]);

    it('skips paths that do not exist', function (): void {
        expect(SourceScan::floatViolations(['app/Does/Not/Exist']))->toBe([]);
    });
});

describe('functionCalls', function (): void {
    it('finds bare, fully qualified and imported calls', function (): void {
        $file = ($this->fixture)("<?php\nenv('A');\n\\env('B');\nuse function env as e;\nENV('C');");

        expect(SourceScan::functionCalls([$file], ['env']))->toHaveCount(4)
            ->each->toMatch('/:\d (env\(\)|use function env)$/');
    });

    it('finds getenv() and putenv() like env()', function (): void {
        $file = ($this->fixture)("<?php\ngetenv('A');\n\\putenv('B=1');");

        expect(SourceScan::functionCalls([$file], ['env', 'getenv', 'putenv']))->toHaveCount(2);
    });

    it('ignores methods, statics, declarations and strings', function (): void {
        $file = ($this->fixture)("<?php\n\$app->env('A');\nApp::env('B');\nclass A { public function env(): void {} }\n\$s = 'env(\"C\")';");

        expect(SourceScan::functionCalls([$file], ['env']))->toBe([]);
    });
});

describe('globalClassReferences', function (): void {
    it('finds fully qualified references and global imports', function (): void {
        $file = ($this->fixture)("<?php\nnamespace App\\Http\\Controllers;\nuse DB;\n\\DB::table('users');\nuse \\DB as Database;");

        expect(SourceScan::globalClassReferences([$file], ['DB']))->toHaveCount(3)
            ->sequence(
                fn ($violation) => $violation->toEndWith(':3 use DB'),
                fn ($violation) => $violation->toEndWith(':4 \\DB'),
                fn ($violation) => $violation->toEndWith(':5 use DB'),
            );
    });

    it('ignores namespaced classes and strings', function (): void {
        $file = ($this->fixture)("<?php\nuse Illuminate\\Support\\Facades\\DB;\nDB::table('x');\n\\App\\DB::run();\n\$s = '\\DB';\n\\DBAL::x();");

        expect(SourceScan::globalClassReferences([$file], ['DB']))->toBe([]);
    });
});

describe('variableReferences', function (): void {
    it('finds superglobal reads and ignores look-alikes', function (): void {
        $file = ($this->fixture)("<?php\n\$a = \$_ENV['A'];\n\$b = \$_SERVER['B'];\n\$_ENVIRONMENT = 1;\n\$s = '\$_ENV';");

        expect(SourceScan::variableReferences([$file], ['$_ENV', '$_SERVER']))->toHaveCount(2)
            ->sequence(
                fn ($violation) => $violation->toEndWith(':2 $_ENV'),
                fn ($violation) => $violation->toEndWith(':3 $_SERVER'),
            );
    });
});

describe('envKeys', function (): void {
    it('extracts literal keys from env() calls, unique and sorted', function (): void {
        $file = ($this->fixture)(<<<'PHP'
            <?php
            return [
                'b' => env('B_KEY', 1),
                'a' => \env("A_KEY"),
                'again' => env('B_KEY'),
                'multi' => env(
                    'C_KEY',
                    'default',
                ),
                'dynamic' => env($name),
                'method' => $app->env('NOT_A_KEY'),
                // 'commented' => env('COMMENTED_KEY'),
            ];
            PHP);

        expect(SourceScan::envKeys([$file]))->toBe(['A_KEY', 'B_KEY', 'C_KEY']);
    });
});

describe('queueDelayViolations', function (): void {
    it('flags delays over the cap and unverifiable arguments', function (string $call, string $expected): void {
        $file = ($this->fixture)("<?php\n{$call};");

        $violations = SourceScan::queueDelayViolations([$file], 900);

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toEndWith($expected);
    })->with([
        'release over the cap' => ['$this->release(901)', 'release(901): 901s > 900s'],
        'delay with separators' => ['dispatch($job)->delay(1_800)', 'delay(1_800): 1800s > 900s'],
        'static delay' => ['Job::delay(3600)', 'delay(3600): 3600s > 900s'],
        'carbon delay' => ['$job->delay(now()->addMinutes(30))', 'delay(now()->addMinutes(30)): not an integer literal, class constant or min(..., N)'],
        'variable' => ['$this->release($seconds)', 'release($seconds): not an integer literal, class constant or min(..., N)'],
        'expression' => ['$this->release(60 * 20)', 'release(60*20): not an integer literal, class constant or min(..., N)'],
        'releaseAfter middleware' => ['(new RateLimited(\'x\'))->releaseAfter(1000)', 'releaseAfter(1000): 1000s > 900s'],
        'Queue::later first argument' => ['Queue::later(901, $job)', 'later(901, $job): 901s > 900s'],
        'Mail::later computed' => ['Mail::to($u)->later(now()->addHour(), $mail)', 'later(now()->addHour(), $mail): not an integer literal, class constant or min(..., N)'],
        'laterOn second argument' => ['Queue::laterOn(\'default\', 1200, $job)', "laterOn('default', 1200, \$job): 1200s > 900s"],
        'ThrottlesExceptions backoff in minutes' => ['(new ThrottlesExceptions(3))->backoff(20)', 'backoff(20): 1200s > 900s'],
        'delay property assignment' => ['$this->delay = now()->addHour()', 'delay = now()->addHour(): not an integer literal, class constant or min(..., N)'],
        'delay property assignment over the cap' => ['$this->delay = 3600', 'delay = 3600: 3600s > 900s'],
        'min() without a verifiable bound' => ['$this->release(min($a, $b))', 'release(min($a,$b)): not an integer literal, class constant or min(..., N)'],
        'min() with a bound over the cap' => ['$this->release(min($retryAfter, 3600))', 'release(min($retryAfter,3600)): 3600s > 900s'],
        'min() inside a larger expression' => ['$this->release(min($retryAfter, 900) + $jitter)', 'release(min($retryAfter,900)+$jitter): not an integer literal, class constant or min(..., N)'],
    ]);

    it('accepts min() capped delays, the sanctioned form for computed delays (spec 4)', function (string $call): void {
        expect(SourceScan::queueDelayViolations([($this->fixture)("<?php\n{$call};")], 900))->toBe([]);
    })->with([
        'release(min(expr, 900))' => ['$this->release(min($retryAfter + random_int(0, 5), 900))'],
        'release(\min(900, expr))' => ['$this->release(\min(900, $seconds))'],
        'delay(min(expr, literal))' => ['dispatch($job)->delay(min($wait, 60))'],
        'later within the cap' => ['Queue::later(600, $job)'],
        'laterOn within the cap' => ['Queue::laterOn(\'q\', 900, $job)'],
        'backoff of 15 minutes' => ['(new ThrottlesExceptions(3))->backoff(15)'],
        'delay assignment within the cap' => ['$this->delay = 300'],
    ]);

    it('accepts literals within the cap, no argument and capped class constants', function (): void {
        $class = 'SourceScanFixture'.bin2hex(random_bytes(4));
        $file = ($this->fixture)(<<<PHP
            <?php
            final class {$class}
            {
                private const RETRY_IN = 900;

                public function handle(\$lock): void
                {
                    \$this->release(900);
                    \$this->release(self::RETRY_IN);
                    \$this->release(static::RETRY_IN);
                    \$lock->release();
                    \$this->delay(0);
                }
            }
            PHP);
        require $file;

        expect(SourceScan::queueDelayViolations([$file], 900))->toBe([]);
    });

    it('checks the value of class constants', function (): void {
        $class = 'SourceScanFixture'.bin2hex(random_bytes(4));
        $file = ($this->fixture)(<<<PHP
            <?php
            final class {$class}
            {
                private const RETRY_IN = 3600;

                public function handle(): void
                {
                    \$this->release(self::RETRY_IN);
                }
            }
            PHP);
        require $file;

        $violations = SourceScan::queueDelayViolations([$file], 900);

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toEndWith('3600s > 900s');
    });

    it('ignores unrelated methods and plain function calls', function (): void {
        $file = ($this->fixture)("<?php\n\$x->delayed(5000);\nrelease(5000);\n\$s = '->delay(5000)';\n\$delay = 5000;\nclass J { public function backoff(): array { return [1]; } }");

        expect(SourceScan::queueDelayViolations([$file], 900))->toBe([]);
    });
});
