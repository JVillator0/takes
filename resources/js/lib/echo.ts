import { configureEcho } from '@laravel/echo-vue';

/**
 * Connects Echo to Reverb when the build carries a Reverb key (spec 5,
 * D-028). Without one (local `BROADCAST_CONNECTION=log`, tests) Echo stays
 * unconfigured and useTakeUpdates() falls back to polling on its own: there
 * is no switch to turn live updates on or off.
 */
export function initializeEcho(): void {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (typeof key !== 'string' || key === '') {
        return;
    }

    configureEcho({ broadcaster: 'reverb' });
}
