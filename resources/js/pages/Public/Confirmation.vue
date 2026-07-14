<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { CheckCircle2, CalendarDays, Clock, User, Hash, XCircle } from 'lucide-vue-next';
import PublicLayout from '@/layouts/PublicLayout.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import { money, duration, longDateIn, timeIn } from '@/lib/format';

const props = defineProps<{
    booking: {
        reference: string;
        status: string;
        status_label: string;
        service: string;
        staff: string;
        price_cents: number;
        timezone: string;
        starts_at: string;
        ends_at: string;
        local_starts_at: string;
        customer_name: string;
        customer_email: string;
        notes: string | null;
        can_cancel: boolean;
    };
}>();

const page = usePage();
const currency = computed(() => page.props.tenant?.currency ?? 'EUR');
const authed = computed(() => page.props.auth.user !== null);

const cancelling = ref(false);
const cancelError = ref<string | null>(null);

const minutes = computed(() =>
    Math.round(
        (new Date(props.booking.ends_at).getTime() - new Date(props.booking.starts_at).getTime()) / 60_000,
    ),
);

const tone = computed(() => {
    switch (props.booking.status) {
        case 'cancelled':
        case 'no_show':
            return 'critical' as const;
        case 'completed':
            return 'positive' as const;
        default:
            return 'brand' as const;
    }
});

/**
 * Only a signed-in customer can cancel from here. A guest holding the link
 * gets the details but not the ability to act — a shareable URL should not
 * also be a delete button.
 */
async function cancel(): Promise<void> {
    cancelling.value = true;
    cancelError.value = null;

    try {
        await api.patch(apiUrl(`/bookings/${props.booking.reference}/cancel`));
        router.reload();
    } catch (error) {
        cancelError.value =
            error instanceof ApiRequestError
                ? error.error.message
                : 'Could not cancel. Please call the studio.';
    } finally {
        cancelling.value = false;
    }
}
</script>

<template>
    <Head :title="`Booking ${booking.reference}`" />

    <PublicLayout>
        <div class="mx-auto max-w-xl px-4 py-12 sm:px-6 sm:py-20">
            <div class="text-center">
                <span
                    class="mx-auto flex size-12 items-center justify-center rounded-full"
                    :class="booking.status === 'cancelled' ? 'bg-critical-soft' : 'bg-positive-soft'"
                >
                    <XCircle v-if="booking.status === 'cancelled'" class="size-6 text-critical" />
                    <CheckCircle2 v-else class="size-6 text-positive" />
                </span>

                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink">
                    {{ booking.status === 'cancelled' ? 'Booking cancelled' : 'You are booked in' }}
                </h1>
                <p class="mt-1.5 text-sm text-ink-muted">
                    {{
                        booking.status === 'cancelled'
                            ? 'This appointment is no longer in the diary.'
                            : 'Keep this page — it is the only confirmation you need.'
                    }}
                </p>
            </div>

            <div class="panel mt-8 divide-y divide-line">
                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="inline-flex items-center gap-2 text-xs text-ink-muted">
                        <Hash class="size-3.5" />
                        Reference
                    </span>
                    <span class="tnum font-mono text-sm font-semibold text-ink">
                        {{ booking.reference }}
                    </span>
                </div>

                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="inline-flex items-center gap-2 text-xs text-ink-muted">
                        <CalendarDays class="size-3.5" />
                        When
                    </span>
                    <span class="text-right text-sm text-ink">
                        {{ longDateIn(booking.starts_at, booking.timezone) }}<br />
                        <span class="tnum font-medium">
                            {{ timeIn(booking.starts_at, booking.timezone) }}–{{ timeIn(booking.ends_at, booking.timezone) }}
                        </span>
                        <span class="block text-[0.6875rem] text-ink-subtle">{{ booking.timezone }}</span>
                    </span>
                </div>

                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="inline-flex items-center gap-2 text-xs text-ink-muted">
                        <Clock class="size-3.5" />
                        What
                    </span>
                    <span class="text-right text-sm text-ink">
                        {{ booking.service }}
                        <span class="block text-[0.6875rem] text-ink-subtle">
                            {{ duration(minutes) }} · {{ money(booking.price_cents, currency) }}
                        </span>
                    </span>
                </div>

                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="inline-flex items-center gap-2 text-xs text-ink-muted">
                        <User class="size-3.5" />
                        With
                    </span>
                    <span class="text-sm text-ink">{{ booking.staff }}</span>
                </div>

                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="text-xs text-ink-muted">Booked for</span>
                    <span class="text-right text-sm text-ink">
                        {{ booking.customer_name }}
                        <span class="block text-[0.6875rem] text-ink-subtle">{{ booking.customer_email }}</span>
                    </span>
                </div>

                <div v-if="booking.notes" class="px-5 py-3.5">
                    <p class="text-xs text-ink-muted">Your note</p>
                    <p class="mt-1 text-sm text-ink">{{ booking.notes }}</p>
                </div>

                <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                    <span class="text-xs text-ink-muted">Status</span>
                    <AppBadge :tone="tone" size="sm">{{ booking.status_label }}</AppBadge>
                </div>
            </div>

            <p v-if="cancelError" class="mt-3 text-center text-xs text-critical">{{ cancelError }}</p>

            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <AppButton href="/book" variant="secondary">Book another</AppButton>
                <AppButton
                    v-if="booking.can_cancel && authed"
                    variant="ghost"
                    :loading="cancelling"
                    @click="cancel"
                >
                    Cancel this booking
                </AppButton>
            </div>

            <p v-if="booking.can_cancel && !authed" class="mt-4 text-center text-xs text-ink-subtle">
                Need to change it? Call the studio and quote {{ booking.reference }}.
            </p>
        </div>
    </PublicLayout>
</template>
