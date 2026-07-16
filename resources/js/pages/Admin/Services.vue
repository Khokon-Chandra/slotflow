<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus, Pencil, Sparkles, Scissors } from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppField from '@/components/ui/AppField.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppEmpty from '@/components/ui/AppEmpty.vue';
import AiProvenanceTag from '@/components/AiProvenanceTag.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import { money, duration } from '@/lib/format';
import type { AiProvenance } from '@/types';

interface ServiceRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    keywords: string | null;
    duration_minutes: number;
    buffer_minutes: number;
    price_cents: number;
    color: string;
    is_active: boolean;
    requires_deposit: boolean;
    deposit_cents: number;
    staff_ids: number[];
    booking_count: number;
}

const props = defineProps<{
    services: ServiceRow[];
    staffOptions: { value: number; label: string }[];
}>();

const page = usePage();
const currency = computed(() => page.props.tenant?.currency ?? 'EUR');

const blank = (): Partial<ServiceRow> => ({
    name: '',
    description: '',
    keywords: '',
    duration_minutes: 45,
    buffer_minutes: 10,
    price_cents: 0,
    color: '#6366f1',
    is_active: true,
    requires_deposit: false,
    deposit_cents: 0,
    staff_ids: [],
});

const editing = ref<Partial<ServiceRow> | null>(null);
const isNew = computed(() => editing.value?.id === undefined);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const formError = ref<string | null>(null);

// Price is edited in whole currency units and stored in minor units. Doing
// the conversion in one place stops a stray `* 100` appearing somewhere else.
const priceMajor = computed({
    get: () => (editing.value?.price_cents ?? 0) / 100,
    set: (value: number) => {
        if (editing.value) editing.value.price_cents = Math.round(Number(value) * 100);
    },
});

/* AI copywriter --------------------------------------------------------- */

const drafting = ref(false);
const draftProvenance = ref<AiProvenance | null>(null);
const draftHighlights = ref<string[]>([]);
const draftError = ref<string | null>(null);

async function draftDescription(): Promise<void> {
    if (!editing.value?.name) return;

    drafting.value = true;
    draftError.value = null;

    try {
        const { data } = await api.post<{
            data: { description: string; highlights: string[]; ai: AiProvenance };
        }>(apiUrl('/ai/service-description'), {
            name: editing.value.name,
            duration_minutes: editing.value.duration_minutes,
            price_cents: editing.value.price_cents,
            keywords: editing.value.keywords || undefined,
        });

        // Into the field, not into the database. The owner reads it, edits it
        // and decides — a draft, never a publish.
        editing.value.description = data.description;
        draftHighlights.value = data.highlights;
        draftProvenance.value = data.ai;
    } catch (error) {
        draftError.value =
            error instanceof ApiRequestError ? error.error.message : 'Could not draft a description.';
    } finally {
        drafting.value = false;
    }
}

/* Persist ---------------------------------------------------------------- */

function open(service?: ServiceRow): void {
    editing.value = service ? { ...service } : blank();
    errors.value = {};
    formError.value = null;
    draftProvenance.value = null;
    draftHighlights.value = [];
}

async function save(): Promise<void> {
    if (!editing.value) return;

    saving.value = true;
    errors.value = {};
    formError.value = null;

    const body = {
        name: editing.value.name,
        description: editing.value.description,
        keywords: editing.value.keywords,
        duration_minutes: Number(editing.value.duration_minutes),
        buffer_minutes: Number(editing.value.buffer_minutes),
        price_cents: Number(editing.value.price_cents),
        color: editing.value.color,
        is_active: editing.value.is_active,
        requires_deposit: editing.value.requires_deposit,
        deposit_cents: Number(editing.value.deposit_cents ?? 0),
        staff_ids: editing.value.staff_ids ?? [],
    };

    try {
        isNew.value
            ? await api.post(apiUrl('/services'), body)
            : await api.put(apiUrl(`/services/${editing.value.slug}`), body);

        editing.value = null;
        router.reload({ only: ['services'] });
    } catch (error) {
        if (error instanceof ApiRequestError) {
            errors.value = error.error.fields ?? {};
            formError.value = error.error.message;
        }
    } finally {
        saving.value = false;
    }
}

const err = (field: string): string | undefined => errors.value[field]?.[0];

function toggleStaff(id: number): void {
    if (!editing.value) return;

    const ids = editing.value.staff_ids ?? [];
    editing.value.staff_ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id];
}
</script>

