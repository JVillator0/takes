<script setup lang="ts">
import { useCopy } from '@/composables/useCopy';
import type { LiveConnectionState } from '@/types/live';

/**
 * P04 header: "Live" while the socket is connected; the 2-01 Reconnecting
 * line while the page polls instead (D-028, W-20). Nothing while idle or
 * still connecting.
 * Colour is never the only signal: the state is always in words, on
 * phones the short "Reconnecting…" (the full line stays for screen
 * readers). The role=status container stays mounted, so screen readers
 * announce the words when they change (F-19).
 */
defineProps<{ state: LiveConnectionState }>();

const { t } = useCopy();
</script>

<template>
    <span
        role="status"
        :data-state="state"
        class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium"
    >
        <template v-if="state === 'live'">
            <span
                aria-hidden="true"
                class="size-2 shrink-0 rounded-full bg-emerald-600 dark:bg-emerald-400"
            />
            <span class="text-emerald-700 dark:text-emerald-300">
                {{ t('takes.connection.live') }}
            </span>
        </template>
        <template v-else-if="state === 'polling'">
            <span
                aria-hidden="true"
                class="size-2 shrink-0 rounded-full bg-amber-500 motion-safe:animate-pulse"
            />
            <span
                aria-hidden="true"
                class="whitespace-nowrap text-amber-800 sm:hidden dark:text-amber-200"
            >
                {{ t('takes.connection.reconnecting_short') }}
            </span>
            <span
                class="hidden truncate text-amber-800 sm:inline dark:text-amber-200"
            >
                {{ t('takes.connection.reconnecting') }}
            </span>
            <span class="sr-only sm:hidden">
                {{ t('takes.connection.reconnecting') }}
            </span>
        </template>
    </span>
</template>
