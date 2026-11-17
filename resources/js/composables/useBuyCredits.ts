import { ref } from 'vue';

/** One Buy credits modal per page (D-026), opened from the bar, the composer or a banner. */
const isOpen = ref(false);

export function useBuyCredits() {
    return {
        isOpen,
        open: (): void => {
            isOpen.value = true;
        },
        close: (): void => {
            isOpen.value = false;
        },
    };
}
