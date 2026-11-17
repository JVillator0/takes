<script setup lang="ts">
import { Clapperboard } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';

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
        <span class="text-base font-semibold tracking-tight">{{ name }}</span>
    </span>
</template>
