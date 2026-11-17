<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Clapperboard } from '@lucide/vue';

type Props = {
    /** Hide the wordmark below sm (the app bar at 360 px); it stays the link's accessible name. */
    compact?: boolean;
};

withDefaults(defineProps<Props>(), {
    compact: false,
});

const name = usePage().props.name;
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <span
            class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground"
            aria-hidden="true"
        >
            <Clapperboard class="size-4" />
        </span>
        <span
            :class="[
                'text-base font-semibold tracking-tight',
                compact && 'sr-only sm:not-sr-only',
            ]"
        >
            {{ name }}
        </span>
    </span>
</template>
