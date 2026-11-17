import { usePage } from '@inertiajs/vue3';
import type { CopyKey, CopyReplacements, CopyTree } from '@/types';

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
    let node: string | CopyTree | undefined = copy;

    for (const segment of key.split('.')) {
        node = typeof node === 'object' ? node[segment] : undefined;
    }

    if (typeof node !== 'string') {
        return key;
    }

    // Longest names first, so `:amount_max` is not eaten by `:amount`.
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (text, name) =>
                text.replaceAll(`:${name}`, String(replacements[name])),
            node,
        );
}

/**
 * `t('takes.status.queued')` for UI copy (D-057). The strings live in
 * `lang/en/*.php` and reach the client once, as the `copy` shared prop.
 */
export function useCopy() {
    const page = usePage();

    const t = (key: CopyKey, replacements?: CopyReplacements): string =>
        translate(page.props.copy, key, replacements);

    return { t };
}
