<script setup lang="ts">
import { PlugZap } from '@lucide/vue';
import { useCopy } from '@/composables/useCopy';

/** Driven by the shared `imageService.configured` prop (spec 3-04); names no provider (D-023). */
const { t } = useCopy();
</script>

<template>
    <div
        role="status"
        class="flex items-start gap-2.5 rounded-lg border border-amber-500/50 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-400/40 dark:bg-amber-950/50 dark:text-amber-100"
    >
        <PlugZap class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p>{{ t('takes.service.not_configured') }}</p>
    </div>
</template>
