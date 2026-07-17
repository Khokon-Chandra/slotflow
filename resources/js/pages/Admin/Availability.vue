<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Globe2, AlertCircle } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppField from '@/components/ui/AppField.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';

interface Rule {
    id?: number;
    weekday: number;
    starts_at: string;
    ends_at: string;
}

const props = defineProps<{
    staff: { id: number; name: string; title: string | null; timezone: string };
    rules: Rule[];
    timeOff: { id: number; starts_at: string; ends_at: string; reason: string | null; is_past: boolean }[];
}>();

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const rules = ref<Rule[]>(props.rules.map((r) => ({ ...r })));
const saving = ref(false);
const saveError = ref<string | null>(null);
const saved = ref(false);

const addRule = (weekday: number): void => {
    rules.value.push({ weekday, starts_at: '09:00', ends_at: '17:00' });
};

const removeRule = (rule: Rule): void => {
    rules.value = rules.value.filter((r) => r !== rule);
};

const rulesFor = (weekday: number): Rule[] => rules.value.filter((r) => r.weekday === weekday);

/**
 * The whole week is sent at once and replaces what was there. A merge would
 * need per-row create/update/delete and a way to describe "this row is gone";
 * a replace is one request, and sending it twice changes nothing.
 */
async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;
    saved.value = false;

    try {
        await api.put(apiUrl(`/staff/${props.staff.id}/availability-rules`), {
            rules: rules.value.map((r) => ({
                weekday: r.weekday,
                starts_at: r.starts_at,
                ends_at: r.ends_at,
            })),
        });

        saved.value = true;
        router.reload({ only: ['rules'] });
    } catch (error) {
        saveError.value =
            error instanceof ApiRequestError ? error.error.message : 'Could not save those hours.';
    } finally {
        saving.value = false;
    }
}

/* Time off ---------------------------------------------------------------- */

const timeOffForm = ref({ starts_at: '', ends_at: '', reason: '' });
const addingTimeOff = ref(false);
const timeOffError = ref<string | null>(null);
const conflicts = ref<string[]>([]);

async function addTimeOff(): Promise<void> {
    addingTimeOff.value = true;
    timeOffError.value = null;
    conflicts.value = [];

    try {
        const response = await api.post<{ meta: { conflicting_bookings: string[] } }>(
            apiUrl(`/staff/${props.staff.id}/time-off`),
            timeOffForm.value,
        );

        // Existing bookings inside the window are reported, not cancelled.
        // Silently dropping appointments someone has already been promised is
        // not a decision this screen gets to make.
        conflicts.value = response.meta.conflicting_bookings;
        timeOffForm.value = { starts_at: '', ends_at: '', reason: '' };
        router.reload({ only: ['timeOff'] });
    } catch (error) {
        timeOffError.value =
            error instanceof ApiRequestError ? error.error.message : 'Could not add that.';
    } finally {
        addingTimeOff.value = false;
    }
}

async function removeTimeOff(id: number): Promise<void> {
    await api.delete(apiUrl(`/staff/${props.staff.id}/time-off/${id}`));
    router.reload({ only: ['timeOff'] });
}

const formatRange = (start: string, end: string): string => {
    const options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: props.staff.timezone,
    };

    return `${new Intl.DateTimeFormat(undefined, options).format(new Date(start))} → ${new Intl.DateTimeFormat(undefined, options).format(new Date(end))}`;
};
</script>

