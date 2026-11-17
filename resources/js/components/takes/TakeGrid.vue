<script setup lang="ts">
import { ImagePlus } from '@lucide/vue';
import TakeCard from '@/components/takes/TakeCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useCopy } from '@/composables/useCopy';
import type { TakeCardData } from '@/types';

type Props = {
    takes: TakeCardData[];
    /** Deferred or first load: shows skeleton cards instead of the empty state. */
    loading?: boolean;
    /** A status filter is active, so an empty list means "no matches", not "no takes". */
    filtered?: boolean;
    skeletonCount?: number;
};

withDefaults(defineProps<Props>(), {
    loading: false,
    filtered: false,
    skeletonCount: 4,
});

const emit = defineEmits<{
    open: [id: number];
    cancel: [id: number];
}>();

const { t } = useCopy();

const gridClass =
    'grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-4';
</script>

<template>
    <div>
        <div v-if="loading" :class="gridClass" aria-busy="true">
            <div
                v-for="index in skeletonCount"
                :key="index"
                class="overflow-hidden rounded-xl border"
            >
                <Skeleton class="aspect-[4/3] rounded-none" />
                <div class="space-y-2 p-3">
                    <Skeleton class="h-4 w-20" />
                    <Skeleton class="h-4 w-full" />
                    <Skeleton class="h-4 w-24" />
                </div>
            </div>
        </div>

        <div
            v-else-if="takes.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center"
        >
            <ImagePlus
                class="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="max-w-sm text-sm text-muted-foreground">
                {{ filtered ? t('takes.empty_filter') : t('takes.empty_grid') }}
            </p>
            <slot name="empty-action" />
        </div>

        <!-- Creation order, newest first, never re-sorted live (2-02). -->
        <ul v-else role="list" :class="gridClass">
            <li v-for="take in takes" :key="take.id">
                <TakeCard
                    :take="take"
                    @open="emit('open', $event)"
                    @cancel="emit('cancel', $event)"
                />
            </li>
        </ul>
    </div>
</template>
