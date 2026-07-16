<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Deferred, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    TrendingDown,
    Wallet,
    AlertTriangle,
    Sparkles,
    Clock,
    Phone,
    Mail,
} from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppEmpty from '@/components/ui/AppEmpty.vue';
import StatTile from '@/components/StatTile.vue';
import RiskBadge from '@/components/RiskBadge.vue';
import RiskDetail from '@/components/RiskDetail.vue';
import AiProvenanceTag from '@/components/AiProvenanceTag.vue';
import { money, duration, timeIn, percent } from '@/lib/format';
import type { BookingRow, Briefing, DayStats } from '@/types';

defineProps<{
    stats: DayStats;
    metrics: {
        bookings_30d: number;
        completed_30d: number;
        no_shows_30d: number;
        no_show_rate: number;
        revenue_30d_cents: number;
        lost_to_no_shows_cents: number;
        upcoming: number;
    };
    todaysBookings: BookingRow[];
    atRisk: BookingRow[];
    briefing?: Briefing;
}>();

const page = usePage();
const timezone = computed(() => page.props.tenant?.timezone ?? 'UTC');
const currency = computed(() => page.props.tenant?.currency ?? 'EUR');

const inspecting = ref<BookingRow | null>(null);

const toneClass = (tone: string): string =>
    ({
        warning: 'text-warning',
        positive: 'text-positive',
        neutral: 'text-ink-muted',
    })[tone] ?? 'text-ink-muted';
</script>

