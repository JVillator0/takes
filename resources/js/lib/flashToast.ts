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
            const action = data.action;

            toast[data.type](
                data.message,
                action
                    ? {
                          action: {
                              label: action.label,
                              onClick: () => router.visit(action.href),
                          },
                      }
                    : undefined,
            );
        }

        // A policy denial sent back by the server (spec 3-01), with 2-01 copy.
        // Neutral, never an error: 2-01 says denials are not failures.
        if (denial?.message) {
            toast.info(denial.message);
        }
    });
}
