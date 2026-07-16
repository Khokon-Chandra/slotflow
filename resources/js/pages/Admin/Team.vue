<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock, CalendarOff, Globe2, Settings2 } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';

defineProps<{
    team: {
        id: number;
        name: string;
        title: string | null;
        bio: string | null;
        timezone: string;
        is_active: boolean;
        service_ids: number[];
        weekly_hours: number;
        upcoming_time_off: number;
    }[];
    serviceOptions: { value: number; label: string }[];
}>();
</script>

<template>
    <Head title="Team" />

    <AdminLayout title="Team" :subtitle="`${team.length} people`">
        <div class="grid gap-4 md:grid-cols-2">
            <AppCard v-for="member in team" :key="member.id">
                <div class="flex items-start gap-4">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-soft text-sm font-semibold text-brand-strong"
                        aria-hidden="true"
                    >
                        {{ member.name.split(' ').slice(0, 2).map((p) => p[0]).join('') }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-ink">{{ member.name }}</h3>
                            <AppBadge v-if="!member.is_active" tone="neutral" size="sm">Not bookable</AppBadge>
                        </div>
                        <p class="text-xs text-brand">{{ member.title }}</p>
                        <p v-if="member.bio" class="mt-1.5 line-clamp-2 text-xs leading-relaxed text-ink-muted">
                            {{ member.bio }}
                        </p>

                        <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[0.6875rem] text-ink-subtle">
                            <div class="inline-flex items-center gap-1.5">
                                <Clock class="size-3" />
                                <dd class="tnum">{{ member.weekly_hours }} h / week</dd>
                            </div>
                            <div v-if="member.upcoming_time_off" class="inline-flex items-center gap-1.5">
                                <CalendarOff class="size-3" />
                                <dd>{{ member.upcoming_time_off }} time off booked</dd>
                            </div>
                            <div class="inline-flex items-center gap-1.5">
                                <Globe2 class="size-3" />
                                <dd>{{ member.timezone }}</dd>
                            </div>
                        </dl>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <AppBadge
                                v-for="id in member.service_ids"
                                :key="id"
                                tone="neutral"
                                size="sm"
                            >
                                {{ serviceOptions.find((s) => s.value === id)?.label }}
                            </AppBadge>
                        </div>
                    </div>

                    <Link
                        :href="`/admin/team/${member.id}/hours`"
                        class="shrink-0 rounded-md p-1.5 text-ink-subtle transition hover:bg-surface-sunken hover:text-ink"
                        :aria-label="`Edit ${member.name}'s hours`"
                    >
                        <Settings2 class="size-4" />
                    </Link>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
