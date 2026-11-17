import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { Ability } from '@/types/auth';
import type { FlashToast } from '@/types/ui';

type FlashDenial = Ability & { allowed: false };

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;
        const denial = flash?.denial as FlashDenial | undefined;

        if (data) {
            toast[data.type](data.message);
        }

        // A policy denial sent back by the server (spec 3-01), with 2-01 copy.
        if (denial?.message) {
            toast.error(denial.message);
        }
    });
}
