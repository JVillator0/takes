import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * Shared demo logins cannot change their name, email or password (D-072):
 * `auth.can.updateAccount` is denied with `demo_account_locked` and its 2-01
 * copy, so the settings forms render disabled with that notice.
 */
export function useAccountLock(): {
    locked: ComputedRef<boolean>;
    message: ComputedRef<string | null>;
} {
    const page = usePage();
    const ability = computed(() => page.props.auth.can?.updateAccount ?? null);

    return {
        locked: computed(() => ability.value?.allowed === false),
        message: computed(() => ability.value?.message ?? null),
    };
}
