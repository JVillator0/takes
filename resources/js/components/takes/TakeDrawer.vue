<script setup lang="ts">
import {
    ArrowRight,
    Check,
    Copy,
    Download,
    Columns2,
    RotateCcw,
    Sparkles,
    Shuffle,
    X,
} from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import TakeImage from '@/components/takes/TakeImage.vue';
import TakeStatusBadge from '@/components/takes/TakeStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCopy } from '@/composables/useCopy';
import { cn } from '@/lib/utils';
import type { TakeDetailData, TakePriceTone } from '@/types';

type Props = {
    take: TakeDetailData | null;
};

const props = defineProps<Props>();

/** Drawer visibility; the page keeps it in the URL (`?take={id}`, 2-02). */
const open = defineModel<boolean>('open', { default: false });
/** Side-by-side with the parent take (frontend only, D-044). */
const comparing = defineModel<boolean>('comparing', { default: false });

const emit = defineEmits<{
    remix: [id: number];
    tryAgain: [id: number];
    describeChange: [id: number];
    cancel: [id: number];
    selectVersion: [id: number];
}>();

const { t } = useCopy();
const ids = { timeline: useId(), price: useId(), versions: useId() };
const copied = ref(false);

const canCompare = computed(
    () =>
        Boolean(props.take?.parent?.imageUrl) && Boolean(props.take?.imageUrl),
);

watch(
    () => props.take?.id,
    () => {
        copied.value = false;

        if (!canCompare.value) {
            comparing.value = false;
        }
    },
);

const priceTones: Record<TakePriceTone, string> = {
    charged:
        'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:border-emerald-400/40 dark:bg-emerald-950/60 dark:text-emerald-200',
    on_hold:
        'border-dashed border-amber-500 bg-amber-50 text-amber-900 dark:border-amber-400/70 dark:bg-amber-950/60 dark:text-amber-200',
    not_charged:
        'border-emerald-600/40 bg-background text-emerald-800 dark:border-emerald-400/40 dark:text-emerald-200',
};

const formatTime = (iso: string): string =>
    new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

const copyPrompt = async (): Promise<void> => {
    if (!props.take) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.take.prompt);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};