<template>
    <Head :title="`${staff.name} · hours`" />

    <AdminLayout :title="`${staff.name}'s hours`" :subtitle="staff.title ?? undefined">
        <div class="space-y-6">
            <Link
                href="/admin/team"
                class="inline-flex items-center gap-1.5 text-xs text-ink-muted transition hover:text-ink"
            >
                <ArrowLeft class="size-3.5" />
                Back to team
            </Link>

            <!-- Timezone note. Not decoration: these times mean nothing without it. -->
            <div class="flex items-start gap-2.5 rounded-lg border border-brand/25 bg-brand-soft px-4 py-3">
                <Globe2 class="mt-0.5 size-4 shrink-0 text-brand" />
                <p class="text-xs leading-relaxed text-brand-strong">
                    These are wall-clock times in <strong>{{ staff.timezone }}</strong> — the clock
                    {{ staff.name.split(' ')[0] }} actually works to. Customers see them converted into
                    their own zone, and the conversion follows daylight saving on both sides.
                </p>
            </div>

            <AppCard title="Weekly pattern" subtitle="Repeats every week until changed">
                <template #actions>
                    <AppButton size="sm" :loading="saving" @click="save">Save hours</AppButton>
                </template>

                <div class="space-y-4">
                    <div
                        v-for="(dayName, weekday) in WEEKDAYS"
                        :key="weekday"
                        class="flex flex-wrap items-start gap-3 border-b border-line pb-4 last:border-0 last:pb-0"
                    >
                        <span class="w-24 shrink-0 pt-1.5 text-xs font-medium text-ink">{{ dayName }}</span>

                        <div class="flex-1 space-y-2">
                            <div
                                v-for="(rule, index) in rulesFor(weekday)"
                                :key="index"
                                class="flex items-center gap-2"
                            >
                                <input
                                    v-model="rule.starts_at"
                                    type="time"
                                    class="field tnum w-28"
                                    :aria-label="`${dayName} start`"
                                />
                                <span class="text-xs text-ink-subtle">to</span>
                                <input
                                    v-model="rule.ends_at"
                                    type="time"
                                    class="field tnum w-28"
                                    :aria-label="`${dayName} end`"
                                />
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-ink-subtle transition hover:bg-critical-soft hover:text-critical"
                                    :aria-label="`Remove this ${dayName} shift`"
                                    @click="removeRule(rule)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>

                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-ink-subtle transition hover:text-brand"
                                @click="addRule(weekday)"
                            >
                                <Plus class="size-3.5" />
                                {{ rulesFor(weekday).length ? 'Add another shift' : 'Add hours' }}
                            </button>
                        </div>
                    </div>

                    <p class="text-[0.6875rem] text-ink-subtle">
                        Two shifts on one day is how you model a lunch break. An end time earlier than the
                        start means an overnight shift, and is handled.
                    </p>

                    <p v-if="saveError" class="text-xs text-critical">{{ saveError }}</p>
                    <p v-else-if="saved" class="text-xs text-positive">Hours saved.</p>
                </div>
            </AppCard>

            <AppCard title="Time off" subtitle="Holidays, appointments, anything that blocks the diary">
                <div class="space-y-4">
                    <ul v-if="timeOff.length" class="divide-y divide-line">
                        <li
                            v-for="entry in timeOff"
                            :key="entry.id"
                            class="flex items-center justify-between gap-3 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="tnum text-xs text-ink">{{ formatRange(entry.starts_at, entry.ends_at) }}</p>
                                <p class="text-[0.6875rem] text-ink-subtle">{{ entry.reason ?? 'No reason given' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <AppBadge v-if="entry.is_past" tone="neutral" size="sm">Past</AppBadge>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-ink-subtle transition hover:bg-critical-soft hover:text-critical"
                                    aria-label="Delete"
                                    @click="removeTimeOff(entry.id)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </li>
                    </ul>

                    <form class="grid gap-3 sm:grid-cols-[1fr_1fr_1.2fr_auto]" @submit.prevent="addTimeOff">
                        <AppField label="From" for="to-start">
                            <input id="to-start" v-model="timeOffForm.starts_at" type="datetime-local" class="field" required />
                        </AppField>
                        <AppField label="Until" for="to-end">
                            <input id="to-end" v-model="timeOffForm.ends_at" type="datetime-local" class="field" required />
                        </AppField>
                        <AppField label="Reason" for="to-reason">
                            <input id="to-reason" v-model="timeOffForm.reason" type="text" class="field" placeholder="Holiday" />
                        </AppField>
                        <div class="flex items-end">
                            <AppButton type="submit" variant="secondary" :loading="addingTimeOff">Add</AppButton>
                        </div>
                    </form>

                    <p v-if="timeOffError" class="text-xs text-critical">{{ timeOffError }}</p>

                    <div
                        v-if="conflicts.length"
                        class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-2.5"
                    >
                        <AlertCircle class="mt-0.5 size-4 shrink-0 text-warning" />
                        <p class="text-xs text-warning">
                            {{ conflicts.length }} existing booking{{ conflicts.length === 1 ? '' : 's' }} fall
                            inside that window and {{ conflicts.length === 1 ? 'was' : 'were' }} left alone:
                            <span class="font-mono">{{ conflicts.join(', ') }}</span>. Move or cancel
                            {{ conflicts.length === 1 ? 'it' : 'them' }} yourself.
                        </p>
                    </div>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
