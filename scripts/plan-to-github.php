<?php

declare(strict_types=1);

/**
 * Parses .claude/specs/ into a GitHub plan (labels, milestones, issues) and prints it as JSON.
 * scripts/plan-to-github.sh is the only caller; it does every GitHub call (spec 1-05).
 *
 * Sources:
 *   - .claude/specs/README.md §3 table   -> milestone titles (M0 … M10) and fallback descriptions
 *   - each spec's "## Goal" paragraph     -> milestone description and issue context
 *   - each spec's "## Issues" numbered list -> one issue per item
 *   - each spec's "## Acceptance…" (else "## Tests", else "## Final checks") -> issue acceptance criteria
 *   - .claude/plan/items.json             -> short title, type, area, size (human judgment)
 *   - 1-04 phase table (D-070)            -> Worktree, encoded below
 *
 * Usage: php scripts/plan-to-github.php [--repo owner/name] [--branch main]
 */
$root = dirname(__DIR__);
$options = getopt('', ['repo:', 'branch:']);
$repo = is_string($options['repo'] ?? null) ? $options['repo'] : 'JVillator0/takes';
$branch = is_string($options['branch'] ?? null) ? $options['branch'] : 'main';
$specsDir = $root.'/.claude/specs';
$blobBase = "https://github.com/{$repo}/blob/{$branch}/.claude/specs";
$treeBase = "https://github.com/{$repo}/tree/{$branch}/.claude/specs";

// Spec 1-04 "Which work runs where" (D-070). M3 is split per file after the 3-02 schema PR;
// item-level overrides (3-02 issue 3 → d) live in items.json.
const WORKTREES = [
    '0' => 'main', '1' => 'main', '2' => 'main',
    '3-01' => 'a', '3-02' => 'main', '3-03' => 'b', '3-04' => 'c',
    '4' => 'a', '5' => 'b', '6' => 'd', '7' => 'c', '8' => 'e',
    '9' => 'main', '10' => 'main',
];
const STRETCH_SPECS = [6, 7, 8];
const DONE_SPECS = [2 => 'Done: the product principles, screen specs and wireframes are in `.claude/specs/2-product-design/`.'];
const TYPES = ['feature', 'chore', 'docs', 'test', 'fix'];
const AREAS = ['backend', 'frontend', 'filament', 'harness', 'infra'];
const SIZES = ['S', 'M', 'L'];

function fail(string $message): never
{
    fwrite(STDERR, "plan-to-github: {$message}\n");
    exit(1);
}

/** @return array<string, string> heading line => section body (without the heading) */
function sections(string $markdown): array
{
    $result = [];
    $current = null;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (str_starts_with($line, '## ')) {
            $current = substr($line, 3);
            $result[$current] = '';

            continue;
        }
        if ($current !== null) {
            $result[$current] .= $line."\n";
        }
    }

    return array_map('trim', $result);
}

/** @param array<string, string> $sections */
function findSection(array $sections, string $pattern): ?string
{
    foreach ($sections as $heading => $body) {
        if (preg_match($pattern, $heading) === 1) {
            return $heading;
        }
    }

    return null;
}

/** GitHub's heading anchor: lowercase, drop punctuation except - and _, spaces to hyphens. */
function anchor(string $heading): string
{
    $slug = mb_strtolower($heading);
    $slug = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug) ?? $slug;

    return str_replace(' ', '-', $slug);
}

function firstParagraph(string $body): string
{
    foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph !== '' && ! str_starts_with($paragraph, '>')) {
            return preg_replace('/\s*\n\s*/', ' ', $paragraph) ?? $paragraph;
        }
    }

    return '';
}

/** @return list<array{n: int, text: string}> */
function numberedItems(string $body): array
{
    $items = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (preg_match('/^(\d+)\.\s+(.*)$/', $line, $m) === 1) {
            $items[] = ['n' => (int) $m[1], 'text' => trim($m[2])];
        } elseif ($items !== [] && preg_match('/^\s{2,}\S/', $line) === 1) {
            $items[array_key_last($items)]['text'] .= ' '.trim($line);
        }
    }

    return $items;
}

