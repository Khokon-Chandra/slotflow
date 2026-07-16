<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Search, CalendarDays, ChevronLeft, ChevronRight, Check, X, UserX } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppEmpty from '@/components/ui/AppEmpty.vue';
import RiskBadge from '@/components/RiskBadge.vue';
import RiskDetail from '@/components/RiskDetail.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import { money, duration, timeIn, dateIn } from '@/lib/format';
import type { BookingRow, BookingStatus } from '@/types';

const props = defineProps<{
    bookings: {
        data: BookingRow[];
        meta: { current_page: number; last_page: number; total: number; per_page: number };
    };
    filters: Record<string, string | number | null>;
    staffOptions: { value: number; label: string }[];
    statusOptions: { value: string; label: string }[];
}>();

const page = usePage();
const timezone = computed(() => page.props.tenant?.timezone ?? 'UTC');
const currency = computed(() => page.props.tenant?.currency ?? 'EUR');

const filters = ref({ ...props.filters });
const inspecting = ref<BookingRow | null>(null);
const acting = ref<string | null>(null);
const actionError = ref<string | null>(null);

let debounce: ReturnType<typeof setTimeout> | undefined;

/**
 * Debounced so typing a customer name does not fire a request per keystroke —
 * the difference between one query and fourteen on a table this size.
 */
watch(
    filters,
    (value) => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            router.get('/admin/bookings', { ...value }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);
    },
    { deep: true },
);

const statusTone = (status: BookingStatus) =>
    ({
        confirmed: 'brand',
        pending: 'warning',
        completed: 'positive',
        cancelled: 'neutral',
        no_show: 'critical',
    })[status] as 'brand' | 'warning' | 'positive' | 'neutral' | 'critical';

async function transition(booking: BookingRow, status: BookingStatus): Promise<void> {
    acting.value = booking.reference;
    actionError.value = null;

    try {
        await api.patch(apiUrl(`/admin/bookings/${booking.reference}/status`), { status });
        router.reload({ only: ['bookings'] });
    } catch (error) {
        actionError.value =
            error instanceof ApiRequestError ? error.error.message : 'That change could not be applied.';
    } finally {
        acting.value = null;
    }
}

const goToPage = (n: number): void => {
    router.get('/admin/bookings', { ...filters.value, page: n }, { preserveState: true, preserveScroll: true });
};

const clearFilters = (): void => {
    filters.value = { status: null, staff_id: null, risk: null, search: null, from: null, to: null };
};

const hasFilters = computed(() => Object.values(filters.value).some((v) => v !== null && v !== ''));
</script>