const sectionLabel =
    'text-xs font-semibold tracking-wide text-muted-foreground uppercase';
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto p-0 sm:max-w-3xl lg:max-w-5xl"
        >
            <template v-if="take">
                <SheetHeader class="border-b p-4 pr-12 sm:px-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <SheetTitle class="text-lg">
                            {{ t('takes.card.title', { id: take.id }) }}
                        </SheetTitle>
                        <TakeStatusBadge
                            :phase="take.phase"
                            :label="take.statusLabel"
                        />
                    </div>
                    <SheetDescription>{{ take.paramsLine }}</SheetDescription>
                </SheetHeader>

                <div
                    class="grid gap-6 p-4 sm:p-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]"
                >
                    <div class="flex min-w-0 flex-col gap-4">
                        <div
                            v-if="comparing && take.parent"
                            class="grid grid-cols-2 gap-2"
                        >
                            <figure class="flex flex-col gap-1.5">
                                <TakeImage
                                    :src="take.parent.imageUrl"
                                    :alt="take.parent.prompt"
                                    phase="done"
                                    fit="contain"
                                    class="aspect-square rounded-lg border"
                                />
                                <figcaption
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        t('takes.drawer.parent', {
                                            id: take.parent.id,
                                        })
                                    }}
                                </figcaption>
                            </figure>
                            <figure class="flex flex-col gap-1.5">
                                <TakeImage
                                    :src="take.imageUrl"
                                    :alt="take.prompt"
                                    :phase="take.phase"
                                    fit="contain"
                                    class="aspect-square rounded-lg border"
                                />
                                <figcaption
                                    class="text-xs font-medium text-foreground"
                                >
                                    {{ t('takes.drawer.this_take') }}
                                </figcaption>
                            </figure>
                        </div>
                        <TakeImage
                            v-else
                            :src="take.imageUrl"
                            :alt="take.prompt"
                            :phase="take.phase"
                            fit="contain"
                            class="aspect-square w-full rounded-lg border"
                        />

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="take.canCancel"
                                type="button"
                                variant="outline"
                                @click="emit('cancel', take.id)"
                            >
                                <X aria-hidden="true" />
                                {{ t('takes.actions.cancel') }}
                            </Button>
                            <Button
                                v-if="take.canRetry"
                                type="button"
                                @click="emit('tryAgain', take.id)"
                            >
                                <RotateCcw aria-hidden="true" />
                                {{ t('takes.actions.try_again') }}
                            </Button>
                            <Button
                                v-if="
                                    take.phase === 'done' ||
                                    take.phase === 'failed'
                                "
                                type="button"
                                :variant="
                                    take.phase === 'done'
                                        ? 'default'
                                        : 'outline'
                                "
                                @click="emit('remix', take.id)"
                            >
                                <Shuffle aria-hidden="true" />
                                {{ t('takes.actions.remix') }}
                            </Button>
                            <Button
                                v-if="take.phase === 'done'"
                                type="button"
                                variant="outline"
                                @click="emit('describeChange', take.id)"
                            >
                                <Sparkles aria-hidden="true" />
                                {{ t('takes.actions.describe_change') }}
                            </Button>
                            <Button
                                v-if="take.downloadUrl"
                                variant="outline"
                                as-child
                            >
                                <a
                                    :href="take.downloadUrl"
                                    :download="`take-${take.id}`"
                                >
                                    <Download aria-hidden="true" />
                                    {{ t('takes.actions.download') }}
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="copyPrompt"
                            >
                                <Check v-if="copied" aria-hidden="true" />
                                <Copy v-else aria-hidden="true" />
                                <span aria-live="polite">
                                    {{
                                        copied
                                            ? t('takes.actions.prompt_copied')
                                            : t('takes.actions.copy_prompt')
                                    }}
                                </span>
                            </Button>
                            <Button
                                v-if="canCompare"
                                type="button"
                                variant="ghost"
                                :aria-pressed="comparing"
                                @click="comparing = !comparing"
                            >
                                <Columns2 aria-hidden="true" />
                                {{
                                    comparing
                                        ? t('takes.actions.hide_comparison')
                                        : t('takes.actions.compare_with_parent')
                                }}
                            </Button>
                        </div>

                        <section
                            v-if="take.versions.length"
                            :aria-labelledby="ids.versions"
                            class="flex flex-col gap-2"
                        >
                            <h3 :id="ids.versions" :class="sectionLabel">
                                {{ t('takes.drawer.versions') }}
                            </h3>
                            <ol
                                class="-mx-1 flex items-center gap-1 overflow-x-auto px-1 pb-1"
                            >
                                <li
                                    v-for="(version, index) in take.versions"
                                    :key="version.id"
                                    class="flex shrink-0 items-center gap-1"
                                >
                                    <ArrowRight
                                        v-if="index > 0"
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <button
                                        type="button"
                                        :aria-current="
                                            version.current ? 'true' : undefined
                                        "
                                        :class="
                                            cn(
                                                'flex w-24 flex-col gap-1 rounded-lg border p-1 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                                version.current
                                                    ? 'border-foreground ring-1 ring-foreground'
                                                    : 'hover:bg-accent',
                                            )
                                        "
                                        @click="
                                            emit('selectVersion', version.id)
                                        "
                                    >
                                        <TakeImage
                                            :src="version.imageUrl"
                                            alt=""
                                            :phase="
                                                version.imageUrl
                                                    ? 'done'
                                                    : 'processing'
                                            "
                                            class="aspect-square rounded-md"
                                        />
                                        <span
                                            class="truncate px-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ version.label }}
                                        </span>
                                    </button>
                                </li>
                            </ol>
                        </section>
                    </div>

                    <div class="flex min-w-0 flex-col gap-5">
                        <div class="rounded-lg border p-3">
                            <p class="text-sm">“{{ take.prompt }}”</p>
                            <p
                                v-if="take.failureMessage || take.explanation"
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                {{ take.failureMessage ?? take.explanation }}
                            </p>
                        </div>

                        <section
                            :aria-labelledby="ids.price"
                            class="flex flex-col gap-2"
                        >
                            <h3 :id="ids.price" :class="sectionLabel">
                                {{ t('takes.drawer.price') }}
                            </h3>
                            <div class="rounded-lg border p-4">
                                <div
                                    class="flex flex-wrap items-center gap-x-3 gap-y-1"
                                >
                                    <span
                                        class="text-2xl font-semibold tracking-tight tabular-nums"
                                    >
                                        {{ take.price.amount }}
                                    </span>
                                    <span
                                        :class="
                                            cn(
                                                'rounded-full border px-2 py-0.5 text-xs font-medium',
                                                priceTones[take.price.tone],
                                            )
                                        "
                                    >
                                        {{ take.price.label }}
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums sm:ml-auto"
                                    >
                                        {{ take.price.estimateLine }}
                                    </span>
                                </div>
                                <p
                                    v-if="take.price.note"
                                    class="mt-2 text-sm text-muted-foreground"
                                >
                                    {{ take.price.note }}
                                </p>
                            </div>
                        </section>

                        <section
                            v-if="take.timeline.length"
                            :aria-labelledby="ids.timeline"
                            class="flex flex-col gap-2"
                        >
                            <h3 :id="ids.timeline" :class="sectionLabel">
                                {{ t('takes.drawer.timeline') }}
                            </h3>
                            <ol class="divide-y rounded-lg border text-sm">
                                <li
                                    v-for="entry in take.timeline"
                                    :key="entry.at + entry.label"
                                    class="flex gap-4 px-3 py-2"
                                >
                                    <time
                                        :datetime="entry.at"
                                        class="shrink-0 text-muted-foreground tabular-nums"
                                    >
                                        {{ formatTime(entry.at) }}
                                    </time>
                                    <span>{{ entry.label }}</span>
                                </li>
                            </ol>
                        </section>
                    </div>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