<template>
    <Head title="Services" />

    <AdminLayout title="Services" :subtitle="`${services.length} services`">
        <template #default>
            <div class="space-y-4">
                <div class="flex justify-end">
                    <AppButton size="sm" @click="open()">
                        <Plus class="size-4" />
                        New service
                    </AppButton>
                </div>

                <AppCard :padded="false">
                    <AppEmpty v-if="services.length === 0" title="No services yet" description="Add the first thing people can book.">
                        <template #icon><Scissors class="size-5" /></template>
                    </AppEmpty>

                    <ul v-else class="divide-y divide-line">
                        <li
                            v-for="service in services"
                            :key="service.id"
                            class="flex items-start gap-4 px-5 py-4 transition hover:bg-surface-sunken"
                        >
                            <span
                                class="mt-1 h-10 w-1 shrink-0 rounded-full"
                                :style="{ backgroundColor: service.color }"
                                aria-hidden="true"
                            />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ service.name }}</h3>
                                    <AppBadge v-if="!service.is_active" tone="neutral" size="sm">Hidden</AppBadge>
                                    <AppBadge v-if="service.requires_deposit" tone="brand" size="sm">
                                        Deposit {{ money(service.deposit_cents, currency) }}
                                    </AppBadge>
                                </div>

                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-ink-muted">
                                    {{ service.description }}
                                </p>

                                <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-[0.6875rem] text-ink-subtle">
                                    <span>{{ duration(service.duration_minutes) }} appointment</span>
                                    <span v-if="service.buffer_minutes">+{{ service.buffer_minutes }} min turnaround</span>
                                    <span>{{ service.booking_count }} bookings all time</span>
                                    <span v-if="service.staff_ids.length">
                                        {{
                                            staffOptions
                                                .filter((s) => service.staff_ids.includes(s.value))
                                                .map((s) => s.label)
                                                .join(', ')
                                        }}
                                    </span>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span class="tnum text-sm font-semibold text-ink">
                                    {{ money(service.price_cents, currency) }}
                                </span>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-ink-subtle transition hover:bg-surface hover:text-ink"
                                    :aria-label="`Edit ${service.name}`"
                                    @click="open(service)"
                                >
                                    <Pencil class="size-4" />
                                </button>
                            </div>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <AppModal
                :open="editing !== null"
                :title="isNew ? 'New service' : `Edit ${editing?.name}`"
                wide
                @close="editing = null"
            >
                <form v-if="editing" id="service-form" class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField label="Name" required :error="err('name')" for="svc-name">
                            <input id="svc-name" v-model="editing.name" type="text" class="field" required />
                        </AppField>

                        <AppField label="Colour" :error="err('color')" for="svc-color">
                            <input id="svc-color" v-model="editing.color" type="color" class="field h-9 p-1" />
                        </AppField>
                    </div>

                    <AppField
                        label="Search keywords"
                        hint="Comma separated. What customers actually type: haircut, trim, blow dry."
                        :error="err('keywords')"
                        for="svc-keywords"
                    >
                        <input id="svc-keywords" v-model="editing.keywords" type="text" class="field" />
                    </AppField>

                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label for="svc-desc" class="block text-xs font-medium text-ink-muted">
                                Description
                            </label>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :loading="drafting"
                                :disabled="!editing.name"
                                @click="draftDescription"
                            >
                                <Sparkles class="size-3.5" />
                                Draft with AI
                            </AppButton>
                        </div>

                        <textarea id="svc-desc" v-model="editing.description" rows="3" class="field resize-none" />

                        <p v-if="err('description')" class="text-xs text-critical">{{ err('description') }}</p>
                        <p v-else-if="draftError" class="text-xs text-critical">{{ draftError }}</p>
                        <div v-else-if="draftProvenance" class="flex flex-wrap items-center gap-2">
                            <AiProvenanceTag :ai="draftProvenance" />
                            <span class="text-[0.6875rem] text-ink-subtle">— a draft. Edit it before saving.</span>
                        </div>

                        <div v-if="draftHighlights.length" class="flex flex-wrap gap-1.5 pt-1">
                            <AppBadge v-for="h in draftHighlights" :key="h" tone="brand" size="sm">{{ h }}</AppBadge>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <AppField label="Duration (min)" required :error="err('duration_minutes')" for="svc-dur">
                            <input id="svc-dur" v-model.number="editing.duration_minutes" type="number" min="5" max="600" class="field" required />
                        </AppField>

                        <AppField
                            label="Turnaround (min)"
                            hint="Blocked after, not billed."
                            :error="err('buffer_minutes')"
                            for="svc-buf"
                        >
                            <input id="svc-buf" v-model.number="editing.buffer_minutes" type="number" min="0" max="180" class="field" />
                        </AppField>

                        <AppField :label="`Price (${currency})`" required :error="err('price_cents')" for="svc-price">
                            <input id="svc-price" v-model.number="priceMajor" type="number" min="0" step="0.5" class="field" required />
                        </AppField>
                    </div>

                    <fieldset>
                        <legend class="mb-2 text-xs font-medium text-ink-muted">Who performs it</legend>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="option in staffOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs transition"
                                :class="
                                    (editing.staff_ids ?? []).includes(option.value)
                                        ? 'border-brand bg-brand-soft text-brand-strong'
                                        : 'border-line text-ink-muted hover:border-line-strong'
                                "
                                @click="toggleStaff(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap gap-5">
                        <label class="flex items-center gap-2 text-xs text-ink-muted">
                            <input v-model="editing.is_active" type="checkbox" class="rounded border-line-strong" />
                            Bookable online
                        </label>
                        <label class="flex items-center gap-2 text-xs text-ink-muted">
                            <input v-model="editing.requires_deposit" type="checkbox" class="rounded border-line-strong" />
                            Requires a deposit
                        </label>
                    </div>

                    <AppField
                        v-if="editing.requires_deposit"
                        :label="`Deposit (${currency} cents)`"
                        hint="A held deposit lowers the no-show score by 20 points."
                        :error="err('deposit_cents')"
                        for="svc-dep"
                    >
                        <input id="svc-dep" v-model.number="editing.deposit_cents" type="number" min="0" class="field" />
                    </AppField>

                    <p v-if="formError" class="rounded-lg border border-critical/30 bg-critical-soft px-3.5 py-2.5 text-xs text-critical">
                        {{ formError }}
                    </p>
                </form>

                <template #footer>
                    <AppButton variant="ghost" @click="editing = null">Cancel</AppButton>
                    <AppButton type="submit" form="service-form" :loading="saving">
                        {{ isNew ? 'Create service' : 'Save changes' }}
                    </AppButton>
                </template>
            </AppModal>
        </template>
    </AdminLayout>
</template>
