import { shallowRef } from 'vue';

/**
 * Focus restore for a dialog opened from code, with no DialogTrigger (F-20):
 * reka-ui then has no trigger to refocus on close and focus falls to the
 * body. Remember the opener when opening, and bind `onCloseAutoFocus` to
 * the DialogContent's `close-auto-focus`.
 */
export function useReturnFocus() {
    const opener = shallowRef<HTMLElement | null>(null);

    /** The given element, else whatever has focus now (the control that was used). */
    const remember = (element?: HTMLElement | null): void => {
        const active = document.activeElement;

        opener.value =
            element ??
            (active instanceof HTMLElement && active !== document.body
                ? active
                : null);
    };

    const onCloseAutoFocus = (event: Event): void => {
        if (opener.value?.isConnected) {
            event.preventDefault();
            opener.value.focus();
        }
    };

    return { opener, remember, onCloseAutoFocus };
}
