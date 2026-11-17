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

    it('ignores methods, statics, declarations and strings', function (): void {
        $file = ($this->fixture)("<?php\n\$app->env('A');\nApp::env('B');\nclass A { public function env(): void {} }\n\$s = 'env(\"C\")';");

        expect(SourceScan::functionCalls([$file], ['env']))->toBe([]);
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
        'carbon delay' => ['$job->delay(now()->addMinutes(30))', 'delay(now()->addMinutes(30)): not an integer literal or class constant'],
        'variable' => ['$this->release($seconds)', 'release($seconds): not an integer literal or class constant'],
        'expression' => ['$this->release(60 * 20)', 'release(60*20): not an integer literal or class constant'],
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
        $file = ($this->fixture)("<?php\n\$x->delayed(5000);\nrelease(5000);\n\$s = '->delay(5000)';");

        expect(SourceScan::queueDelayViolations([$file], 900))->toBe([]);
    });
});
