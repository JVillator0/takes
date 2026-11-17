import { usePage } from '@inertiajs/vue3';
import type { CopyKey, CopyReplacements, CopyTree } from '@/types';

/** The raw line at a dot-notation key, or undefined when the key is missing. */
function lookup(copy: CopyTree | undefined, key: string): string | undefined {
    let node: string | CopyTree | undefined = copy;

    for (const segment of key.split('.')) {
        node = typeof node === 'object' ? node[segment] : undefined;
    }

    return typeof node === 'string' ? node : undefined;
}

/** Fills Laravel-style `:placeholders`, longest names first, so `:amount_max` is not eaten by `:amount`. */
function fill(line: string, replacements: CopyReplacements): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (text, name) =>
                text.replaceAll(`:${name}`, String(replacements[name])),
            line,
        );
}

/**
 * Looks up a dot-notation key in the shared copy tree and fills Laravel-style
 * `:placeholders`. A missing key returns the key itself, so a gap is visible
 * on screen instead of rendering an empty string.
 */
export function translate(
    copy: CopyTree | undefined,
    key: string,
    replacements: CopyReplacements = {},
): string {
    const line = lookup(copy, key);

    return line === undefined ? key : fill(line, replacements);
}

/** A `{n}` or `[from,to]` condition (`*` is open-ended), as in Laravel's MessageSelector. */
const CONDITION = /^[[{]([^[\]{}]*)[\]}]([\s\S]*)$/;

function conditionMatches(condition: string, count: number): boolean {
    if (!condition.includes(',')) {
        return Number(condition.trim()) === count;
    }

    const [from = '*', to = '*'] = condition
        .split(',')
        .map((part) => part.trim());

    return (
        (from === '*' || count >= Number(from)) &&
        (to === '*' || count <= Number(to))
    );
}

/**
 * Picks the plural form of a `trans_choice` line for `count`, the same way
 * Laravel does: an explicit `{n}` / `[from,to]` segment that matches wins;
 * otherwise English rules (`one|other`). The lang files are English only.
 */
export function choose(line: string, count: number): string {
    const segments = line.split('|').map((segment) => segment.trim());

    for (const segment of segments) {
        const match = CONDITION.exec(segment);

        if (match && conditionMatches(match[1] ?? '', count)) {
            return (match[2] ?? '').trim();
        }
    }

    const forms = segments.map((segment) =>
        segment.replace(CONDITION, '$2').trim(),
    );

    return forms[count === 1 ? 0 : 1] ?? forms[0] ?? line;
}

/**
 * `t('takes.status.queued')` for UI copy (D-057). The strings live in
 * `lang/en/*.php` and reach the client once, as the `copy` shared prop.
 */
export function useCopy() {
    const page = usePage();

    const t = (key: CopyKey, replacements?: CopyReplacements): string =>
        translate(page.props.copy, key, replacements);

    /** `trans_choice` for a pluralised line; `:count` is filled with `count`. */
    const tc = (
        key: CopyKey,
        count: number,
        replacements: CopyReplacements = {},
    ): string => {
        const line = lookup(page.props.copy, key);

        return line === undefined
            ? key
            : fill(choose(line, count), { count, ...replacements });
    };

    return { t, tc };
}
