<script setup lang="ts">
import { ImageOff } from '@lucide/vue';
import { computed } from 'vue';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { TakePhase } from '@/types';

type Props = {
    src: string | null;
    /** Alt text: the prompt, or "" when a named control beside it already reads the prompt (cards). */
    alt: string;
    phase: TakePhase;
    /** Short state word for running takes, e.g. the explanation. */
    caption?: string | null;
    fit?: 'cover' | 'contain';
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    caption: null,
    fit: 'cover',
    class: '',
});

const { t } = useCopy();

const running = computed(() =>
    ['submitting', 'waiting_for_capacity', 'queued', 'processing'].includes(
        props.phase,
    ),
);

const emptyLabel = computed(() => {
    if (props.phase === 'failed') {
        return t('takes.card.no_image');
    }

    if (props.phase === 'done') {
        return t('takes.image_unavailable');
    }

    return props.caption ?? '';
});
</script>

<template>
    <div
        :class="
            cn(
                'relative overflow-hidden bg-muted',
                running &&
                    'bg-linear-to-br from-muted via-muted-foreground/10 to-muted motion-safe:animate-pulse',
                props.class,
            )
        "
    >
        <img
            v-if="src"
            :src="src"
            :alt="alt"
            loading="lazy"
            decoding="async"
            :class="[
                'size-full motion-safe:animate-in motion-safe:fade-in',
                fit === 'cover' ? 'object-cover' : 'object-contain',
            ]"
        />
        <div
            v-else
            class="flex size-full flex-col items-center justify-center gap-1.5 p-3 text-center text-xs text-muted-foreground"
        >
            <ImageOff v-if="!running" class="size-5" aria-hidden="true" />
            <span v-if="emptyLabel" class="line-clamp-2">{{ emptyLabel }}</span>
        </div>
    </div>
</template>
