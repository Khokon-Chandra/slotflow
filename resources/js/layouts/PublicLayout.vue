<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarClock } from 'lucide-vue-next';
import AccountMenu from '@/components/AccountMenu.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import AppButton from '@/components/ui/AppButton.vue';

const page = usePage();
const tenant = computed(() => page.props.tenant);
</script>

<template>
    <div class="flex min-h-full flex-col bg-surface-sunken">
        <header class="sticky top-0 z-40 border-b border-line bg-surface/85 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link href="/" class="flex items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-brand text-brand-ink">
                        <CalendarClock class="size-4" />
                    </span>
                    <span class="text-sm font-semibold tracking-tight text-ink">
                        {{ tenant?.name ?? 'SlotFlow' }}
                    </span>
                </Link>

                <nav class="flex items-center gap-1">
                    <Link
                        href="/"
                        class="rounded-lg px-3 py-2 text-sm text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
                    >
                        Services
                    </Link>
                    <ThemeToggle />

                    <!-- Covers every role in one component. The previous
                         admin/guest branch had no arm for a signed-in
                         customer, who could therefore never sign out. -->
                    <AccountMenu />

                    <AppButton href="/book" size="sm">Book</AppButton>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-8 text-xs text-ink-subtle sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p>
                    {{ tenant?.name }} · all times shown in
                    <span class="font-medium text-ink-muted">{{ tenant?.timezone }}</span>
                    unless stated otherwise
                </p>
                <p>
                    A portfolio demonstration. Every business, person and booking here is invented.
                </p>
            </div>
        </footer>
    </div>
</template>
