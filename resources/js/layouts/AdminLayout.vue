<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    CalendarClock,
    LayoutDashboard,
    CalendarDays,
    Scissors,
    Users,
    Sparkles,
    Settings,
    LogOut,
    Menu,
    X,
    ExternalLink,
} from 'lucide-vue-next';
import ThemeToggle from '@/components/ThemeToggle.vue';
import AppBadge from '@/components/ui/AppBadge.vue';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const tenant = computed(() => page.props.tenant);
const aiLive = computed(() => page.props.ai.live);
const flash = computed(() => page.props.flash);

const mobileOpen = ref(false);

// `ownerOnly` entries are filtered out for staff — they would 403 on click,
// and a menu item that refuses you is worse than no menu item.
const nav = [
    { label: 'Overview', href: '/admin', icon: LayoutDashboard },
    { label: 'Diary', href: '/admin/bookings', icon: CalendarDays },
    { label: 'Services', href: '/admin/services', icon: Scissors },
    { label: 'Team', href: '/admin/team', icon: Users },
    { label: 'AI usage', href: '/admin/ai', icon: Sparkles },
    { label: 'AI providers', href: '/admin/ai-providers', icon: KeyRound, ownerOnly: true },
    { label: 'Settings', href: '/admin/settings', icon: Settings },
];

const visibleNav = computed(() => nav.filter((item) => !item.ownerOnly || user.value?.is_admin));

// Exact match for the index, prefix match for everything else — otherwise
// "/admin" stays highlighted on every child page.
const isCurrent = (href: string): boolean =>
    href === '/admin'
        ? page.url === '/admin' || page.url === '/admin/'
        : page.url.startsWith(href);

const signOut = (): void => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-full lg:grid lg:grid-cols-[16rem_1fr]">
        <!--
            Sidebar — exactly one viewport tall, at every breakpoint.

            Mobile: `fixed inset-y-0` is an off-canvas drawer.

            Desktop: `sticky` + `h-dvh` + `self-start`, not `static`. As a
            plain grid item it stretched to the *row* height, which is the
            height of the content column — so on a long page (the diary at 20
            rows) the sidebar became taller than the screen, scrolled away with
            the page, and the account block pinned to its bottom ended up far
            below the fold.

            `self-start` is load-bearing: a grid item stretched to fill its row
            has nowhere to stick, so sticky silently does nothing without it.

            Flex column rather than an absolutely positioned footer, so the nav
            can scroll on its own when the menu outgrows a short screen instead
            of rendering behind the account block.
        -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0 lg:self-start"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-line px-4">
                <Link href="/admin" class="flex items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-brand text-brand-ink">
                        <CalendarClock class="size-4" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-ink">{{ tenant?.name }}</span>
                        <span class="block text-[0.6875rem] text-ink-subtle">SlotFlow admin</span>
                    </span>
                </Link>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-ink-subtle lg:hidden"
                    aria-label="Close menu"
                    @click="mobileOpen = false"
                >
                    <X class="size-4" />
                </button>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
                <Link
                    v-for="item in visibleNav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition"
                    :class="
                        isCurrent(item.href)
                            ? 'bg-brand-soft font-medium text-brand-strong'
                            : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'
                    "
                    @click="mobileOpen = false"
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    {{ item.label }}
                </Link>
            </nav>

            <div class="shrink-0 space-y-3 border-t border-line p-3">
                <Link
                    href="/"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-xs text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
                >
                    <ExternalLink class="size-3.5" />
                    View booking page
                </Link>

                <div class="flex items-center justify-between gap-2 rounded-lg bg-surface-sunken px-3 py-2">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-medium text-ink">{{ user?.name }}</p>
                        <p class="truncate text-[0.6875rem] capitalize text-ink-subtle">{{ user?.role }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-ink-subtle transition hover:text-critical"
                        aria-label="Sign out"
                        @click="signOut"
                    >
                        <LogOut class="size-3.5" />
                    </button>
                </div>
            </div>
        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-40 bg-ink/40 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Content -->
        <div class="flex min-h-full flex-col">
            <header class="sticky top-0 z-30 border-b border-line bg-surface/85 backdrop-blur">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-ink-muted lg:hidden"
                        aria-label="Open menu"
                        @click="mobileOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>

                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-base font-semibold tracking-tight text-ink">{{ title }}</h1>
                        <p v-if="subtitle" class="truncate text-xs text-ink-muted">{{ subtitle }}</p>
                    </div>

                    <!-- The AI mode is always visible. Someone reading a
                         model-written briefing should not have to guess whether
                         a model wrote it. -->
                    <AppBadge :tone="aiLive ? 'brand' : 'neutral'" size="sm">
                        <Sparkles class="size-3" />
                        {{ aiLive ? 'AI live' : 'AI offline' }}
                    </AppBadge>

                    <ThemeToggle />
                </div>
            </header>

            <div
                v-if="flash.success || flash.error"
                class="mx-4 mt-4 rounded-lg border px-4 py-2.5 text-sm sm:mx-6"
                :class="
                    flash.success
                        ? 'border-positive/30 bg-positive-soft text-positive'
                        : 'border-critical/30 bg-critical-soft text-critical'
                "
            >
                {{ flash.success ?? flash.error }}
            </div>

            <main class="flex-1 p-4 sm:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
