<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { CalendarClock } from 'lucide-vue-next';
import AppButton from '@/components/ui/AppButton.vue';
import AppField from '@/components/ui/AppField.vue';

const props = defineProps<{
    demo: { owner: string; staff: string; customer: string; password: string };
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = (): void => {
    form.post('/login', { onFinish: () => form.reset('password') });
};

// One click to sign in as each role. A demo nobody can get into is a
// screenshot, and asking a reviewer to retype credentials is a tax on the
// thing you actually want them to look at.
const fill = (email: string): void => {
    form.email = email;
    form.password = props.demo.password;
};
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-full items-center justify-center bg-surface-sunken px-4 py-12">
        <div class="w-full max-w-sm">
            <Link href="/" class="mb-8 flex items-center justify-center gap-2.5">
                <span class="flex size-9 items-center justify-center rounded-lg bg-brand text-brand-ink">
                    <CalendarClock class="size-4.5" />
                </span>
                <span class="text-base font-semibold tracking-tight text-ink">SlotFlow</span>
            </Link>

            <div class="panel p-6">
                <h1 class="text-lg font-semibold tracking-tight text-ink">Sign in</h1>
                <p class="mt-1 text-xs text-ink-muted">Access the studio's admin panel.</p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <AppField label="Email" :error="form.errors.email" required for="email">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="field"
                            autocomplete="username"
                            required
                        />
                    </AppField>

                    <AppField label="Password" :error="form.errors.password" required for="password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="field"
                            autocomplete="current-password"
                            required
                        />
                    </AppField>

                    <label class="flex items-center gap-2 text-xs text-ink-muted">
                        <input v-model="form.remember" type="checkbox" class="rounded border-line-strong" />
                        Keep me signed in
                    </label>

                    <AppButton type="submit" block :loading="form.processing">Sign in</AppButton>
                </form>
            </div>

            <div class="panel mt-4 p-4">
                <p class="text-[0.6875rem] font-medium uppercase tracking-wide text-ink-subtle">
                    Demo accounts
                </p>
                <div class="mt-2.5 space-y-1.5">
                    <button
                        v-for="(email, role) in { Owner: demo.owner, Staff: demo.staff, Customer: demo.customer }"
                        :key="role"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-lg px-2.5 py-1.5 text-left text-xs transition hover:bg-surface-sunken"
                        @click="fill(email)"
                    >
                        <span class="font-medium text-ink">{{ role }}</span>
                        <span class="truncate text-ink-subtle">{{ email }}</span>
                    </button>
                </div>
                <p class="mt-2.5 text-[0.6875rem] text-ink-subtle">
                    Password for all three: <code class="font-mono text-ink-muted">{{ demo.password }}</code>
                </p>
            </div>
        </div>
    </div>
</template>
