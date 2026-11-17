<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronUp, EllipsisVertical } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import LiveIndicator from '@/components/takes/LiveIndicator.vue';
import DescribeChangePanel from '@/components/takes/DescribeChangePanel.vue';
import TakeComposer from '@/components/takes/TakeComposer.vue';
import TakeDrawer from '@/components/takes/TakeDrawer.vue';
import TakeFilterTabs from '@/components/takes/TakeFilterTabs.vue';
import TakeGrid from '@/components/takes/TakeGrid.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBuyCredits } from '@/composables/useBuyCredits';
import { useCopy } from '@/composables/useCopy';
import { useTakeUpdates } from '@/composables/useTakeUpdates';
import StudioLayout from '@/layouts/StudioLayout.vue';
import {
    destroy as archiveProject,
    index as projectsIndex,
    show as showProject,
    update as updateProject,
} from '@/routes/projects';
import { cancel as cancelTake } from '@/routes/takes';
import type {
    BalanceSummary,
    ComposerConfig,
    SmallestTake,
    TakeCardData,
    TakeDetailData,
    TakeFilter,
    TakeFilterCounts,
    WorkspaceProject,
} from '@/types';

/**
 * The project workspace (P04, P05, P06, P10; spec 4 issue 6): composer on
 * the left (a bottom sheet on phones), the takes grid on the right, newest
 * first and never re-sorted live (2-02), the drawer at `?take={id}` (P06,
 * partial reload `only: ['take']`). Live status comes from spec 5
 * (useTakeUpdates: socket, else polling every 4 s), which reloads only the
 * props named below.
 */
type Props = {
    project: WorkspaceProject;
    takes: TakeCardData[];
    hasMoreTakes: boolean;
    pages: number;
    filter: TakeFilter;
    counts: TakeFilterCounts;
    balance: BalanceSummary;
    negativeBalance: string | null;
    smallestTake: SmallestTake;
    take: TakeDetailData | null;
    composer: ComposerConfig;
};

const props = defineProps<Props>();

const page = usePage();
const { t } = useCopy();
const buyCredits = useBuyCredits();

const LIVE_PROPS = ['takes', 'counts', 'balance', 'project', 'take'];

const updates = useTakeUpdates(() => props.project.id, {
    customerId: page.props.auth.customer?.id ?? null,
    hasActiveTakes: () => props.counts.in_progress > 0,
    only: LIVE_PROPS,
});

/** The socket's balance between a broadcast and the reload that follows it. */
const balance = computed(() => updates.balance.value ?? props.balance);
watch(
    () => props.balance,
    () => {
        updates.balance.value = null;
    },
);

/** The URL query the workspace keeps: filter and loaded pages (the drawer adds `take`). */
const query = (extra: Record<string, string | number | undefined> = {}) => ({
    status: props.filter === 'all' ? undefined : props.filter,
    pages: props.pages > 1 ? props.pages : undefined,
    ...extra,
});

