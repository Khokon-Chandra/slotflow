<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Globe2, Clock, CalendarRange, Grid3x3, Ban } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';

defineProps<{
    tenant: {
        name: string;
        slug: string;
        timezone: string;
        currency: string;
        contact_email: string | null;
        phone: string | null;
        description: string | null;
    };
    booking: {
        min_notice_minutes: number;
        max_advance_days: number;
        slot_granularity_minutes: number;
        cancellation_window_hours: number;
    };
}>();
</script>

<template>
    <Head title="Settings" />

    <AdminLayout title="Settings" subtitle="Workspace and booking rules">
        <div class="grid gap-6 lg:grid-cols-2">
            <AppCard title="Workspace">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs text-ink-subtle">Name</dt>
                        <dd class="text-ink">{{ tenant.name }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs text-ink-subtle">Identifier</dt>
                        <dd class="font-mono text-xs text-ink">{{ tenant.slug }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="inline-flex items-center gap-1.5 text-xs text-ink-subtle">
                            <Globe2 class="size-3" /> Timezone
                        </dt>
                        <dd class="text-ink">{{ tenant.timezone }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs text-ink-subtle">Currency</dt>
                        <dd class="text-ink">{{ tenant.currency }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-xs text-ink-subtle">Contact</dt>
                        <dd class="text-right text-ink">
                            {{ tenant.contact_email }}
                            <span class="block text-xs text-ink-subtle">{{ tenant.phone }}</span>
                        </dd>
                    </div>
                </dl>

                <p class="mt-4 border-t border-line pt-3 text-[0.6875rem] text-ink-subtle">
                    Read-only in this demo. Editing these is a form over the same fields — the
                    interesting part is below.
                </p>
            </AppCard>

            <AppCard title="Booking rules" subtitle="Enforced by the availability engine and the booking guard">
                <ul class="space-y-4 text-sm">
                    <li class="flex items-start gap-3">
                        <Clock class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        <div>
                            <p class="text-ink">
                                Minimum notice ·
                                <span class="tnum font-medium">{{ booking.min_notice_minutes }} min</span>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                Stops someone booking the 09:00 slot at 08:59.
                            </p>
                        </div>
                    </li>

                    <li class="flex items-start gap-3">
                        <CalendarRange class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        <div>
                            <p class="text-ink">
                                Booking horizon ·
                                <span class="tnum font-medium">{{ booking.max_advance_days }} days</span>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                How far ahead the diary is open. Longer horizons raise no-show risk.
                            </p>
                        </div>
                    </li>

                    <li class="flex items-start gap-3">
                        <Grid3x3 class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        <div>
                            <p class="text-ink">
                                Slot grid ·
                                <span class="tnum font-medium">{{ booking.slot_granularity_minutes }} min</span>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                Start times land on this grid, so a 45-minute service offers 09:00, 09:15,
                                09:30 rather than 09:00, 09:45.
                            </p>
                        </div>
                    </li>

                    <li class="flex items-start gap-3">
                        <Ban class="mt-0.5 size-4 shrink-0 text-ink-subtle" />
                        <div>
                            <p class="text-ink">
                                Self-cancellation window ·
                                <span class="tnum font-medium">{{ booking.cancellation_window_hours }} h</span>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                Inside this window a customer has to call. That is a business rule, not a
                                technical limit.
                            </p>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
