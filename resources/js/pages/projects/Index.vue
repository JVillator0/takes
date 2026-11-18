<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import ShowcaseNotice from '@/components/takes/ShowcaseNotice.vue';
import TakeImage from '@/components/takes/TakeImage.vue';
import TakeStatusBadge from '@/components/takes/TakeStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCopy } from '@/composables/useCopy';
import { useReturnFocus } from '@/composables/useReturnFocus';
import {
    index as projectsIndex,
    show as showProject,
    store as storeProject,
} from '@/routes/projects';
import type { ProjectSummary } from '@/types';

/**
 * P03, the customer home: one card per project (cover = newest finished
 * take, take count, spend from settled charges, In progress badge), search,
 * and "New project" (one field). The first visit already has "Untitled
 * project", so the composer is never blocked (P03 decision 1).
 */
type Props = {
    projects: ProjectSummary[];
    search: string;
};

const props = defineProps<Props>();

const page = usePage();
const { t, tc } = useCopy();

const search = ref(props.search);
const runSearch = useDebounceFn((value: string): void => {
    router.get(
        projectsIndex({ query: { search: value || undefined } }).url,
        {},
        { only: ['projects', 'search'], preserveState: true, replace: true },
    );
}, 250);

const creating = ref(false);
/* Two buttons open New project; focus returns to the one used (F-20). */
const createFocus = useReturnFocus();
const openCreate = (): void => {
    createFocus.remember();
    creating.value = true;
};
const form = useForm({ name: '' });
const canCreate = computed(
    () => page.props.auth.can?.createProject.allowed === true,
);

const create = (): void => {
    form.post(storeProject().url, {
        onSuccess: () => {
            creating.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Head :title="t('takes.projects.title')" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <ShowcaseNotice />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ t('takes.projects.title') }}
            </h1>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <Input
                    v-model="search"
                    type="search"
                    :aria-label="t('takes.projects.search')"
                    :placeholder="t('takes.projects.search')"
                    class="min-w-0 flex-1 sm:w-64"
                    @update:model-value="runSearch(String($event))"
                />
                <Button v-if="canCreate" @click="openCreate">
                    <Plus aria-hidden="true" />
                    {{ t('takes.projects.new') }}
                </Button>
            </div>
        </div>

        <ul
            v-if="projects.length"
            role="list"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <li v-for="project in projects" :key="project.id">
                <article
                    class="group relative flex h-full flex-col overflow-hidden rounded-xl border bg-card shadow-xs transition-shadow has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50 motion-safe:hover:shadow-md"
                >
                    <TakeImage
                        v-if="project.coverUrl"
                        :src="project.coverUrl"
                        alt=""
                        phase="done"
                        class="aspect-[16/9] border-b"
                    />
                    <div
                        v-else
                        class="flex aspect-[16/9] items-center justify-center border-b bg-muted text-xs text-muted-foreground"
                    >
                        {{ t('takes.projects.empty_cover') }}
                    </div>
                    <div class="flex flex-1 flex-col gap-1.5 p-3">
                        <h2 class="text-sm font-semibold">
                            <Link
                                :href="showProject(project.id)"
                                class="outline-none after:absolute after:inset-0 after:content-['']"
                            >
                                {{ project.name }}
                            </Link>
                        </h2>
                        <p
                            class="text-xs text-muted-foreground tabular-nums"
                            :title="project.spendExact"
                        >
                            {{
                                tc(
                                    'takes.projects.summary',
                                    project.takeCount,
                                    {
                                        amount: project.spend,
                                    },
                                )
                            }}
                        </p>
                        <TakeStatusBadge
                            v-if="project.inProgressCount > 0"
                            phase="processing"
                            :label="
                                t('takes.projects.in_progress', {
                                    count: project.inProgressCount,
                                })
                            "
                        />
                    </div>
                </article>
            </li>
        </ul>

        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center"
        >
            <p class="max-w-sm text-sm text-muted-foreground">
                {{
                    search
                        ? t('takes.projects.no_results')
                        : t('takes.projects.empty')
                }}
            </p>
            <Button v-if="canCreate && !search" @click="openCreate">
                <Plus aria-hidden="true" />
                {{ t('takes.projects.new') }}
            </Button>
        </div>

        <Dialog v-model:open="creating">
            <DialogContent
                class="sm:max-w-md"
                @close-auto-focus="createFocus.onCloseAutoFocus"
            >
                <DialogHeader>
                    <DialogTitle>{{ t('takes.projects.new') }}</DialogTitle>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="create">
                    <Label for="new-project-name">
                        {{ t('takes.projects.name') }}
                    </Label>
                    <Input
                        id="new-project-name"
                        v-model="form.name"
                        maxlength="80"
                        required
                        autofocus
                    />
                    <InputError :message="form.errors.name" />
                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('takes.projects.create') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
