<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import BalancePill from '@/components/takes/BalancePill.vue';
import ComposerShell from '@/components/takes/ComposerShell.vue';
import DescribeChangePanel from '@/components/takes/DescribeChangePanel.vue';
import EstimatePanel from '@/components/takes/EstimatePanel.vue';
import ServiceNotConfiguredBanner from '@/components/takes/ServiceNotConfiguredBanner.vue';
import TakeDrawer from '@/components/takes/TakeDrawer.vue';
import TakeFilterTabs from '@/components/takes/TakeFilterTabs.vue';
import TakeGrid from '@/components/takes/TakeGrid.vue';
import TakeStatusBadge from '@/components/takes/TakeStatusBadge.vue';
import { useCopy } from '@/composables/useCopy';
import StudioLayout from '@/layouts/StudioLayout.vue';
import { gallery } from '@/routes/dev';
import type {
    BalanceSummary,
    SuggestionData,
    SuggestionQuote,
    TakeCardData,
    TakeDetailData,
    TakeEstimate,
    TakeFilter,
    TakeFilterCounts,
    TakePhase,
    TakeQuality,
    TakeSize,
} from '@/types';

type Props = {
    balance: BalanceSummary;
    takes: TakeCardData[];
    extraStates: TakeCardData[];
    counts: TakeFilterCounts;
    details: Record<'done' | 'failed' | 'waiting', TakeDetailData>;
    estimates: Record<'square' | 'remix' | 'heavy', TakeEstimate>;
    lowBalance: BalanceSummary;
    insufficientMessage: string;
    describeChange: {
        quote: SuggestionQuote;
        paused: SuggestionQuote;
        proposed: SuggestionData;
        unaffordable: SuggestionData;
        failed: SuggestionData;
    };
};

const props = defineProps<Props>();
const { t } = useCopy();

const sections = [
    { id: 'shell', title: 'App shell and balance' },
    { id: 'badges', title: 'Status badges' },
    { id: 'grid', title: 'Takes grid' },
    { id: 'states', title: 'More card states' },
    { id: 'empty', title: 'Empty and loading' },
    { id: 'estimate', title: 'Estimate panel' },
    { id: 'composer', title: 'Composer' },
    { id: 'drawer', title: 'Take drawer' },
];

const phases: TakePhase[] = [
    'submitting',
    'waiting_for_capacity',
    'queued',
    'processing',
    'done',
    'failed',
];

/** Last event a component emitted, so reviewers can see the wiring. */
const lastEvent = ref('none yet');
const log = (name: string, id?: number): void => {
    lastEvent.value = id === undefined ? name : `${name} #${id}`;
};

const filter = ref<TakeFilter>('all');
const visibleTakes = computed(() =>
    props.takes.filter((take) => {
        switch (filter.value) {
            case 'in_progress':
                return take.phase !== 'done' && take.phase !== 'failed';
            case 'done':
                return take.phase === 'done';
            case 'failed':
                return take.phase === 'failed';
            default:
                return true;
        }
    }),
);

type ComposerDraft = {
    prompt: string;
    size: TakeSize;
    quality: TakeQuality;
    references: string[];
};

const draft = (overrides: Partial<ComposerDraft> = {}): ComposerDraft =>
    reactive({
        prompt: '',
        size: '1024x1024',
        quality: 'medium',
        references: [],
        ...overrides,
    });

const composers = {
    ready: draft({
        prompt: 'Lighthouse at dusk, warm light, cinematic wide shot, low horizon',
        references: ['https://images.example.com/lighthouse.jpg'],
    }),
    insufficient: draft({
        prompt: 'Harbour at night, long exposure, light trails',
        size: '1536x1024',
        quality: 'high',
        references: [
            'https://images.example.com/harbour.jpg',
            'https://images.example.com/boats.jpg',
        ],
    }),
    validation: draft({
        prompt: 'Lighthouse '.repeat(383).slice(0, 4212),
        references: ['ftp://images.example.com/a.png'],
    }),
    unconfigured: draft({ prompt: 'A lighthouse on a cliff at dusk' }),
    recomputing: draft({
        prompt: 'Night version with stars',
        quality: 'high',
    }),
    estimateFailed: draft({ prompt: 'Harbour at dawn' }),
    submitting: draft({ prompt: 'Night version with stars' }),
};

const drawerOpen = ref(false);
const comparing = ref(false);
const drawerTake = ref<TakeDetailData | null>(null);
const openDrawer = (key: 'done' | 'failed' | 'waiting'): void => {
    drawerTake.value = props.details[key];
    comparing.value = false;
    drawerOpen.value = true;
};

const openFromGrid = (id: number): void => {
    log('open', id);

    const match = Object.values(props.details).find((take) => take.id === id);

    if (match) {
        drawerTake.value = match;
        drawerOpen.value = true;
    }
};

const sectionTitle = 'text-lg font-semibold';
const caption = 'text-xs font-medium text-muted-foreground';
</script>

