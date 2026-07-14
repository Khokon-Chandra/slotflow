<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import {
    Sparkles,
    Search,
    ArrowLeft,
    CalendarDays,
    Clock,
    User,
    AlertCircle,
    CheckCircle2,
} from 'lucide-vue-next';
import PublicLayout from '@/layouts/PublicLayout.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppField from '@/components/ui/AppField.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AiProvenanceTag from '@/components/AiProvenanceTag.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import { money, duration, browserTimezone, dateIn, relativeDay } from '@/lib/format';
import type { AiProvenance, ServiceSummary, Slot } from '@/types';

const props = defineProps<{
    services: ServiceSummary[];
    preselected: string | null;
}>();

const page = usePage();
const tenantSlug = computed(() => page.props.tenant?.slug ?? '');
const currency = computed(() => page.props.tenant?.currency ?? 'EUR');
const authUser = computed(() => page.props.auth.user);

/* -----------------------------------------------------------------------------
 * State
 * ---------------------------------------------------------------------------*/

const timezone = ref(browserTimezone());
const step = ref<'ask' | 'choose' | 'details'>('ask');

const query = ref('');
const asking = ref(false);
const askError = ref<string | null>(null);

const selectedService = ref<ServiceSummary | null>(null);
const slots = ref<Slot[]>([]);
const chosenSlot = ref<Slot | null>(null);
const assistantMessage = ref<string | null>(null);
const assistantProvenance = ref<AiProvenance | null>(null);
const relaxed = ref(false);

const form = ref({
    customer_name: authUser.value?.name ?? '',
    customer_email: authUser.value?.email ?? '',
    customer_phone: '',
    notes: '',
});
const submitting = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const submitError = ref<string | null>(null);

const examples = [
    'a haircut next Tuesday afternoon',
    'beard trim tomorrow morning',
    'my scalp is itchy, anything soon?',
    'colour with Maya sometime next week',
];

/* -----------------------------------------------------------------------------
 * The assistant
 * ---------------------------------------------------------------------------*/

interface AssistantResponse {
    data: {
        intent: Record<string, unknown>;
        service: { id: number; name: string; slug: string; duration_minutes: number; price_cents: number } | null;
        slots: Slot[];
        relaxed: boolean;
        message: string;
        ai: AiProvenance;
    };
}

async function ask(): Promise<void> {
    if (query.value.trim().length < 2) return;

    asking.value = true;
    askError.value = null;

    try {
        const { data } = await api.post<AssistantResponse>(
            apiUrl('/ai/booking-assistant', { tenant: tenantSlug.value }),
            { text: query.value, tz: timezone.value, limit: 8 },
        );

        assistantMessage.value = data.message;
        assistantProvenance.value = data.ai;
        relaxed.value = data.relaxed;
        slots.value = data.slots;

        selectedService.value = data.service
            ? props.services.find((s) => s.id === data.service!.id) ?? null
            : null;

        // Stay on this step when the assistant could not identify a service —
        // it asked a question, and the answer is another sentence, not a slot.
        if (selectedService.value) {
            step.value = 'choose';
        }
    } catch (error) {
        askError.value =
            error instanceof ApiRequestError
                ? error.error.message
                : 'Could not reach the booking assistant. Pick a service below instead.';
    } finally {
        asking.value = false;
    }
}

/* -----------------------------------------------------------------------------
 * Browsing a service directly (the path that needs no AI at all)
 * ---------------------------------------------------------------------------*/

const loadingSlots = ref(false);

async function browse(service: ServiceSummary): Promise<void> {
    selectedService.value = service;
    assistantMessage.value = null;
    assistantProvenance.value = null;
    relaxed.value = false;
    chosenSlot.value = null;
    step.value = 'choose';

    await loadSlots(service);
}

async function loadSlots(service: ServiceSummary): Promise<void> {
    loadingSlots.value = true;

    try {
        const today = new Date();
        const until = new Date(Date.now() + 20 * 86_400_000);
        const iso = (d: Date): string =>
            new Intl.DateTimeFormat('en-CA', { timeZone: timezone.value }).format(d);

        const { data } = await api.get<{
            data: { days: { date: string; slots: Slot[] }[] };
        }>(
            apiUrl('/availability', {
                tenant: tenantSlug.value,
                service_id: service.id,
                from: iso(today),
                until: iso(until),
                tz: timezone.value,
            }),
        );

        slots.value = data.days.flatMap((day) => day.slots);
    } catch {
        slots.value = [];
    } finally {
        loadingSlots.value = false;
    }
}

