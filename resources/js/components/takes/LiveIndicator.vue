<script setup lang="ts">
import { useCopy } from '@/composables/useCopy';
import type { LiveConnectionState } from '@/types/live';

/**
 * P04 header: "Live" while the socket is connected; the 2-01 Reconnecting
 * line while the page polls instead (D-028, W-20). Nothing while idle or
 * still connecting.
 * Colour is never the only signal: the state is always in words.
 */
defineProps<{ state: LiveConnectionState }>();

const { t } = useCopy();
</script>

<template>
    <span
        v-if="state === 'live' || state === 'polling'"
        role="status"
        :data-state="state"
        class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium"
    >
        <span
            aria-hidden="true"
            :class="[
                'size-2 shrink-0 rounded-full',
                state === 'live'
                    ? 'bg-emerald-600 dark:bg-emerald-400'
                    : 'bg-amber-500 motion-safe:animate-pulse',
            ]"
        />
        <span
            :class="
                state === 'live'
                    ? 'text-emerald-700 dark:text-emerald-300'
                    : 'hidden truncate text-amber-800 sm:inline dark:text-amber-200'
            "
        >
            {{
                state === 'live'
                    ? t('takes.connection.live')
                    : t('takes.connection.reconnecting')
            }}
        </span>
        <span v-if="state !== 'live'" class="sr-only sm:hidden">
            {{ t('takes.connection.reconnecting') }}
        </span>
    </span>
</template>
