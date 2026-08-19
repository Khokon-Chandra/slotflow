<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    KeyRound,
    ShieldCheck,
    ShieldAlert,
    Trash2,
    RefreshCw,
    ExternalLink,
    Eye,
    EyeOff,
    Plus,
    CircleCheck,
    Info,
    Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppField from '@/components/ui/AppField.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppEmpty from '@/components/ui/AppEmpty.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import type { AiConnectedProvider, AiProviderCatalogueEntry, AiEffectiveConfig } from '@/types';

const props = defineProps<{
    connected: AiConnectedProvider[];
    catalogue: AiProviderCatalogueEntry[];
    effective: AiEffectiveConfig;
    settings: { monthly_budget_usd: number | null };
}>();

/* ── connect / edit form ──────────────────────────────────────────────── */

interface Draft {
    provider: string;
    api_key: string;
    model: string;
    label: string;
    base_url: string;
    input_rate_per_mtok: number | null;
    output_rate_per_mtok: number | null;
    make_active: boolean;
}

const editing = ref<Draft | null>(null);
const revealed = ref(false);
const busy = ref<string | null>(null);
const error = ref<string | null>(null);
const fields = ref<Record<string, string[]>>({});
const notice = ref<string | null>(null);

const entry = computed(() =>
    props.catalogue.find((c) => c.id === editing.value?.provider) ?? null,
);

const isCustom = computed(() => entry.value?.requires_base_url === true);

const connectedIds = computed(() => new Set(props.connected.map((c) => c.provider)));

/** Rates the catalogue already knows, so the form does not ask twice. */
const catalogueRates = computed(() => {
    const model = entry.value?.models.find((m) => m.id === editing.value?.model);
    return model?.has_rates ? model : null;
});

function open(providerId: string, existing?: AiConnectedProvider): void {
    const cat = props.catalogue.find((c) => c.id === providerId);

    editing.value = {
        provider: providerId,
        api_key: '',
        model: existing?.model ?? cat?.models[0]?.id ?? '',
        label: existing?.label ?? '',
        base_url: existing?.base_url ?? '',
        input_rate_per_mtok: existing?.input_rate_per_mtok ?? null,
        output_rate_per_mtok: existing?.output_rate_per_mtok ?? null,
        make_active: existing?.is_active ?? true,
    };

    revealed.value = false;
    error.value = null;
    fields.value = {};
    notice.value = null;
}

function fail(e: unknown): void {
    if (e instanceof ApiRequestError) {
        fields.value = e.error.fields ?? {};
        error.value = Object.keys(fields.value).length > 0 ? null : e.error.message;
    } else {
        error.value = 'Something went wrong. Try again.';
    }
}

const fieldError = (name: string): string | undefined => fields.value[name]?.[0];

async function connect(): Promise<void> {
    if (!editing.value) return;

    busy.value = 'save';
    error.value = null;
    fields.value = {};

    try {
        const { data } = await api.put<{ data: { verification: { note: string | null } } }>(
            apiUrl(`/admin/ai-providers/${editing.value.provider}`),
            editing.value,
        );

        // Straight out of the browser the moment it is stored. There is no
        // state in which the page needs to keep holding the key.
        editing.value = null;
        notice.value = data.verification.note ?? 'Connected and verified.';
        router.reload({ only: ['connected', 'effective'] });
    } catch (e) {
        fail(e);
    } finally {
        busy.value = null;
    }
}

async function act(
    provider: string,
    action: 'activate' | 'verify' | 'remove',
): Promise<void> {
    busy.value = `${action}:${provider}`;
    error.value = null;
    notice.value = null;

    try {
        if (action === 'remove') {
            await api.delete(apiUrl(`/admin/ai-providers/${provider}`));
            notice.value = 'Disconnected.';
        } else {
            const { data } = await api.post<{ data: { verification?: { ok: boolean; error: string | null; note: string | null } } }>(
                apiUrl(`/admin/ai-providers/${provider}/${action}`),
            );

            if (action === 'verify') {
                const v = data.verification;
                v?.ok ? (notice.value = v.note ?? 'Still working.') : (error.value = v?.error ?? null);
            } else {
                notice.value = 'Now in force.';
            }
        }

        router.reload({ only: ['connected', 'effective'] });
    } catch (e) {
        fail(e);
    } finally {
        busy.value = null;
    }
}

/* ── budget ───────────────────────────────────────────────────────────── */

const budget = ref<number | null>(props.settings.monthly_budget_usd);

