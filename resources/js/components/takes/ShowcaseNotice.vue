<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useCopy } from '@/composables/useCopy';
import { logout } from '@/routes';

/**
 * P03/P04 notice for the read-only showcase login (D-072): it browses real
 * history but cannot create, so the page says so and offers the two ways
 * to generate. Both sign the shared account out first (the demo panel and
 * registration are for guests); the server's sign-out redirects straight
 * to the allow-listed P02 demo panel or Register (`to`), replacing the
 * history entry, so the landing page never flashes in between.
 */
const page = usePage();
const { t } = useCopy();

const readOnly = computed(() => page.props.auth.customer?.read_only === true);
const leaving = ref(false);

const signOutTo = (to: 'login' | 'register'): void => {
    leaving.value = true;
    router.post(
        logout().url,
        { to },
        {
            replace: true,
            onFinish: () => {
                leaving.value = false;
            },
        },
    );
};
</script>

<template>
    <div
        v-if="readOnly"
        role="note"
        data-test="showcase-notice"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-600/30 bg-sky-50 px-3 py-2.5 text-sm text-sky-950 dark:border-sky-400/30 dark:bg-sky-950/40 dark:text-sky-100"
    >
        <p class="flex min-w-0 items-start gap-2">
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ t('takes.showcase.notice') }}</span>
        </p>
        <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                variant="outline"
                :disabled="leaving"
                @click="signOutTo('login')"
            >
                {{ t('landing.hero.demo') }}
            </Button>
            <Button
                size="sm"
                variant="outline"
                :disabled="leaving"
                @click="signOutTo('register')"
            >
                {{ t('takes.showcase.register') }}
            </Button>
        </div>
    </div>
</template>
