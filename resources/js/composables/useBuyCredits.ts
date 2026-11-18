import { ref } from 'vue';
import { useReturnFocus } from '@/composables/useReturnFocus';

/** One Buy credits modal per page (D-026), opened from the bar, the composer or a banner. */
const isOpen = ref(false);

/**
 * The control that opened the modal (useReturnFocus, shared by the page).
 * The pack dialog hands over to the purchase dialog, which has no trigger of
 * its own, so focus goes back here when either closes (F-20).
 */
const returnFocus = useReturnFocus();

export function useBuyCredits() {
    return {
        isOpen,
        opener: returnFocus.opener,
        /** close-auto-focus handler: refocuses the opener when it is still on the page. */
        onCloseAutoFocus: returnFocus.onCloseAutoFocus,
        open: (): void => {
            returnFocus.remember();
            isOpen.value = true;
        },
        close: (): void => {
            isOpen.value = false;
        },
    };
}