// --- README table: | 3 | `3-domain-foundation/` | description | M3 Domain foundation |
$readme = file_get_contents($specsDir.'/README.md') ?: fail('cannot read .claude/specs/README.md');
$specs = [];
foreach (preg_split('/\R/', $readme) ?: [] as $line) {
    if (preg_match('/^\|\s*(\d+)\s*\|\s*`([^`]+)\/`\s*\|\s*(.+?)\s*\|\s*(M\d+ .+?)\s*\|$/', $line, $m) === 1) {
        $specs[(int) $m[1]] = ['folder' => $m[2], 'summary' => $m[3], 'milestone' => $m[4]];
    }
}
ksort($specs);
if (count($specs) !== 11) {
    fail('expected 11 rows (0-10) in the README §3 table, found '.count($specs));
}

$itemsFile = $root.'/.claude/plan/items.json';
/** @var array<string, array{title?: string, type?: string, area?: string, size?: string, worktree?: string}> $meta */
$meta = json_decode(file_get_contents($itemsFile) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
unset($meta['_comment']);
$unused = $meta;

$labels = [];
foreach ($specs as $n => $spec) {
    $labels[] = ['name' => "spec/{$n}", 'color' => '5319E7', 'description' => $spec['milestone']];
}
foreach (TYPES as $type) {
    $labels[] = ['name' => "type:{$type}", 'color' => $type === 'fix' ? 'D73A4A' : '1D76DB', 'description' => "Conventional Commit type {$type}"];
}
foreach (AREAS as $area) {
    $labels[] = ['name' => "area:{$area}", 'color' => '0E8A16', 'description' => "Area: {$area}"];
}
$labels[] = ['name' => 'required', 'color' => 'B60205', 'description' => 'Required scope: never blocked by stretch work'];
$labels[] = ['name' => 'stretch', 'color' => 'FBCA04', 'description' => 'Stretch scope: cut first if time runs out'];

$milestones = [];
$issues = [];
$warnings = [];

foreach ($specs as $n => $spec) {
    $folder = $specsDir.'/'.$spec['folder'];
    $files = glob($folder.'/*SPEC.md') ?: [];
    sort($files);
    if ($files === []) {
        fail("no SPEC.md in {$spec['folder']}");
    }
    $single = count($files) === 1 && basename($files[0]) === 'SPEC.md';
    $milestoneUrl = $single ? "{$blobBase}/{$spec['folder']}/SPEC.md" : "{$treeBase}/{$spec['folder']}";

    $goal = $spec['summary'];
    if ($single) {
        $sections = sections(file_get_contents($files[0]) ?: '');
        $goalHeading = findSection($sections, '/^Goal$/');
        if ($goalHeading !== null) {
            $goal = firstParagraph($sections[$goalHeading]);
        }
    }
    $scope = in_array($n, STRETCH_SPECS, true) ? 'stretch' : 'required';
    $done = DONE_SPECS[$n] ?? null;

    $milestones[] = [
        'spec' => $n,
        'title' => $spec['milestone'],
        'state' => $done === null ? 'open' : 'closed',
        'description' => trim(($done !== null ? $done.' ' : '')."{$goal}\n\nSpec: {$milestoneUrl}"),
    ];

    foreach ($files as $file) {
        $base = basename($file);
        $specKey = $base === 'SPEC.md' ? (string) $n : $n.'-'.substr($base, 0, 2);
        $relative = $spec['folder'].'/'.$base;
        $sections = sections(file_get_contents($file) ?: '');
        $title = trim((string) strtok((string) file_get_contents($file), "\n"), "# \t");

        $issuesHeading = findSection($sections, '/^Issues\b/');
        if ($issuesHeading === null) {
            continue;
        }
        $items = numberedItems($sections[$issuesHeading]);
        if ($items === []) {
            continue;
        }

        $goalHeading = findSection($sections, '/^Goal$/');
        $context = $goalHeading !== null ? firstParagraph($sections[$goalHeading]) : $spec['summary'];
        $acceptanceHeading = findSection($sections, '/^Acceptance/')
            ?? findSection($sections, '/^Tests/')
            ?? findSection($sections, '/^Final checks/');

        foreach ($items as $item) {
            $key = "{$specKey}.{$item['n']}";
            $m = $meta[$key] ?? [];
            unset($unused[$key]);
            if ($m === []) {
                $warnings[] = "{$key}: no entry in .claude/plan/items.json, using defaults";
            }
            $short = $m['title'] ?? mb_strimwidth(trim((string) preg_replace('/[`*]/', '', explode(' (', $item['text'])[0]), ' .'), 0, 60, '…');
            $type = $m['type'] ?? 'feature';
            $area = $m['area'] ?? ($n <= 2 ? 'harness' : 'backend');
            $size = $m['size'] ?? 'M';
            $worktree = $m['worktree'] ?? WORKTREES[$specKey] ?? WORKTREES[(string) $n];
            $issueTitle = "[{$key}] {$short}";
            if (! in_array($type, TYPES, true) || ! in_array($area, AREAS, true) || ! in_array($size, SIZES, true)) {
                fail("{$key}: invalid type/area/size in items.json");
            }
            if (mb_strlen($issueTitle) > 80) {
                fail("{$key}: title longer than 80 characters: {$issueTitle}");
            }

            $siblings = array_map(
                fn (array $other): string => "- [{$specKey}.{$other['n']}] ".($meta["{$specKey}.{$other['n']}"]['title'] ?? 'see the spec'),
                array_values(array_filter($items, fn (array $other): bool => $other['n'] !== $item['n'])),
            );
            $sectionUrl = "{$blobBase}/{$relative}#".anchor($issuesHeading);

            $body = "> Spec: [{$title} § {$issuesHeading}]({$sectionUrl}), item {$item['n']} · Milestone **{$spec['milestone']}** · Worktree **{$worktree}** · Size **{$size}** · ".ucfirst($scope)."\n\n";
            $body .= "## Context\n{$context}\n\n";
            $body .= "## Item (verbatim from the spec)\n{$item['n']}. {$item['text']}\n\n";
            $body .= "## Acceptance criteria\n- [ ] The item above is done exactly as the spec words it, with tests for any code it ships.\n";
            $body .= "- [ ] CI is green and the PR body says `Closes #<this issue>`.\n";
            if ($acceptanceHeading !== null) {
                $body .= "\nFrom the spec ([§ {$acceptanceHeading}]({$blobBase}/{$relative}#".anchor($acceptanceHeading).")), applicable where this item touches them:\n\n{$sections[$acceptanceHeading]}\n";
            }
            $body .= "\n## Out of scope\n- Work the item text assigns to another spec or issue.\n";
            if ($siblings !== []) {
                $body .= "- The other items of this spec, tracked in their own issues:\n".implode("\n", array_map(fn (string $s): string => '  '.$s, $siblings))."\n";
            }
            $body .= "\n---\n_Generated by `scripts/plan-to-github.sh` from `.claude/specs/`. Change the spec, not this issue._\n";

            $issues[] = [
                'key' => $key,
                'spec' => $n,
                'title' => $issueTitle,
                'milestone' => $spec['milestone'],
                'labels' => ["spec/{$n}", "type:{$type}", "area:{$area}", $scope],
                'worktree' => $worktree,
                'size' => $size,
                'scope' => ucfirst($scope),
                'body' => $body,
            ];
        }
    }
}

foreach (array_keys($unused) as $stale) {
    $warnings[] = "{$stale}: entry in items.json has no matching spec item";
}
$titles = array_column($issues, 'title');
if (count($titles) !== count(array_unique($titles))) {
    fail('duplicate issue titles');
}
foreach ($warnings as $warning) {
    fwrite(STDERR, "plan-to-github: warning: {$warning}\n");
}

echo json_encode(
    ['labels' => $labels, 'milestones' => $milestones, 'issues' => $issues],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
)."\n";
