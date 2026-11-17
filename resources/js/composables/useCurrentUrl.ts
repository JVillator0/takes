import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import { toUrl } from '@/lib/utils';

type Href = NonNullable<InertiaLinkProps['href']>;

export type UseCurrentUrlReturn = {
    currentUrl: DeepReadonly<ComputedRef<string>>;
    isCurrentUrl: typeof isCurrentUrl;
    isCurrentOrParentUrl: typeof isCurrentOrParentUrl;
    whenCurrentUrl: typeof whenCurrentUrl;
};

const page = usePage();
const currentUrlReactive = computed(
    () =>
        new URL(
            page.url,
            typeof window !== 'undefined'
                ? window.location.origin
                : 'http://localhost',
        ).pathname,
);

function isCurrentUrl(
    urlToCheck: Href,
    currentUrl?: string,
    startsWith: boolean = false,
): boolean {
    const urlToCompare = currentUrl ?? currentUrlReactive.value;
    const urlString = toUrl(urlToCheck);

    const comparePath = (path: string): boolean =>
        startsWith ? urlToCompare.startsWith(path) : path === urlToCompare;

    if (!urlString.startsWith('http')) {
        return comparePath(urlString);
    }

    try {
        const absoluteUrl = new URL(urlString);

        return comparePath(absoluteUrl.pathname);
    } catch {
        return false;
    }
}

function isCurrentOrParentUrl(urlToCheck: Href, currentUrl?: string): boolean {
    return isCurrentUrl(urlToCheck, currentUrl, true);
}

/**
 * `ifTrue` when `urlToCheck` is the current URL, otherwise `ifFalse`, or
 * `null` when `ifFalse` is omitted. An explicit `undefined` is returned as is.
 */
function whenCurrentUrl<T>(urlToCheck: Href, ifTrue: T): T | null;
function whenCurrentUrl<T, F>(urlToCheck: Href, ifTrue: T, ifFalse: F): T | F;
function whenCurrentUrl<T, F>(
    urlToCheck: Href,
    ifTrue: T,
    ...ifFalse: [] | [F]
): T | F | null {
    if (isCurrentUrl(urlToCheck)) {
        return ifTrue;
    }

    return ifFalse.length === 1 ? ifFalse[0] : null;
}

export function useCurrentUrl(): UseCurrentUrlReturn {
    return {
        currentUrl: readonly(currentUrlReactive),
        isCurrentUrl,
        isCurrentOrParentUrl,
        whenCurrentUrl,
    };
}