/* -----------------------------------------------------------------------------
 * Grouping for display
 * ---------------------------------------------------------------------------*/

const slotsByDate = computed(() => {
    const groups = new Map<string, Slot[]>();

    for (const slot of slots.value) {
        const existing = groups.get(slot.local_date);
        existing ? existing.push(slot) : groups.set(slot.local_date, [slot]);
    }

    return [...groups.entries()].map(([date, items]) => ({ date, slots: items }));
});

function choose(slot: Slot): void {
    chosenSlot.value = slot;
    step.value = 'details';
}

/* -----------------------------------------------------------------------------
 * Confirming
 * ---------------------------------------------------------------------------*/

async function confirm(): Promise<void> {
    if (!chosenSlot.value || !selectedService.value) return;

    submitting.value = true;
    fieldErrors.value = {};
    submitError.value = null;

    try {
        const { data } = await api.post<{ data: { reference: string } }>(
            apiUrl('/bookings', { tenant: tenantSlug.value }),
            {
                service_id: selectedService.value.id,
                staff_id: chosenSlot.value.staff_id,
                starts_at: chosenSlot.value.starts_at,
                customer_timezone: timezone.value,
                ...form.value,
            },
        );

        router.visit(`/booking/${data.reference}`);
    } catch (error) {
        if (error instanceof ApiRequestError) {
            fieldErrors.value = error.error.fields ?? {};
            submitError.value = error.error.message;

            // 409 means somebody else took it between the page load and the
            // click. Rather than a dead end, reload the diary and put the
            // customer back on the slot list with the bad option gone.
            if (error.error.code === 'slot_unavailable' && selectedService.value) {
                chosenSlot.value = null;
                step.value = 'choose';
                await loadSlots(selectedService.value);
            }
        } else {
            submitError.value = 'Something went wrong. Please try again.';
        }
    } finally {
        submitting.value = false;
    }
}

function fieldError(name: string): string | undefined {
    return fieldErrors.value[name]?.[0];
}

function back(): void {
    if (step.value === 'details') {
        step.value = 'choose';
        submitError.value = null;
    } else {
        step.value = 'ask';
        slots.value = [];
        selectedService.value = null;
    }
}

/* -----------------------------------------------------------------------------
 * Deep link: /book?service=cut-finish
 * ---------------------------------------------------------------------------*/

onMounted(() => {
    if (!props.preselected) return;

    const service = props.services.find((s) => s.slug === props.preselected);
    if (service) void browse(service);
});

watch(timezone, () => {
    if (selectedService.value && step.value === 'choose') void loadSlots(selectedService.value);
});

const timezones = computed(() => {
    const tenantZone = page.props.tenant?.timezone ?? 'UTC';
    const detected = browserTimezone();

    return [...new Set([detected, tenantZone, 'Europe/London', 'America/New_York', 'Asia/Dhaka', 'UTC'])];
});
</script>