<template>
    <Head title="Component gallery" />

    <StudioLayout
        :balance="balance"
        show-buy-credits
        :breadcrumbs="[{ title: 'Component gallery', href: gallery() }]"
        @buy-credits="log('buyCredits')"
    >
        <div class="flex flex-col gap-12 px-4 py-8">
            <div class="flex flex-col gap-3">
                <h1 class="text-2xl font-semibold">Component gallery</h1>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    Every take component in every state, with the demo dataset
                    (Ana López, 2026-11-17 12:02 UTC) and the real copy from
                    <code>lang/en</code>. Local and testing only.
                </p>
                <nav aria-label="Gallery sections">
                    <ul role="list" class="flex flex-wrap gap-2 text-sm">
                        <li v-for="section in sections" :key="section.id">
                            <a
                                :href="`#${section.id}`"
                                class="rounded-full border px-3 py-1 hover:bg-accent"
                            >
                                {{ section.title }}
                            </a>
                        </li>
                    </ul>
                </nav>
                <p
                    role="status"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    Last event: <strong>{{ lastEvent }}</strong>
                </p>
            </div>

            <section id="shell" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">App shell and balance</h2>
                <p class="text-sm text-muted-foreground">
                    The top bar above is <code>StudioLayout</code> with the
                    balance pill and Buy credits. At phone width the pill keeps
                    Available and On hold and drops only the word "Available"
                    (P10).
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <BalancePill :balance="balance" />
                    <BalancePill :balance="lowBalance" />
                </div>
                <ServiceNotConfiguredBanner />
            </section>

            <section id="badges" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Status badges</h2>
                <div class="flex flex-wrap gap-2">
                    <TakeStatusBadge
                        v-for="phase in phases"
                        :key="phase"
                        :phase="phase"
                        :label="t(`takes.status.${phase}`)"
                    />
                </div>
            </section>

            <section id="grid" class="flex flex-col gap-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h2 :class="sectionTitle">Lighthouse series</h2>
                        <p class="text-sm text-muted-foreground">
                            8 takes · $0.3525 spent
                        </p>
                    </div>
                    <TakeFilterTabs v-model="filter" :counts="counts" />
                </div>
                <TakeGrid
                    :takes="visibleTakes"
                    :filtered="filter !== 'all'"
                    @open="openFromGrid"
                    @cancel="log('cancel', $event)"
                />
            </section>

            <section id="states" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">More card states</h2>
                <p class="text-sm text-muted-foreground">
                    Submitting, Submitting after an ambiguous error, Waiting for
                    capacity (busy), content policy (no Try again), cancelled,
                    done with an expired image link, daily limit.
                </p>
                <TakeGrid
                    :takes="extraStates"
                    @open="log('open', $event)"
                    @cancel="log('cancel', $event)"
                />
            </section>

            <section id="empty" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Empty and loading</h2>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <span :class="caption">No takes yet</span>
                        <TakeGrid :takes="[]" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption">Filter with no matches</span>
                        <TakeGrid :takes="[]" filtered />
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <span :class="caption">Loading (deferred prop)</span>
                    <TakeGrid :takes="[]" loading />
                </div>
            </section>

            <section id="estimate" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Estimate panel</h2>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div class="flex flex-col gap-2">
                        <span :class="caption">Idle</span>
                        <EstimatePanel :estimate="null" state="idle" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption">First load</span>
                        <EstimatePanel :estimate="null" state="loading" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption"
                            >Recomputing (previous dimmed)</span
                        >
                        <EstimatePanel
                            :estimate="estimates.square"
                            state="loading"
                        />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption">Ready</span>
                        <EstimatePanel
                            :estimate="estimates.remix"
                            state="ready"
                        />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption"
                            >Denied: insufficient credits</span
                        >
                        <EstimatePanel
                            :estimate="estimates.heavy"
                            state="denied"
                            :available-display="lowBalance.available"
                            :denied-message="insufficientMessage"
                        />
                    </div>
                    <div class="flex flex-col gap-2">
                        <span :class="caption">Error</span>
                        <EstimatePanel :estimate="null" state="error" />
                    </div>
                </div>
            </section>

            <section id="composer" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Composer</h2>
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">P04 · remixing, ready</span>
                        <ComposerShell
                            v-model:prompt="composers.ready.prompt"
                            v-model:size="composers.ready.size"
                            v-model:quality="composers.ready.quality"
                            v-model:references="composers.ready.references"
                            :estimate="estimates.remix"
                            estimate-state="ready"
                            :remixing-from="8"
                            @submit="log('submit')"
                            @cancel-remix="log('cancelRemix', 8)"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption"
                            >P05-A · insufficient balance</span
                        >
                        <ComposerShell
                            v-model:prompt="composers.insufficient.prompt"
                            v-model:size="composers.insufficient.size"
                            v-model:quality="composers.insufficient.quality"
                            v-model:references="
                                composers.insufficient.references
                            "
                            :estimate="estimates.heavy"
                            estimate-state="denied"
                            :available-display="lowBalance.available"
                            :denied-message="insufficientMessage"
                            show-buy-credits
                            @submit="log('submit')"
                            @buy-credits="log('buyCredits')"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">P05-B · inline validation</span>
                        <ComposerShell
                            v-model:prompt="composers.validation.prompt"
                            v-model:size="composers.validation.size"
                            v-model:quality="composers.validation.quality"
                            v-model:references="composers.validation.references"
                            :estimate="null"
                            estimate-state="idle"
                            @submit="log('submit')"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">
                            Recomputing · previous max dimmed, not submittable
                        </span>
                        <ComposerShell
                            v-model:prompt="composers.recomputing.prompt"
                            v-model:size="composers.recomputing.size"
                            v-model:quality="composers.recomputing.quality"
                            v-model:references="
                                composers.recomputing.references
                            "
                            :estimate="estimates.square"
                            estimate-state="loading"
                            @submit="log('submit')"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">
                            Estimate failed · no price, not submittable
                        </span>
                        <ComposerShell
                            v-model:prompt="composers.estimateFailed.prompt"
                            v-model:size="composers.estimateFailed.size"
                            v-model:quality="composers.estimateFailed.quality"
                            v-model:references="
                                composers.estimateFailed.references
                            "
                            :estimate="null"
                            estimate-state="error"
                            @submit="log('submit')"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">Service not configured</span>
                        <ComposerShell
                            v-model:prompt="composers.unconfigured.prompt"
                            v-model:size="composers.unconfigured.size"
                            v-model:quality="composers.unconfigured.quality"
                            v-model:references="
                                composers.unconfigured.references
                            "
                            :estimate="estimates.square"
                            estimate-state="ready"
                            :service-configured="false"
                            @submit="log('submit')"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span :class="caption">Submitting</span>
                        <ComposerShell
                            v-model:prompt="composers.submitting.prompt"
                            v-model:size="composers.submitting.size"
                            v-model:quality="composers.submitting.quality"
                            v-model:references="composers.submitting.references"
                            :estimate="estimates.square"
                            estimate-state="ready"
                            processing
                            @submit="log('submit')"
                        />
                    </div>
                </div>
            </section>

            <section id="describe-change" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Describe a change</h2>
                <p class="text-sm text-muted-foreground">
                    P07 panel states. Fixtures only: nothing here calls the
                    server until you press a button.
                </p>
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span class="text-xs text-muted-foreground">Ask</span>
                        <DescribeChangePanel
                            take-id="01JGALLERYTAKE000000000010"
                            :initial-quote="describeChange.quote"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span class="text-xs text-muted-foreground"
                            >Proposal</span
                        >
                        <DescribeChangePanel
                            take-id="01JGALLERYTAKE000000000010"
                            :initial-quote="describeChange.quote"
                            :initial-suggestion="describeChange.proposed"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span class="text-xs text-muted-foreground"
                            >Proposal, new take unaffordable</span
                        >
                        <DescribeChangePanel
                            take-id="01JGALLERYTAKE000000000010"
                            :initial-quote="describeChange.quote"
                            :initial-suggestion="describeChange.unaffordable"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span class="text-xs text-muted-foreground"
                            >Failed (not charged)</span
                        >
                        <DescribeChangePanel
                            take-id="01JGALLERYTAKE000000000010"
                            :initial-quote="describeChange.quote"
                            :initial-suggestion="describeChange.failed"
                        />
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl border p-4">
                        <span class="text-xs text-muted-foreground"
                            >Paused (ai_paused)</span
                        >
                        <DescribeChangePanel
                            take-id="01JGALLERYTAKE000000000010"
                            :initial-quote="describeChange.paused"
                        />
                    </div>
                </div>
            </section>

            <section id="drawer" class="flex flex-col gap-4">
                <h2 :class="sectionTitle">Take drawer</h2>
                <p class="text-sm text-muted-foreground">
                    Opens as a sheet (Esc closes). Cards above with a detail
                    fixture (#13, #10, #9) open it too.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm hover:bg-accent"
                        @click="openDrawer('done')"
                    >
                        Take #10 · Done, with parent
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm hover:bg-accent"
                        @click="openDrawer('failed')"
                    >
                        Take #9 · Failed
                    </button>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm hover:bg-accent"
                        @click="openDrawer('waiting')"
                    >
                        Take #13 · Waiting for capacity
                    </button>
                </div>
            </section>
        </div>

        <TakeDrawer
            v-model:open="drawerOpen"
            v-model:comparing="comparing"
            :take="drawerTake"
            @remix="log('remix', $event)"
            @try-again="log('tryAgain', $event)"
            @describe-change="log('describeChange', $event)"
            @cancel="log('cancel', $event)"
            @select-version="log('selectVersion', $event)"
        />
    </StudioLayout>
</template>