/* Filter (2-02): In progress groups the four running states. */
const filter = computed({
    get: () => props.filter,
    set: (value: TakeFilter) => {
        router.get(
            showProject(props.project.id, {
                query: {
                    status: value === 'all' ? undefined : value,
                },
            }).url,
            {},
            {
                only: ['takes', 'counts', 'filter', 'hasMoreTakes', 'pages'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    },
});

const loadMore = (): void => {
    router.reload({
        data: { pages: props.pages + 1 },
        only: ['takes', 'hasMoreTakes', 'pages'],
    });
};

/* Drawer (P06): URL-addressable, so a reload or a shared link keeps it open. */
const pendingTakeId = ref<string | null>(null);
const comparing = ref(false);
const drawerOpen = computed({
    get: () => props.take !== null || pendingTakeId.value !== null,
    set: (open: boolean) => {
        if (!open) {
            closeTake();
        }
    },
});

const openTake = (id: string): void => {
    pendingTakeId.value = id;
    router.get(
        showProject(props.project.id, { query: query({ take: id }) }).url,
        {},
        {
            only: ['take'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                pendingTakeId.value = null;
            },
        },
    );
};

const closeTake = (): void => {
    pendingTakeId.value = null;
    router.get(
        showProject(props.project.id, { query: query() }).url,
        {},
        {
            only: ['take'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

/*
 * Describe a change (P07, spec 8): the panel opens in the drawer of a done
 * take; applying creates the new take through the same pipeline as the
 * composer, which then opens in the drawer.
 */
const describing = ref(false);

watch(
    () => props.take?.id,
    () => {
        describing.value = false;
    },
);

const onSuggestionApplied = (takeId: string): void => {
    describing.value = false;
    router.reload({ only: ['takes', 'counts', 'balance', 'project'] });
    openTake(takeId);
};

/* Cancel a take waiting for capacity (D-044): voids the hold. */
const cancel = (id: string): void => {
    router.post(
        cancelTake(id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.cancel) {
                    toast.info(errors.cancel);
                }
            },
        },
    );
};

/* Composer: remix and try again fill it; on phones it is a bottom sheet (P10). */
const composerRef = ref<InstanceType<typeof TakeComposer> | null>(null);
const composerPanel = ref<HTMLElement | null>(null);
const sheetExpanded = ref(false);

const focusComposer = async (): Promise<void> => {
    sheetExpanded.value = true;
    await nextTick();
    composerPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    composerPanel.value
        ?.querySelector<HTMLTextAreaElement>('textarea')
        ?.focus();
};

const remix = (): void => {
    if (props.take) {
        composerRef.value?.remix(props.take);
        closeTake();
        void focusComposer();
    }
};

const tryAgain = (): void => {
    if (props.take) {
        composerRef.value?.tryAgain(props.take);
        closeTake();
        void focusComposer();
    }
};

/*
 * Toasts only for transitions to a terminal state of the customer's own
 * takes (2-02); hold release is not announced as a balance event.
 */
const RUNNING = ['submitting', 'waiting_for_capacity', 'queued', 'processing'];
let phases = new Map(props.takes.map((take) => [take.id, take.phase]));

watch(
    () => props.takes,
    (takes) => {
        for (const take of takes) {
            const before = phases.get(take.id);

            if (before === undefined || !RUNNING.includes(before)) {
                continue;
            }

            if (take.phase === 'done') {
                toast.success(
                    t('takes.toast.done', { id: take.number ?? '' }),
                    {
                        description: t('takes.toast.done_detail', {
                            charged: take.moneyLine,
                        }),
                    },
                );
            } else if (take.phase === 'failed') {
                toast.info(t('takes.toast.failed', { id: take.number ?? '' }), {
                    description: take.moneyLine,
                });
            }
        }

        phases = new Map(takes.map((take) => [take.id, take.phase]));
    },
);

/* Project: rename and archive (spec 4 issue 2). */
const renaming = ref(false);
const renameForm = useForm({ name: props.project.name });

const startRename = (): void => {
    renameForm.name = props.project.name;
    renameForm.clearErrors();
    renaming.value = true;
};

const rename = (): void => {
    renameForm.patch(updateProject(props.project.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            renaming.value = false;
        },
    });
};

const archive = (): void => {
    if (window.confirm(t('takes.projects.archive_confirm'))) {
        router.delete(archiveProject(props.project.id).url);
    }
};

const canManage = computed(
    () => page.props.auth.can?.createProject.allowed === true,
);
</script>

<template>
    <Head :title="project.name" />

    <StudioLayout :balance="balance" fluid>
        <template #switcher>
            <nav
                class="flex min-w-0 items-center gap-1.5 text-sm"
                aria-label="Breadcrumb"
            >
                <Link
                    :href="projectsIndex()"
                    class="text-muted-foreground hover:text-foreground"
                >
                    {{ t('takes.projects.title') }}
                </Link>
                <span aria-hidden="true" class="text-muted-foreground">/</span>
                <span class="truncate font-medium" aria-current="page">
                    {{ project.name }}
                </span>
            </nav>
        </template>
        <template #status>
            <LiveIndicator :state="updates.state.value" />
        </template>

        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <!--
                One composer: a sticky column on wide screens, a bottom sheet on
                phones (P10) with the max price on its button at every width.
            -->
            <aside
                ref="composerPanel"
                :aria-label="t('takes.composer.title')"
                :class="[
                    'z-20 border-t bg-background lg:static lg:w-[400px] lg:shrink-0 lg:border-t-0 lg:border-r',
                    'max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:rounded-t-2xl max-lg:shadow-[0_-8px_24px_-12px_rgb(0_0_0/0.25)]',
                    sheetExpanded
                        ? 'max-lg:max-h-[85svh] max-lg:overflow-y-auto'
                        : 'max-lg:max-h-16 max-lg:overflow-hidden',
                ]"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-2 px-4 py-3 text-sm font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 lg:hidden"
                    :aria-expanded="sheetExpanded"
                    @click="sheetExpanded = !sheetExpanded"
                >
                    {{ t('takes.composer.title') }}
                    <ChevronUp
                        aria-hidden="true"
                        :class="[
                            'size-4 transition-transform',
                            !sheetExpanded && 'rotate-180',
                        ]"
                    />
                </button>
                <div
                    class="p-4 pt-0 lg:sticky lg:top-14 lg:max-h-[calc(100svh-3.5rem)] lg:overflow-y-auto lg:p-6"
                >
                    <TakeComposer
                        ref="composerRef"
                        :project-id="project.id"
                        :config="composer"
                        :available-display="balance.available"
                    />
                </div>
            </aside>

            <section
                class="flex min-w-0 flex-1 flex-col gap-4 p-4 pb-24 sm:p-6 lg:pb-6"
                aria-labelledby="workspace-title"
            >
                <div
                    v-if="negativeBalance"
                    role="status"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-600/40 bg-red-50 px-3 py-2.5 text-sm text-red-900 dark:border-red-400/40 dark:bg-red-950/50 dark:text-red-100"
                >
                    <p>
                        {{
                            t('billing.negative_balance', {
                                amount: negativeBalance,
                            })
                        }}
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="buyCredits.open()"
                    >
                        {{ t('billing.buy_credits') }}
                    </Button>
                </div>
                <div
                    v-else-if="smallestTake.below"
                    role="status"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/50 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-400/40 dark:bg-amber-950/50 dark:text-amber-100"
                >
                    <p>
                        {{
                            t('takes.low_balance', { amount: smallestTake.max })
                        }}
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="buyCredits.open()"
                    >
                        {{ t('billing.buy_credits') }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-baseline gap-3">
                        <h1
                            id="workspace-title"
                            class="truncate text-xl font-semibold tracking-tight"
                        >
                            {{ project.name }}
                        </h1>
                        <span
                            class="shrink-0 text-sm text-muted-foreground tabular-nums"
                            :title="project.spendExact"
                        >
                            {{
                                t('takes.projects.summary', {
                                    count: project.takeCount,
                                    amount: project.spend,
                                })
                            }}
                        </span>
                        <DropdownMenu v-if="canManage">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 shrink-0"
                                    :aria-label="t('takes.projects.rename')"
                                >
                                    <EllipsisVertical aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuItem @select="startRename">
                                    {{ t('takes.projects.rename') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem @select="archive">
                                    {{ t('takes.projects.archive') }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <TakeFilterTabs v-model="filter" :counts="counts" />
                </div>

                <TakeGrid
                    :takes="takes"
                    :filtered="filter !== 'all'"
                    @open="openTake"
                    @cancel="cancel"
                />

                <div v-if="hasMoreTakes" class="flex justify-center">
                    <Button variant="outline" @click="loadMore">
                        {{ t('takes.projects.load_more') }}
                    </Button>
                </div>
            </section>
        </div>

        <TakeDrawer
            v-model:open="drawerOpen"
            v-model:comparing="comparing"
            :take="take"
            @remix="remix"
            @try-again="tryAgain"
            @cancel="cancel"
            @select-version="openTake"
            @describe-change="describing = true"
        >
            <template v-if="describing && take" #describe>
                <DescribeChangePanel
                    :key="take.id"
                    :take-id="take.id"
                    @applied="onSuggestionApplied"
                    @buy-credits="buyCredits.open()"
                />
            </template>
        </TakeDrawer>

        <Dialog v-model:open="renaming">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ t('takes.projects.rename') }}</DialogTitle>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="rename">
                    <Label for="project-name">{{
                        t('takes.projects.name')
                    }}</Label>
                    <Input
                        id="project-name"
                        v-model="renameForm.name"
                        maxlength="80"
                        required
                    />
                    <InputError :message="renameForm.errors.name" />
                    <DialogFooter>
                        <Button type="submit" :disabled="renameForm.processing">
                            {{ t('takes.projects.save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </StudioLayout>
</template>
