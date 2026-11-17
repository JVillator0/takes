<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TakeStatusBadge from '@/components/takes/TakeStatusBadge.vue';
import TakesMark from '@/components/takes/TakesMark.vue';
import { Button } from '@/components/ui/button';
import { useCopy } from '@/composables/useCopy';
import { dashboard, login, register } from '@/routes';

type PricingExample = {
    label: string;
    /** Server-formatted estimate range, 4 decimals (D-021). */
    range: string;
};

type Props = {
    /** Welcome credit for new accounts, server-formatted (D-019). */
    welcomeCredit: string;
    /** "Try a demo account" (D-045); spec 4 enables it and passes the POST /demo URL. */
    demo?: { enabled: boolean; action: string | null };
    pricingExamples: PricingExample[];
};

withDefaults(defineProps<Props>(), {
    demo: () => ({ enabled: false, action: null }),
});

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth.customer));
const { t } = useCopy();

const steps = (['describe', 'take', 'remix'] as const).map((step) => ({
    title: t(`landing.steps.${step}_title`),
    body: t(`landing.steps.${step}`),
}));

const tiles = [
    {
        label: t('landing.example.take', { id: 1 }),
        tone: 'from-sky-300 via-orange-200 to-slate-700',
    },
    {
        label: t('landing.example.take_warmer', { id: 2 }),
        tone: 'from-amber-300 via-orange-400 to-rose-900',
    },
    {
        label: t('landing.example.take_portrait', { id: 3 }),
        tone: 'from-slate-300 via-slate-500 to-slate-900',
    },
    {
        label: t('landing.example.take', { id: 4 }),
        tone: 'from-indigo-300 via-violet-400 to-slate-900',
    },
];
</script>

<template>
    <Head :title="t('landing.meta_title')" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex h-14 w-full max-w-6xl items-center gap-4 px-4 sm:px-6"
            >
                <TakesMark />
                <nav
                    :aria-label="t('landing.nav.label')"
                    class="hidden items-center gap-5 text-sm text-muted-foreground md:flex"
                >
                    <a href="#how-it-works" class="hover:text-foreground">
                        {{ t('landing.nav.how_it_works') }}
                    </a>
                    <a href="#pricing" class="hover:text-foreground">
                        {{ t('landing.nav.pricing') }}
                    </a>
                </nav>
                <div class="ml-auto flex items-center gap-2">
                    <Button v-if="signedIn" as-child size="sm">
                        <Link :href="dashboard()">{{
                            t('landing.nav.open_app')
                        }}</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="login()">{{
                                t('landing.nav.sign_in')
                            }}</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="register()">{{
                                t('landing.nav.start_free')
                            }}</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main id="main" class="flex-1">
            <section
                aria-labelledby="hero-title"
                class="mx-auto grid w-full max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 md:py-20 lg:grid-cols-[1fr_1.05fr]"
            >
                <div class="flex flex-col gap-6">
                    <h1
                        id="hero-title"
                        class="text-5xl leading-[1.02] font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        {{ t('landing.hero.line_1') }}<br />
                        {{ t('landing.hero.line_2') }}<br />
                        {{ t('landing.hero.line_3') }}
                    </h1>
                    <p class="max-w-md text-lg text-muted-foreground">
                        {{ t('landing.hero.lead') }}
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button as-child size="lg">
                            <Link :href="register()">
                                {{
                                    t('landing.hero.start', {
                                        amount: welcomeCredit,
                                    })
                                }}
                            </Link>
                        </Button>
                        <Button
                            v-if="demo.enabled && demo.action"
                            as-child
                            variant="outline"
                            size="lg"
                        >
                            <Link :href="demo.action" method="post" as="button">
                                {{ t('landing.hero.demo') }}
                            </Link>
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ t('landing.hero.no_card') }}
                    </p>
                </div>

                <figure
                    class="rounded-2xl border bg-card p-4 shadow-sm sm:p-5"
                    :aria-label="t('landing.example.label')"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold">
                            {{ t('landing.example.project') }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ t('landing.example.count', { count: 5 }) }}
                        </span>
                    </div>
                    <ul
                        role="list"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                    >
                        <li
                            v-for="tile in tiles"
                            :key="tile.label"
                            :class="[
                                'relative flex aspect-[4/3] items-end overflow-hidden rounded-lg bg-linear-to-b p-2',
                                tile.tone,
                            ]"
                        >
                            <span
                                class="rounded bg-black/45 px-1.5 py-0.5 text-[11px] font-medium text-white"
                            >
                                {{ tile.label }}
                            </span>
                        </li>
                        <li
                            class="flex aspect-[4/3] items-center justify-center rounded-lg border border-dashed bg-muted motion-safe:animate-pulse"
                        >
                            <TakeStatusBadge
                                phase="processing"
                                :label="t('takes.status.processing')"
                            />
                        </li>
                    </ul>
                </figure>
            </section>

            <section
                id="how-it-works"
                aria-labelledby="how-title"
                class="border-t bg-muted/40"
            >
                <div class="mx-auto w-full max-w-6xl px-4 py-12 sm:px-6">
                    <h2 id="how-title" class="text-2xl font-semibold">
                        {{ t('landing.steps.title') }}
                    </h2>
                    <ol role="list" class="mt-6 grid gap-4 md:grid-cols-3">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="rounded-xl border bg-card p-5"
                        >
                            <p
                                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ index + 1 }} · {{ step.title }}
                            </p>
                            <p class="mt-2 text-sm">{{ step.body }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <section
                id="pricing"
                aria-labelledby="pricing-title"
                class="border-t"
            >
                <div
                    class="mx-auto grid w-full max-w-6xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-2"
                >
                    <div class="flex flex-col gap-3">
                        <h2 id="pricing-title" class="text-2xl font-semibold">
                            {{ t('landing.pricing.title') }}
                        </h2>
                        <p class="text-muted-foreground">
                            {{ t('landing.pricing.body') }}
                            <strong class="font-semibold text-foreground">
                                {{ t('takes.estimate.failed_free') }}
                            </strong>
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                t('landing.pricing.welcome', {
                                    amount: welcomeCredit,
                                })
                            }}
                            <span class="whitespace-nowrap">
                                {{ t('landing.pricing.credit_rate') }}
                            </span>
                        </p>
                    </div>
                    <table class="w-full self-start text-sm">
                        <caption class="sr-only">
                            {{
                                t('landing.pricing.caption')
                            }}
                        </caption>
                        <thead>
                            <tr
                                class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th scope="col" class="py-2 font-semibold">
                                    {{ t('landing.pricing.example_take') }}
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-semibold"
                                >
                                    {{ t('landing.pricing.price_range') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="example in pricingExamples"
                                :key="example.label"
                                class="border-b"
                            >
                                <td class="py-3 pr-4">{{ example.label }}</td>
                                <td
                                    class="py-3 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ example.range }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto w-full max-w-6xl px-4 py-6 text-xs text-muted-foreground sm:px-6"
            >
                {{ t('landing.footer') }}
            </div>
        </footer>
    </div>
</template>
