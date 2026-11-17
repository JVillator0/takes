import { ref, shallowRef } from 'vue';

/** One Buy credits modal per page (D-026), opened from the bar, the composer or a banner. */
const isOpen = ref(false);

/**
 * The control that opened the modal. The pack dialog hands over to the
 * purchase dialog, which has no trigger of its own, so focus goes back
 * here when the purchase dialog closes (F-20).
 */
const opener = shallowRef<HTMLElement | null>(null);

export function useBuyCredits() {
    return {
        isOpen,
        opener,
        open: (): void => {
            opener.value =
                document.activeElement instanceof HTMLElement &&
                document.activeElement !== document.body
                    ? document.activeElement
                    : null;
            isOpen.value = true;
        },
        close: (): void => {
            isOpen.value = false;
        },
    };
}