<template>
    <Head title="Diary" />

    <AdminLayout title="Diary" :subtitle="`${bookings.meta.total} bookings · times in ${timezone}`">
        <div class="space-y-4">
            <!-- Filters -->
            <AppCard :padded="false">
                <div class="flex flex-wrap items-end gap-3 p-4">
                    <div class="relative min-w-52 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-ink-subtle" />
                        <input
                            v-model="filters.search"
                            type="search"
                            class="field pl-9"
                            placeholder="Reference, name or email"
                            aria-label="Search bookings"
                        />
                    </div>

                    <select v-model="filters.status" class="field w-auto" aria-label="Status">
                        <option :value="null">Any status</option>
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>

                    <select v-model="filters.staff_id" class="field w-auto" aria-label="Team member">
                        <option :value="null">Anyone</option>
                        <option v-for="option in staffOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>

                    <select v-model="filters.risk" class="field w-auto" aria-label="Risk band">
                        <option :value="null">Any risk</option>
                        <option value="high">High risk</option>
                        <option value="medium">Watch</option>
                        <option value="low">Low risk</option>
                    </select>

                    <input v-model="filters.from" type="date" class="field w-auto" aria-label="From date" />
                    <input v-model="filters.to" type="date" class="field w-auto" aria-label="To date" />

                    <AppButton v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">
                        Clear
                    </AppButton>
                </div>
            </AppCard>

            <p v-if="actionError" class="rounded-lg border border-critical/30 bg-critical-soft px-4 py-2.5 text-xs text-critical">
                {{ actionError }}
            </p>

            <!-- Table -->
            <AppCard :padded="false">
                <AppEmpty
                    v-if="bookings.data.length === 0"
                    title="No bookings match"
                    description="Try widening the filters."
                >
                    <template #icon><CalendarDays class="size-5" /></template>
                    <template v-if="hasFilters" #action>
                        <AppButton variant="secondary" size="sm" @click="clearFilters">Clear filters</AppButton>
                    </template>
                </AppEmpty>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs text-ink-subtle">
                                <th scope="col" class="px-4 py-2.5 font-medium">When</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Customer</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Service</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">With</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Risk</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Value</th>
                                <th scope="col" class="px-4 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="booking in bookings.data"
                                :key="booking.reference"
                                class="transition hover:bg-surface-sunken"
                            >
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="block text-ink">{{ dateIn(booking.starts_at, timezone) }}</span>
                                    <span class="tnum block text-xs text-ink-subtle">
                                        {{ timeIn(booking.starts_at, timezone) }} ·
                                        {{ duration(booking.duration_minutes) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="block text-ink">{{ booking.customer.name }}</span>
                                    <span class="block font-mono text-[0.6875rem] text-ink-subtle">
                                        {{ booking.reference }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-2">
                                        <span
                                            class="size-2 rounded-full"
                                            :style="{ backgroundColor: booking.service.color }"
                                            aria-hidden="true"
                                        />
                                        <span class="text-ink">{{ booking.service.name }}</span>
                                    </span>
                                    <span class="mt-0.5 block text-[0.6875rem] text-ink-subtle">
                                        via {{ booking.source_label }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-ink-muted">{{ booking.staff.name }}</td>

                                <td class="px-4 py-3">
                                    <AppBadge :tone="statusTone(booking.status)" size="sm">
                                        {{ booking.status_label }}
                                    </AppBadge>
                                </td>

                                <td class="px-4 py-3">
                                    <button type="button" @click="inspecting = booking">
                                        <RiskBadge :risk="booking.risk" show-score />
                                    </button>
                                </td>

                                <td class="tnum px-4 py-3 text-right text-ink">
                                    {{ money(booking.price_cents, currency) }}
                                </td>

                                <td class="px-4 py-3">
                                    <div
                                        v-if="booking.status === 'confirmed' || booking.status === 'pending'"
                                        class="flex justify-end gap-1"
                                    >
                                        <button
                                            type="button"
                                            class="rounded-md p-1.5 text-ink-subtle transition hover:bg-positive-soft hover:text-positive"
                                            title="Mark as completed"
                                            :disabled="acting === booking.reference"
                                            @click="transition(booking, 'completed')"
                                        >
                                            <Check class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md p-1.5 text-ink-subtle transition hover:bg-critical-soft hover:text-critical"
                                            title="Mark as no-show"
                                            :disabled="acting === booking.reference"
                                            @click="transition(booking, 'no_show')"
                                        >
                                            <UserX class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md p-1.5 text-ink-subtle transition hover:bg-surface-sunken hover:text-ink"
                                            title="Cancel"
                                            :disabled="acting === booking.reference"
                                            @click="transition(booking, 'cancelled')"
                                        >
                                            <X class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="bookings.meta.last_page > 1"
                    class="flex items-center justify-between border-t border-line px-4 py-3 text-xs"
                >
                    <span class="text-ink-subtle">
                        Page {{ bookings.meta.current_page }} of {{ bookings.meta.last_page }}
                    </span>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            class="rounded-md p-1.5 text-ink-muted transition hover:bg-surface-sunken disabled:opacity-40"
                            :disabled="bookings.meta.current_page === 1"
                            aria-label="Previous page"
                            @click="goToPage(bookings.meta.current_page - 1)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5 text-ink-muted transition hover:bg-surface-sunken disabled:opacity-40"
                            :disabled="bookings.meta.current_page === bookings.meta.last_page"
                            aria-label="Next page"
                            @click="goToPage(bookings.meta.current_page + 1)"
                        >
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </div>
            </AppCard>
        </div>

        <AppModal
            :open="inspecting !== null"
            :title="inspecting ? `Risk · ${inspecting.customer.name}` : ''"
            @close="inspecting = null"
        >
            <div v-if="inspecting" class="space-y-5">
                <dl class="grid grid-cols-2 gap-3 rounded-lg bg-surface-sunken p-3 text-xs">
                    <div>
                        <dt class="text-ink-subtle">Attended</dt>
                        <dd class="tnum mt-0.5 font-medium text-ink">{{ inspecting.customer.completed_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-subtle">Missed</dt>
                        <dd class="tnum mt-0.5 font-medium text-ink">{{ inspecting.customer.no_show_count }}</dd>
                    </div>
                </dl>

                <RiskDetail v-if="inspecting.risk" :risk="inspecting.risk" />
                <p v-else class="text-sm text-ink-muted">No assessment yet for this booking.</p>
            </div>
        </AppModal>
    </AdminLayout>
</template>