async function saveBudget(): Promise<void> {
    busy.value = 'budget';
    error.value = null;

    try {
        await api.put(apiUrl('/admin/ai-settings'), { monthly_budget_usd: budget.value });
        notice.value = 'Ceiling saved.';
        router.reload({ only: ['settings', 'effective'] });
    } catch (e) {
        fail(e);
    } finally {
        busy.value = null;
    }
}

/* ── presentation ─────────────────────────────────────────────────────── */

const status = computed(() => {
    if (props.effective.configured_driver === 'heuristic') {
        return {
            tone: 'neutral' as const,
            label: 'Turned off',
            detail: 'AI_DRIVER is set to "heuristic" in the environment, so no provider is called even when one is connected.',
        };
    }

    switch (props.effective.source) {
        case 'workspace':
            return {
                tone: 'positive' as const,
                label: `Using ${props.effective.provider_label}`,
                detail: `AI requests are billed to your ${props.effective.provider_label} account, using ${props.effective.model}.`,
            };
        case 'platform':
            return {
                tone: 'brand' as const,
                label: 'Using the platform provider',
                detail: 'Working, but billed to the platform. Connect your own provider to use your own account and your own limits.',
            };
        default:
            return {
                tone: 'warning' as const,
                label: 'Nothing connected',
                detail: 'Everything still works — the built-in fallback answers instead. Connect a provider for noticeably better results.',
            };
    }
});

const when = (iso: string | null): string =>
    iso === null
        ? '—'
        : new Intl.DateTimeFormat(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          }).format(new Date(iso));
</script>

