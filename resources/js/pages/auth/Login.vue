<script setup lang="ts">
import type { Errors } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { DemoLogin } from '@/types';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

type DemoPanel = {
    title: string;
    description: string;
    adminHint: string;
    logins: DemoLogin[];
};

defineProps<{
    status?: string;
    canResetPassword: boolean;
    demoPanel: DemoPanel | null;
}>();

// Announces a failed or throttled demo sign-in (Fortify reports both under `email`).
const demoStatus = ref('');

function onDemoLoginError(errors: Errors): void {
    demoStatus.value = errors.email ?? '';
}
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Don't have an account?
            <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
        </div>
    </Form>

    <!-- P02 demo panel (spec 3-01): only when DEMO_LOGIN_ENABLED=true. -->
    <section
        v-if="demoPanel"
        class="mt-8 rounded-lg border border-dashed p-4"
        aria-labelledby="demo-panel-title"
        data-test="demo-panel"
    >
        <h2
            id="demo-panel-title"
            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
        >
            {{ demoPanel.title }}
        </h2>
        <p class="mt-2 text-sm text-muted-foreground">
            {{ demoPanel.description }}
        </p>

        <!-- Spec 4 adds the "Try a demo account" button (POST /demo) here. -->

        <ul class="mt-4 grid gap-2">
            <li v-for="login in demoPanel.logins" :key="login.key">
                <Form
                    v-bind="store.form()"
                    v-slot="{ errors, processing }"
                    :data-test="`demo-login-${login.key}`"
                    @start="demoStatus = ''"
                    @error="onDemoLoginError"
                >
                    <input type="hidden" name="email" :value="login.email" />
                    <input
                        type="hidden"
                        name="password"
                        :value="login.password"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                    >
                        {{ login.label }}
                    </Button>
                    <InputError class="mt-1" :message="errors.email" />
                </Form>
            </li>
        </ul>

        <p role="status" aria-live="polite" class="sr-only">
            {{ demoStatus }}
        </p>

        <p class="mt-3 text-xs text-muted-foreground">
            {{ demoPanel.adminHint }}
        </p>
    </section>
</template>
