<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Clock, MessageSquareText, ShieldCheck, Globe2 } from 'lucide-vue-next';
import PublicLayout from '@/layouts/PublicLayout.vue';
import AppButton from '@/components/ui/AppButton.vue';
import { money, duration } from '@/lib/format';
import type { ServiceSummary } from '@/types';

defineProps<{
    services: ServiceSummary[];
    team: { id: number; name: string; title: string | null; bio: string | null; timezone: string }[];
    business: { description: string | null; phone: string | null; email: string | null };
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant);
const currency = computed(() => tenant.value?.currency ?? 'EUR');
</script>

<template>
    <Head title="Book an appointment" />

    <PublicLayout>
        <!-- Hero -->
        <section class="border-b border-line bg-surface">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                <div class="max-w-2xl">
                    <p class="text-xs font-medium uppercase tracking-widest text-brand">
                        {{ tenant?.name }}
                    </p>
                    <h1 class="mt-3 text-4xl font-semibold tracking-tight text-ink sm:text-5xl">
                        Say what you need.<br />Pick a time that is actually free.
                    </h1>
                    <p class="mt-5 max-w-xl text-base leading-relaxed text-ink-muted">
                        {{ business.description }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <AppButton href="/book" size="lg">Book an appointment</AppButton>
                        <AppButton href="#services" variant="secondary" size="lg">See what we do</AppButton>
                    </div>

                    <dl class="mt-12 grid gap-6 sm:grid-cols-3">
                        <div>
                            <dt class="flex items-center gap-2 text-xs font-medium text-ink-muted">
                                <MessageSquareText class="size-3.5 text-brand" />
                                Book in plain English
                            </dt>
                            <dd class="mt-1.5 text-xs leading-relaxed text-ink-subtle">
                                "A haircut next Tuesday afternoon" is enough. No calendar grid to fight.
                            </dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 text-xs font-medium text-ink-muted">
                                <Globe2 class="size-3.5 text-brand" />
                                Your clock, not ours
                            </dt>
                            <dd class="mt-1.5 text-xs leading-relaxed text-ink-subtle">
                                Times are shown in your timezone and confirmed in it too.
                            </dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 text-xs font-medium text-ink-muted">
                                <ShieldCheck class="size-3.5 text-brand" />
                                No double bookings
                            </dt>
                            <dd class="mt-1.5 text-xs leading-relaxed text-ink-subtle">
                                If you can see the slot, it is held for you the moment you confirm.
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <!-- Services -->
        <section id="services" class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <h2 class="text-xl font-semibold tracking-tight text-ink">What we do</h2>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="service in services"
                    :key="service.id"
                    :href="`/book?service=${service.slug}`"
                    class="panel group flex flex-col p-5 transition hover:border-line-strong"
                >
                    <span
                        class="mb-3 inline-block h-1 w-8 rounded-full"
                        :style="{ backgroundColor: service.color }"
                        aria-hidden="true"
                    />
                    <h3 class="text-sm font-semibold text-ink group-hover:text-brand">
                        {{ service.name }}
                    </h3>
                    <p class="mt-1.5 flex-1 text-xs leading-relaxed text-ink-muted">
                        {{ service.description }}
                    </p>

                    <div class="mt-4 flex items-center justify-between border-t border-line pt-3">
                        <span class="inline-flex items-center gap-1.5 text-xs text-ink-subtle">
                            <Clock class="size-3.5" />
                            {{ duration(service.duration_minutes) }}
                        </span>
                        <span class="tnum text-sm font-semibold text-ink">
                            {{ money(service.price_cents, currency) }}
                        </span>
                    </div>

                    <p v-if="service.staff.length" class="mt-2 text-[0.6875rem] text-ink-subtle">
                        with {{ service.staff.map((s) => s.name).join(', ') }}
                    </p>
                </Link>
            </div>
        </section>

        <!-- Team -->
        <section class="border-t border-line bg-surface">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                <h2 class="text-xl font-semibold tracking-tight text-ink">Who you will see</h2>

                <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="member in team" :key="member.id" class="flex gap-4">
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-semibold text-brand-strong"
                            aria-hidden="true"
                        >
                            {{ member.name.split(' ').slice(0, 2).map((p) => p[0]).join('') }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ member.name }}</p>
                            <p class="text-xs text-brand">{{ member.title }}</p>
                            <p class="mt-1.5 text-xs leading-relaxed text-ink-muted">{{ member.bio }}</p>
                            <p
                                v-if="member.timezone !== tenant?.timezone"
                                class="mt-1.5 inline-flex items-center gap-1 text-[0.6875rem] text-ink-subtle"
                            >
                                <Globe2 class="size-3" />
                                Works from {{ member.timezone.replace('_', ' ') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