<template>
    <Head title="Overview" />

    <AdminLayout title="Overview" :subtitle="`Today, ${stats.date} · ${timezone}`">
        <div class="space-y-6">
            <!-- Briefing -->
            <AppCard>
                <template #actions>
                    <AppBadge tone="brand" size="sm">
                        <Sparkles class="size-3" />
                        Daily briefing
                    </AppBadge>
                </template>

                <!-- Deferred on the server: the dashboard paints immediately
                     and this arrives on a second round trip, so a slow or
                     unreachable model never holds up the page. -->
                <Deferred data="briefing">
                    <template #fallback>
                        <div class="space-y-2.5">
                            <div class="h-5 w-2/3 animate-pulse rounded bg-surface-sunken" />
                            <div class="h-3 w-full animate-pulse rounded bg-surface-sunken" />
                            <div class="h-3 w-4/5 animate-pulse rounded bg-surface-sunken" />
                        </div>
                    </template>

                    <div v-if="briefing" class="space-y-3">
                        <p class="text-base font-medium tracking-tight text-ink">
                            {{ briefing.headline }}
                        </p>

                        <ul class="space-y-1.5">
                            <li
                                v-for="(bullet, index) in briefing.bullets"
                                :key="index"
                                class="flex items-start gap-2 text-sm"
                                :class="toneClass(bullet.tone)"
                            >
                                <span class="mt-1.5 size-1 shrink-0 rounded-full bg-current" aria-hidden="true" />
                                {{ bullet.text }}
                            </li>
                        </ul>

                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                            <p class="text-sm font-medium text-ink">→ {{ briefing.focus }}</p>
                            <AiProvenanceTag :ai="briefing.ai" />
                        </div>
                    </div>
                </Deferred>
            </AppCard>

            <!-- Headline numbers -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Booked today"
                    :value="String(stats.booking_count)"
                    :caption="`${percent(stats.utilisation_percent)} of rostered hours`"
                >
                    <template #icon><CalendarDays class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="Expected today"
                    :value="money(stats.revenue_cents, currency)"
                    :caption="stats.first_start ? `${stats.first_start}–${stats.last_end}` : 'Nothing booked'"
                >
                    <template #icon><Wallet class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="No-show rate"
                    :value="`${metrics.no_show_rate}%`"
                    :caption="`${metrics.no_shows_30d} of ${metrics.completed_30d + metrics.no_shows_30d} resolved, 30 days`"
                    :tone="metrics.no_show_rate > 10 ? 'critical' : 'neutral'"
                >
                    <template #icon><TrendingDown class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="Lost to no-shows"
                    :value="money(metrics.lost_to_no_shows_cents, currency)"
                    caption="Last 30 days"
                    :tone="metrics.lost_to_no_shows_cents > 0 ? 'warning' : 'neutral'"
                >
                    <template #icon><AlertTriangle class="size-4 text-ink-subtle" /></template>
                </StatTile>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <!-- Today -->
                <AppCard title="Today's diary" :subtitle="`${stats.booking_count} appointments`" :padded="false">
                    <AppEmpty
                        v-if="todaysBookings.length === 0"
                        title="Nothing booked today"
                        description="A good day to call the customers you have not seen in a while."
                    >
                        <template #icon><CalendarDays class="size-5" /></template>
                    </AppEmpty>

                    <ul v-else class="divide-y divide-line">
                        <li
                            v-for="booking in todaysBookings"
                            :key="booking.reference"
                            class="flex items-center gap-3 px-5 py-3 transition hover:bg-surface-sunken"
                        >
                            <span
                                class="h-9 w-1 shrink-0 rounded-full"
                                :style="{ backgroundColor: booking.service.color }"
                                aria-hidden="true"
                            />

                            <span class="tnum w-12 shrink-0 text-sm font-medium text-ink">
                                {{ timeIn(booking.starts_at, timezone) }}
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-ink">
                                    {{ booking.customer.name }}
                                </span>
                                <span class="block truncate text-xs text-ink-subtle">
                                    {{ booking.service.name }} · {{ booking.staff.name }} ·
                                    {{ duration(booking.duration_minutes) }}
                                </span>
                            </span>

                            <button type="button" @click="inspecting = booking">
                                <RiskBadge :risk="booking.risk" />
                            </button>
                        </li>
                    </ul>
                </AppCard>

                <!-- At risk -->
                <AppCard
                    title="Worth a phone call"
                    subtitle="Upcoming bookings scored high risk"
                    :padded="false"
                >
                    <AppEmpty
                        v-if="atRisk.length === 0"
                        title="Nothing flagged"
                        description="No upcoming booking is scoring high right now."
                    >
                        <template #icon><Sparkles class="size-5" /></template>
                    </AppEmpty>

                    <ul v-else class="divide-y divide-line">
                        <li v-for="booking in atRisk" :key="booking.reference" class="px-5 py-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-ink">
                                        {{ booking.customer.name }}
                                    </p>
                                    <p class="mt-0.5 flex items-center gap-1.5 text-xs text-ink-subtle">
                                        <Clock class="size-3" />
                                        {{ timeIn(booking.starts_at, timezone) }} ·
                                        {{ booking.service.name }}
                                    </p>
                                </div>
                                <button type="button" @click="inspecting = booking">
                                    <RiskBadge :risk="booking.risk" show-score />
                                </button>
                            </div>

                            <p v-if="booking.risk?.recommended_action" class="mt-2 text-xs text-ink-muted">
                                {{ booking.risk.recommended_action }}
                            </p>

                            <div class="mt-2 flex flex-wrap gap-3 text-[0.6875rem] text-ink-subtle">
                                <a
                                    v-if="booking.customer.phone"
                                    :href="`tel:${booking.customer.phone}`"
                                    class="inline-flex items-center gap-1 hover:text-brand"
                                >
                                    <Phone class="size-3" />
                                    {{ booking.customer.phone }}
                                </a>
                                <a
                                    :href="`mailto:${booking.customer.email}`"
                                    class="inline-flex items-center gap-1 hover:text-brand"
                                >
                                    <Mail class="size-3" />
                                    {{ booking.customer.email }}
                                </a>
                            </div>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <!-- Per staff -->
            <AppCard v-if="stats.per_staff.length" title="Today by team member">
                <ul class="space-y-3">
                    <li v-for="row in stats.per_staff" :key="row.name" class="space-y-1.5">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-ink">{{ row.name }}</span>
                            <span class="tnum text-ink-subtle">
                                {{ row.bookings }} booking{{ row.bookings === 1 ? '' : 's' }} ·
                                {{ duration(row.booked_minutes) }}
                            </span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-sunken">
                            <div
                                class="h-full rounded-full bg-brand"
                                :style="{
                                    width: `${Math.min(100, (row.booked_minutes / Math.max(1, stats.per_staff[0].booked_minutes)) * 100)}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <AppModal
            :open="inspecting !== null"
            :title="inspecting ? `Risk · ${inspecting.customer.name}` : ''"
            @close="inspecting = null"
        >
            <RiskDetail v-if="inspecting?.risk" :risk="inspecting.risk" />
            <p v-else class="text-sm text-ink-muted">No assessment has been generated for this booking yet.</p>
        </AppModal>
    </AdminLayout>
</template>