<template>
    <Head title="Book an appointment" />

    <PublicLayout>
        <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-16">
            <!-- Progress -->
            <ol class="mb-8 flex items-center gap-2 text-xs">
                <li
                    v-for="(label, index) in ['What you need', 'Pick a time', 'Your details']"
                    :key="label"
                    class="flex items-center gap-2"
                >
                    <span
                        class="flex size-5 items-center justify-center rounded-full text-[0.625rem] font-semibold"
                        :class="
                            ['ask', 'choose', 'details'].indexOf(step) >= index
                                ? 'bg-brand text-brand-ink'
                                : 'bg-surface-sunken text-ink-subtle'
                        "
                    >
                        {{ index + 1 }}
                    </span>
                    <span
                        :class="
                            ['ask', 'choose', 'details'].indexOf(step) >= index
                                ? 'font-medium text-ink'
                                : 'text-ink-subtle'
                        "
                    >
                        {{ label }}
                    </span>
                    <span v-if="index < 2" class="mx-1 h-px w-6 bg-line" aria-hidden="true" />
                </li>
            </ol>

            <!-- ── Step 1 · Ask ─────────────────────────────────────────── -->
            <div v-if="step === 'ask'" class="space-y-8">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-ink">
                        What do you need?
                    </h1>
                    <p class="mt-1.5 text-sm text-ink-muted">
                        Write it the way you would say it. We will find the times that are free.
                    </p>
                </div>

                <form class="space-y-3" @submit.prevent="ask">
                    <div class="relative">
                        <Sparkles
                            class="pointer-events-none absolute left-3.5 top-3.5 size-4 text-brand"
                            aria-hidden="true"
                        />
                        <textarea
                            v-model="query"
                            rows="3"
                            class="field resize-none py-3 pl-10 text-[0.9375rem]"
                            placeholder="e.g. a haircut next Tuesday afternoon"
                            aria-label="Describe the appointment you want"
                            @keydown.enter.exact.prevent="ask"
                        />
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="example in examples"
                            :key="example"
                            type="button"
                            class="rounded-full border border-line px-3 py-1 text-xs text-ink-muted transition hover:border-brand hover:text-brand"
                            @click="query = example; ask()"
                        >
                            {{ example }}
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                        <label class="flex items-center gap-2 text-xs text-ink-muted">
                            Times shown in
                            <select v-model="timezone" class="field w-auto py-1 text-xs">
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                        </label>

                        <AppButton type="submit" :loading="asking" :disabled="query.trim().length < 2">
                            Find times
                        </AppButton>
                    </div>
                </form>

                <div
                    v-if="askError"
                    class="flex items-start gap-2 rounded-lg border border-critical/30 bg-critical-soft px-4 py-3 text-xs text-critical"
                >
                    <AlertCircle class="mt-0.5 size-4 shrink-0" />
                    {{ askError }}
                </div>

                <div
                    v-else-if="assistantMessage && !selectedService"
                    class="rounded-lg border border-line bg-surface p-4"
                >
                    <p class="text-sm text-ink">{{ assistantMessage }}</p>
                    <AiProvenanceTag v-if="assistantProvenance" :ai="assistantProvenance" class="mt-2" />
                </div>

                <!-- Always available. The assistant is a shortcut, never the
                     only door — a booking page that only works when an API is
                     up is a booking page that loses money when it is not. -->
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <Search class="size-3.5 text-ink-subtle" />
                        <h2 class="text-xs font-medium uppercase tracking-wide text-ink-subtle">
                            Or choose a service
                        </h2>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="service in services"
                            :key="service.id"
                            type="button"
                            class="panel flex items-center justify-between gap-3 p-3.5 text-left transition hover:border-line-strong"
                            @click="browse(service)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink">
                                    {{ service.name }}
                                </span>
                                <span class="mt-0.5 block text-xs text-ink-subtle">
                                    {{ duration(service.duration_minutes) }}
                                </span>
                            </span>
                            <span class="tnum shrink-0 text-sm font-semibold text-ink">
                                {{ money(service.price_cents, currency) }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Step 2 · Choose ──────────────────────────────────────── -->
            <div v-else-if="step === 'choose'" class="space-y-6">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs text-ink-muted transition hover:text-ink"
                    @click="back"
                >
                    <ArrowLeft class="size-3.5" />
                    Start again
                </button>

                <div class="panel p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 class="text-lg font-semibold tracking-tight text-ink">
                                {{ selectedService?.name }}
                            </h1>
                            <p class="mt-1 flex items-center gap-3 text-xs text-ink-muted">
                                <span class="inline-flex items-center gap-1">
                                    <Clock class="size-3.5" />
                                    {{ duration(selectedService?.duration_minutes ?? 0) }}
                                </span>
                                <span class="tnum font-medium text-ink">
                                    {{ money(selectedService?.price_cents ?? 0, currency) }}
                                </span>
                            </p>
                        </div>
                        <AppBadge tone="neutral" size="sm">{{ timezone }}</AppBadge>
                    </div>

                    <div v-if="assistantMessage" class="mt-4 rounded-lg bg-brand-soft px-3.5 py-3">
                        <p class="text-xs leading-relaxed text-brand-strong">{{ assistantMessage }}</p>
                        <AiProvenanceTag v-if="assistantProvenance" :ai="assistantProvenance" class="mt-1.5" />
                    </div>

                    <p
                        v-if="relaxed"
                        class="mt-3 flex items-start gap-1.5 text-xs text-warning"
                    >
                        <AlertCircle class="mt-0.5 size-3.5 shrink-0" />
                        These are outside what you asked for — they were the closest free times.
                    </p>
                </div>

                <div v-if="loadingSlots" class="space-y-3">
                    <div v-for="n in 3" :key="n" class="panel h-24 animate-pulse bg-surface-sunken" />
                </div>

                <div v-else-if="slotsByDate.length === 0" class="panel px-6 py-12 text-center">
                    <CalendarDays class="mx-auto size-6 text-ink-subtle" />
                    <p class="mt-3 text-sm font-medium text-ink">Nothing free in that window</p>
                    <p class="mt-1 text-xs text-ink-muted">Try a wider range or a different service.</p>
                    <AppButton variant="secondary" size="sm" class="mt-4" @click="back">
                        Try something else
                    </AppButton>
                </div>

                <div v-else class="space-y-5">
                    <section v-for="group in slotsByDate" :key="group.date">
                        <h2 class="mb-2.5 flex items-baseline gap-2 text-sm font-medium text-ink">
                            {{ dateIn(group.slots[0].local_starts_at, timezone) }}
                            <span
                                v-if="relativeDay(group.date, timezone)"
                                class="text-xs font-normal text-brand"
                            >
                                {{ relativeDay(group.date, timezone) }}
                            </span>
                        </h2>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="slot in group.slots"
                                :key="`${slot.starts_at}-${slot.staff_id}`"
                                type="button"
                                class="panel px-3 py-2 text-left transition hover:border-brand hover:bg-brand-soft"
                                @click="choose(slot)"
                            >
                                <span class="tnum block text-sm font-medium text-ink">
                                    {{ slot.local_time }}
                                </span>
                                <span class="block text-[0.6875rem] text-ink-subtle">
                                    {{ slot.staff_name.split(' ')[0] }}
                                </span>
                            </button>
                        </div>
                    </section>
                </div>
            </div>

            <!-- ── Step 3 · Details ─────────────────────────────────────── -->
            <div v-else class="space-y-6">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs text-ink-muted transition hover:text-ink"
                    @click="back"
                >
                    <ArrowLeft class="size-3.5" />
                    Pick a different time
                </button>

                <div class="panel divide-y divide-line">
                    <div class="space-y-2 p-5">
                        <h1 class="text-lg font-semibold tracking-tight text-ink">Confirm your booking</h1>

                        <dl class="grid gap-2 text-sm sm:grid-cols-2">
                            <div class="flex items-center gap-2">
                                <CalendarDays class="size-4 text-ink-subtle" />
                                <dd class="text-ink">
                                    {{ chosenSlot ? dateIn(chosenSlot.local_starts_at, timezone) : '' }},
                                    <span class="tnum font-medium">{{ chosenSlot?.local_time }}</span>
                                </dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <User class="size-4 text-ink-subtle" />
                                <dd class="text-ink">{{ chosenSlot?.staff_name }}</dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <Clock class="size-4 text-ink-subtle" />
                                <dd class="text-ink">
                                    {{ selectedService?.name }} ·
                                    {{ duration(selectedService?.duration_minutes ?? 0) }}
                                </dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="tnum ml-6 font-semibold text-ink">
                                    {{ money(selectedService?.price_cents ?? 0, currency) }}
                                </span>
                            </div>
                        </dl>

                        <p class="pt-1 text-[0.6875rem] text-ink-subtle">
                            Times are in {{ timezone }}. Your confirmation will use the same clock.
                        </p>
                    </div>

                    <form class="space-y-4 p-5" @submit.prevent="confirm">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppField label="Your name" required :error="fieldError('customer_name')" for="name">
                                <input
                                    id="name"
                                    v-model="form.customer_name"
                                    type="text"
                                    class="field"
                                    autocomplete="name"
                                    required
                                />
                            </AppField>

                            <AppField label="Email" required :error="fieldError('customer_email')" for="email">
                                <input
                                    id="email"
                                    v-model="form.customer_email"
                                    type="email"
                                    class="field"
                                    autocomplete="email"
                                    required
                                />
                            </AppField>
                        </div>

                        <AppField
                            label="Phone"
                            hint="Optional, but it is how we reach you if something changes."
                            :error="fieldError('customer_phone')"
                            for="phone"
                        >
                            <input
                                id="phone"
                                v-model="form.customer_phone"
                                type="tel"
                                class="field"
                                autocomplete="tel"
                            />
                        </AppField>

                        <AppField label="Anything we should know?" :error="fieldError('notes')" for="notes">
                            <textarea id="notes" v-model="form.notes" rows="3" class="field resize-none" />
                        </AppField>

                        <div
                            v-if="submitError"
                            class="flex items-start gap-2 rounded-lg border border-critical/30 bg-critical-soft px-3.5 py-2.5 text-xs text-critical"
                        >
                            <AlertCircle class="mt-0.5 size-4 shrink-0" />
                            {{ submitError }}
                        </div>

                        <AppButton type="submit" size="lg" block :loading="submitting">
                            <CheckCircle2 class="size-4" />
                            Confirm booking
                        </AppButton>
                    </form>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