<template>
    <Head title="AI providers" />

    <AdminLayout title="AI providers" subtitle="Connect a model provider and choose which one is in force">
        <div class="space-y-6">
            <!-- What is actually happening right now -->
            <AppCard>
                <template #actions>
                    <AppBadge :tone="status.tone" size="sm">
                        <KeyRound class="size-3" />
                        {{ status.label }}
                    </AppBadge>
                </template>

                <p class="text-xs leading-relaxed text-ink-muted">{{ status.detail }}</p>

                <p
                    v-if="effective.source !== 'none' && !effective.tracks_spend"
                    class="mt-3 flex items-start gap-2 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-xs text-warning"
                >
                    <Wallet class="mt-0.5 size-4 shrink-0" />
                    <span>
                        Spend is not being tracked for <strong>{{ effective.model }}</strong> — this application has no
                        rates for it. Add the price per million tokens below and the usage figures and the monthly
                        ceiling start working. Until then cost is reported as unknown, never as zero.
                    </span>
                </p>
            </AppCard>

            <p v-if="notice" class="rounded-lg border border-positive/30 bg-positive-soft px-4 py-2.5 text-xs text-positive">
                {{ notice }}
            </p>
            <p v-if="error" class="rounded-lg border border-critical/30 bg-critical-soft px-4 py-2.5 text-xs text-critical">
                {{ error }}
            </p>

            <!-- Connected -->
            <AppCard title="Connected" :subtitle="`${connected.length} provider${connected.length === 1 ? '' : 's'}`" :padded="false">
                <AppEmpty
                    v-if="connected.length === 0"
                    title="No provider connected"
                    description="Pick one below. Everything works without it — the built-in fallback answers."
                >
                    <template #icon><KeyRound class="size-5" /></template>
                </AppEmpty>

                <ul v-else class="divide-y divide-line">
                    <li v-for="item in connected" :key="item.id" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-ink">{{ item.display_name }}</h3>
                                    <AppBadge v-if="item.is_active" tone="positive" size="sm">
                                        <CircleCheck class="size-3" />
                                        In force
                                    </AppBadge>
                                    <AppBadge v-if="!item.tracks_spend" tone="warning" size="sm">Spend untracked</AppBadge>
                                </div>

                                <p class="mt-1 font-mono text-xs text-ink-subtle">
                                    {{ item.model }} · key {{ item.masked_key }}
                                    <span v-if="item.endpoint"> · {{ item.endpoint }}</span>
                                </p>

                                <p class="mt-1 text-[0.6875rem] text-ink-subtle">
                                    Added {{ when(item.key_set_at) }}<span v-if="item.key_set_by"> by {{ item.key_set_by }}</span>
                                </p>

                                <p
                                    class="mt-1.5 flex items-start gap-1.5 text-[0.6875rem]"
                                    :class="item.last_check_passed ? 'text-positive' : 'text-critical'"
                                >
                                    <ShieldCheck v-if="item.last_check_passed" class="mt-0.5 size-3 shrink-0" />
                                    <ShieldAlert v-else class="mt-0.5 size-3 shrink-0" />
                                    <span>
                                        <template v-if="item.last_check_passed">
                                            Last checked {{ when(item.last_checked_at) }} — working
                                        </template>
                                        <template v-else>{{ item.last_check_error }}</template>
                                    </span>
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <AppButton
                                    v-if="!item.is_active"
                                    variant="secondary"
                                    size="sm"
                                    :loading="busy === `activate:${item.provider}`"
                                    :disabled="busy !== null"
                                    @click="act(item.provider, 'activate')"
                                >
                                    Use this one
                                </AppButton>
                                <AppButton
                                    variant="secondary"
                                    size="sm"
                                    :loading="busy === `verify:${item.provider}`"
                                    :disabled="busy !== null"
                                    @click="act(item.provider, 'verify')"
                                >
                                    <RefreshCw class="size-3.5" />
                                    Re-check
                                </AppButton>
                                <AppButton variant="ghost" size="sm" :disabled="busy !== null" @click="open(item.provider, item)">
                                    Replace key
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    :loading="busy === `remove:${item.provider}`"
                                    :disabled="busy !== null"
                                    @click="act(item.provider, 'remove')"
                                >
                                    <Trash2 class="size-3.5" />
                                </AppButton>
                            </div>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <!-- Catalogue -->
            <AppCard title="Available providers" subtitle="Anything that speaks Anthropic or OpenAI Chat Completions">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="item in catalogue"
                        :key="item.id"
                        type="button"
                        class="panel flex flex-col items-start p-4 text-left transition hover:border-brand"
                        :disabled="busy !== null"
                        @click="open(item.id)"
                    >
                        <div class="flex w-full items-center justify-between gap-2">
                            <span class="text-sm font-medium text-ink">{{ item.label }}</span>
                            <AppBadge v-if="connectedIds.has(item.id)" tone="positive" size="sm">Connected</AppBadge>
                            <Plus v-else class="size-4 text-ink-subtle" />
                        </div>

                        <p class="mt-1.5 text-[0.6875rem] leading-relaxed text-ink-subtle">
                            <template v-if="item.requires_base_url">
                                Any OpenAI-compatible endpoint — a gateway, a self-hosted runtime, a provider not listed here.
                            </template>
                            <template v-else>
                                {{ item.models.length }} model{{ item.models.length === 1 ? '' : 's' }}
                                <template v-if="!item.supports_json_schema"> · JSON mode only</template>
                            </template>
                        </p>
                    </button>
                </div>

                <p class="mt-4 flex items-start gap-1.5 text-[0.6875rem] leading-relaxed text-ink-subtle">
                    <Info class="mt-0.5 size-3 shrink-0" />
                    Keys are checked with the provider before they are saved, encrypted with the application key before
                    they are written, never returned by any endpoint, and never logged. Exactly one provider is in force
                    at a time; disconnecting the active one hands over to the next, or to the built-in fallback.
                </p>
            </AppCard>

            <!-- Budget -->
            <AppCard title="Monthly ceiling" subtitle="Crossing it degrades to the fallback; it never breaks anything">
                <div class="flex flex-wrap items-end gap-3">
                    <AppField label="USD per month" hint="Leave empty for the platform default." for="budget" class="w-48">
                        <input
                            id="budget"
                            v-model.number="budget"
                            type="number"
                            min="0"
                            step="1"
                            class="field"
                            :placeholder="String(effective.monthly_budget_usd)"
                            :disabled="busy !== null"
                        />
                    </AppField>

                    <AppButton variant="secondary" size="sm" :loading="busy === 'budget'" :disabled="busy !== null" @click="saveBudget">
                        Save
                    </AppButton>
                </div>

                <p v-if="!effective.tracks_spend && effective.source !== 'none'" class="mt-3 text-[0.6875rem] text-ink-subtle">
                    The ceiling is not enforced while spend is untracked — a limit measured against an unknown cost is
                    not a limit. Add rates for your model above to turn it on.
                </p>
            </AppCard>
        </div>

        <!-- Connect / replace -->
        <AppModal
            :open="editing !== null"
            :title="entry ? `Connect ${entry.label}` : 'Connect a provider'"
            wide
            @close="editing = null"
        >
            <form v-if="editing && entry" id="connect-form" class="space-y-5" @submit.prevent="connect">
                <AppField
                    v-if="isCustom"
                    label="Name"
                    hint="What you will recognise this connection by."
                    :error="fieldError('label')"
                    for="c-label"
                    required
                >
                    <input id="c-label" v-model="editing.label" type="text" class="field" placeholder="Ollama on the office box" required />
                </AppField>

                <AppField
                    v-if="isCustom"
                    label="Base URL"
                    hint="The root of an OpenAI-compatible API, usually ending in /v1. https, or http only for localhost."
                    :error="fieldError('base_url')"
                    for="c-url"
                    required
                >
                    <input id="c-url" v-model="editing.base_url" type="url" class="field font-mono" placeholder="https://api.example.com/v1" required />
                </AppField>

                <AppField
                    label="API key"
                    :hint="`${entry.key_hint ? entry.key_hint + ' · ' : ''}Checked with the provider before it is saved, then stored encrypted — this page can never show it again.`"
                    :error="fieldError('api_key')"
                    for="c-key"
                    required
                >
                    <div class="relative">
                        <input
                            id="c-key"
                            v-model="editing.api_key"
                            :type="revealed ? 'text' : 'password'"
                            class="field pr-10 font-mono"
                            :placeholder="entry.key_hint || 'your API key'"
                            autocomplete="off"
                            spellcheck="false"
                            required
                        />
                        <button
                            type="button"
                            class="absolute right-2 top-1.5 rounded-md p-1.5 text-ink-subtle transition hover:text-ink"
                            :aria-label="revealed ? 'Hide the key' : 'Show the key'"
                            @click="revealed = !revealed"
                        >
                            <EyeOff v-if="revealed" class="size-4" />
                            <Eye v-else class="size-4" />
                        </button>
                    </div>
                </AppField>

                <AppField label="Model" :error="fieldError('model')" for="c-model" required>
                    <select v-if="entry.models.length > 0" id="c-model" v-model="editing.model" class="field" required>
                        <option v-for="m in entry.models" :key="m.id" :value="m.id">
                            {{ m.label }}<template v-if="m.has_rates"> — ${{ m.input_per_mtok_usd }}/${{ m.output_per_mtok_usd }} per Mtok</template>
                        </option>
                    </select>
                    <input v-else id="c-model" v-model="editing.model" type="text" class="field font-mono" placeholder="llama3.1:8b" required />
                </AppField>

                <div v-if="!catalogueRates" class="space-y-3 rounded-lg border border-line bg-surface-sunken p-4">
                    <p class="text-xs leading-relaxed text-ink-muted">
                        This application has no published rates for that model, so it will not guess. Enter what your
                        provider charges and spend tracking and the monthly ceiling start working; leave them empty and
                        cost is reported as unknown rather than as zero.
                        <a
                            v-if="entry.pricing_url"
                            :href="entry.pricing_url"
                            target="_blank"
                            rel="noreferrer noopener"
                            class="inline-flex items-center gap-1 text-brand hover:underline"
                        >
                            {{ entry.label }} pricing <ExternalLink class="size-3" />
                        </a>
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <AppField label="Input · USD per Mtok" :error="fieldError('input_rate_per_mtok')" for="c-in">
                            <input id="c-in" v-model.number="editing.input_rate_per_mtok" type="number" min="0" step="0.01" class="field" />
                        </AppField>
                        <AppField label="Output · USD per Mtok" :error="fieldError('output_rate_per_mtok')" for="c-out">
                            <input id="c-out" v-model.number="editing.output_rate_per_mtok" type="number" min="0" step="0.01" class="field" />
                        </AppField>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-xs text-ink-muted">
                    <input v-model="editing.make_active" type="checkbox" class="rounded border-line-strong" />
                    Make this the provider in force
                </label>

                <a
                    v-if="entry.console_url"
                    :href="entry.console_url"
                    target="_blank"
                    rel="noreferrer noopener"
                    class="inline-flex items-center gap-1.5 text-xs text-brand hover:underline"
                >
                    Get a key from the {{ entry.label }} console <ExternalLink class="size-3" />
                </a>

                <p v-if="error" class="rounded-lg border border-critical/30 bg-critical-soft px-3.5 py-2.5 text-xs text-critical">
                    {{ error }}
                </p>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="editing = null">Cancel</AppButton>
                <AppButton type="submit" form="connect-form" :loading="busy === 'save'">Verify and connect</AppButton>
            </template>
        </AppModal>
    </AdminLayout>
</template>
