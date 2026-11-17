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
const signedIn = computed(() => Boolean(page.props.auth.user));
const { t } = useCopy();

const steps = [
    {
        title: 'Describe',
        body: 'Write a prompt, pick size and quality. The price range shows before you start.',
    },
    {
        title: 'Take',
        body: 'Every generation is a take, saved in a project, with its exact cost.',
    },
    {
        title: 'Remix',
        body: 'Branch any take, or describe a change (“warmer, portrait”) and review it before it runs.',
    },
];

const tiles = [
    { label: 'take #1', tone: 'from-sky-300 via-orange-200 to-slate-700' },
    {
        label: 'take #2 · warmer',
        tone: 'from-amber-300 via-orange-400 to-rose-900',
    },
    {
        label: 'take #3 · portrait',
        tone: 'from-slate-300 via-slate-500 to-slate-900',
    },
    { label: 'take #4', tone: 'from-indigo-300 via-violet-400 to-slate-900' },
];
</script>

<template>
    <Head title="Describe it. Take it. Remix it." />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex h-14 w-full max-w-6xl items-center gap-4 px-4 sm:px-6"
            >
                <TakesMark />
                <nav
                    aria-label="Sections"
                    class="hidden items-center gap-5 text-sm text-muted-foreground md:flex"
                >
                    <a href="#how-it-works" class="hover:text-foreground">
                        How it works
                    </a>
                    <a href="#pricing" class="hover:text-foreground">Pricing</a>
                </nav>
                <div class="ml-auto flex items-center gap-2">
                    <Button v-if="signedIn" as-child size="sm">
                        <Link :href="dashboard()">Open Takes</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="outline" size="sm">
                            <Link :href="login()">Sign in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="register()">Start free</Link>
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
                        Describe it.<br />
                        Take it.<br />
                        Remix it.
                    </h1>
                    <p class="max-w-md text-lg text-muted-foreground">
                        Generate images, iterate on them as versions, and always
                        see the price before you press Generate.
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button as-child size="lg">
                            <Link :href="register()">
                                Start with {{ welcomeCredit }} free
                            </Link>
                        </Button>
                        <Button
                            v-if="demo.enabled && demo.action"
                            as-child
                            variant="outline"
                            size="lg"
                        >
                            <Link :href="demo.action" method="post" as="button">
                                Try a demo account
                            </Link>
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        No card. Credits are simulated for this demo.
                    </p>
                </div>

                <figure
                    class="rounded-2xl border bg-card p-4 shadow-sm sm:p-5"
                    aria-label="Example project with five takes"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold">
                            Lighthouse series
                        </span>
                        <span class="text-xs text-muted-foreground"
                            >5 takes</span
                        >
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
                        How it works
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
                            Clear pricing
                        </h2>
                        <p class="text-muted-foreground">
                            Pay per take from prepaid credits. See the price
                            range before you generate, the exact amount after.
                            <strong class="font-semibold text-foreground">
                                Failed takes cost nothing.
                            </strong>
                        </p>
                        <p class="text-muted-foreground">
                            New accounts start with {{ welcomeCredit }} of
                            credits.
                            <span class="whitespace-nowrap">
                                1 credit = $1.00.
                            </span>
                        </p>
                    </div>
                    <table class="w-full self-start text-sm">
                        <caption class="sr-only">
                            Example prices per take
                        </caption>
                        <thead>
                            <tr
                                class="border-b text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th scope="col" class="py-2 font-semibold">
                                    Example take
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-semibold"
                                >
                                    Price range
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
                Takes is a take-home project. Payments are simulated.
            </div>
        </footer>
    </div>
</template>
