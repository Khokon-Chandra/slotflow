<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    KeyRound,
    ShieldCheck,
    ShieldAlert,
    Trash2,
    RefreshCw,
    ExternalLink,
    Eye,
    EyeOff,
    Info,
} from 'lucide-vue-next';
import AppCard from '@/components/ui/AppCard.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppField from '@/components/ui/AppField.vue';
import { api, apiUrl, ApiRequestError } from '@/lib/api';
import type { AiSettings, AiEffectiveConfig, AiModelOption } from '@/types';

const props = defineProps<{
    settings: AiSettings;
    effective: AiEffectiveConfig;
    models: AiModelOption[];
}>();

const emit = defineEmits<{ changed: [] }>();

/* ── local state ──────────────────────────────────────────────────────── */

const settings = ref<AiSettings>({ ...props.settings });
const effective = ref<AiEffectiveConfig>({ ...props.effective });

const apiKey = ref('');
const revealed = ref(false);
const model = ref<string | null>(props.settings.model);
const budget = ref<number | null>(props.settings.monthly_budget_usd);

const busy = ref<'save' | 'remove' | 'verify' | 'prefs' | null>(null);
const error = ref<string | null>(null);
const fieldError = ref<string | null>(null);
const notice = ref<string | null>(null);

/* ── derived ──────────────────────────────────────────────────────────── */

const status = computed(() => {
    if (effective.value.configured_driver === 'heuristic') {
        return {
            tone: 'neutral' as const,
            label: 'Turned off',
            detail: 'AI_DRIVER is set to "heuristic" in the environment, so no key will be used even if one is installed.',
        };
    }

    if (effective.value.key_source === 'tenant') {
        return {
            tone: 'positive' as const,
            label: 'Using your key',
            detail: 'AI requests from this workspace are billed to your Anthropic account.',
        };
    }

    if (effective.value.key_source === 'platform') {
        return {
            tone: 'brand' as const,
            label: 'Using the platform key',
            detail: 'Working, but billed to the platform. Add your own key to use your own account and your own limits.',
        };
    }

    return {
        tone: 'warning' as const,
        label: 'No key installed',
        detail: 'Everything still works — the built-in fallback answers instead. Add a key for noticeably better results.',
    };
});

function apply(data: { settings: AiSettings; effective: AiEffectiveConfig }): void {
    settings.value = data.settings;
    effective.value = data.effective;
    model.value = data.settings.model;
    budget.value = data.settings.monthly_budget_usd;
    emit('changed');
}

function reset(): void {
    error.value = null;
    fieldError.value = null;
    notice.value = null;
}

function handle(e: unknown): void {
    if (e instanceof ApiRequestError) {
        fieldError.value = e.error.fields?.api_key?.[0] ?? null;
        error.value = fieldError.value ? null : e.error.message;
    } else {
        error.value = 'Something went wrong. Try again.';
    }
}

/* ── actions ──────────────────────────────────────────────────────────── */

async function saveKey(): Promise<void> {
    if (apiKey.value.trim().length === 0) return;

    busy.value = 'save';
    reset();

    try {
        const { data } = await api.put<{ data: { settings: AiSettings; effective: AiEffectiveConfig } }>(
            apiUrl('/admin/ai-settings/key'),
            { api_key: apiKey.value, model: model.value },
        );

        apply(data);
        // Out of the component the moment it is stored. There is no state in
        // which the browser needs to keep holding the key.
        apiKey.value = '';
        revealed.value = false;
        notice.value = 'Key verified with Anthropic and saved.';
    } catch (e) {
        handle(e);
    } finally {
        busy.value = null;
    }
}

async function removeKey(): Promise<void> {
    busy.value = 'remove';
    reset();

    try {
        const { data } = await api.delete<{ data: { settings: AiSettings; effective: AiEffectiveConfig } }>(
            apiUrl('/admin/ai-settings/key'),
        );

        apply(data);
        notice.value = 'Key removed.';
    } catch (e) {
        handle(e);
    } finally {
        busy.value = null;
    }
}

async function verifyKey(): Promise<void> {
    busy.value = 'verify';
    reset();

    try {
        const { data } = await api.post<{
            data: { settings: AiSettings; effective: AiEffectiveConfig; verification: { ok: boolean; error: string | null } };
        }>(apiUrl('/admin/ai-settings/verify'));

        apply(data);
        notice.value = data.verification.ok
            ? 'Key checked — still working.'
            : null;
        error.value = data.verification.ok ? null : data.verification.error;
    } catch (e) {
        handle(e);
    } finally {
        busy.value = null;
    }
}

async function savePreferences(): Promise<void> {
    busy.value = 'prefs';
    reset();

    try {
        const { data } = await api.put<{ data: { settings: AiSettings; effective: AiEffectiveConfig } }>(
            apiUrl('/admin/ai-settings'),
            { model: model.value, monthly_budget_usd: budget.value },
        );

        apply(data);
        notice.value = 'Saved.';
    } catch (e) {
        handle(e);
    } finally {
        busy.value = null;
    }
}

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
    <AppCard title="Anthropic API key" subtitle="Bring your own key to use your own account and limits">
        <template #actions>
            <AppBadge :tone="status.tone" size="sm">
                <KeyRound class="size-3" />
                {{ status.label }}
            </AppBadge>
        </template>

        <div class="space-y-6">
            <p class="text-xs leading-relaxed text-ink-muted">{{ status.detail }}</p>

            <!-- Installed key -->
            <div v-if="settings.has_key" class="rounded-lg border border-line bg-surface-sunken p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-ink">{{ settings.masked_key }}</p>
                        <p class="mt-1 text-xs text-ink-subtle">
                            Added {{ when(settings.key_set_at) }}<span v-if="settings.key_set_by"> by {{ settings.key_set_by }}</span>
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <AppButton
                            variant="secondary"
                            size="sm"
                            :loading="busy === 'verify'"
                            :disabled="busy !== null"
                            @click="verifyKey"
                        >
                            <RefreshCw class="size-3.5" />
                            Re-check
                        </AppButton>
                        <AppButton
                            variant="ghost"
                            size="sm"
                            :loading="busy === 'remove'"
                            :disabled="busy !== null"
                            @click="removeKey"
                        >
                            <Trash2 class="size-3.5" />
                            Remove
                        </AppButton>
                    </div>
                </div>

                <p
                    class="mt-3 flex items-start gap-1.5 border-t border-line pt-3 text-xs"
                    :class="settings.last_check_passed ? 'text-positive' : 'text-critical'"
                >
                    <ShieldCheck v-if="settings.last_check_passed" class="mt-0.5 size-3.5 shrink-0" />
                    <ShieldAlert v-else class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        <template v-if="settings.last_check_passed">
                            Last checked {{ when(settings.last_checked_at) }} — working.
                        </template>
                        <template v-else>
                            {{ settings.last_check_error ?? 'The last check did not pass.' }}
                        </template>
                        <span class="block text-ink-subtle">
                            A key can be revoked at any time, so this says when it was last known good — not that it works right now.
                        </span>
                    </span>
                </p>
            </div>

            <!-- Add / replace -->
            <form class="space-y-3" @submit.prevent="saveKey">
                <AppField
                    :label="settings.has_key ? 'Replace the key' : 'Add a key'"
                    :error="fieldError ?? undefined"
                    hint="Starts with sk-ant-. It is checked with Anthropic before it is saved, and stored encrypted — this page can never show it again."
                    for="ai-key"
                >
                    <div class="relative">
                        <input
                            id="ai-key"
                            v-model="apiKey"
                            :type="revealed ? 'text' : 'password'"
                            class="field pr-10 font-mono"
                            placeholder="sk-ant-api03-…"
                            autocomplete="off"
                            spellcheck="false"
                            :disabled="busy !== null"
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

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a
                        href="https://console.anthropic.com/settings/keys"
                        target="_blank"
                        rel="noreferrer noopener"
                        class="inline-flex items-center gap-1.5 text-xs text-brand hover:underline"
                    >
                        Get a key from the Anthropic console
                        <ExternalLink class="size-3" />
                    </a>

                    <AppButton
                        type="submit"
                        size="sm"
                        :loading="busy === 'save'"
                        :disabled="busy !== null || apiKey.trim().length === 0"
                    >
                        Verify and save
                    </AppButton>
                </div>
            </form>

            <!-- Preferences -->
            <div class="space-y-4 border-t border-line pt-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField
                        label="Model"
                        hint="Only models this app can price, so spend is never reported as zero."
                        for="ai-model"
                    >
                        <select id="ai-model" v-model="model" class="field" :disabled="busy !== null">
                            <option :value="null">
                                Platform default ({{ models.find((m) => m.is_platform_default)?.id }})
                            </option>
                            <option v-for="option in models" :key="option.id" :value="option.id">
                                {{ option.id }} — ${{ option.input_per_mtok_usd }}/${{ option.output_per_mtok_usd }} per Mtok
                            </option>
                        </select>
                    </AppField>

                    <AppField
                        label="Monthly ceiling (USD)"
                        hint="Leave empty for the platform default. Crossing it degrades to the fallback; it never breaks anything."
                        for="ai-budget"
                    >
                        <input
                            id="ai-budget"
                            v-model.number="budget"
                            type="number"
                            min="0"
                            step="1"
                            class="field"
                            :placeholder="String(effective.monthly_budget_usd)"
                            :disabled="busy !== null"
                        />
                    </AppField>
                </div>

                <div class="flex justify-end">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :loading="busy === 'prefs'"
                        :disabled="busy !== null"
                        @click="savePreferences"
                    >
                        Save preferences
                    </AppButton>
                </div>
            </div>

            <!-- Feedback -->
            <p
                v-if="error"
                class="flex items-start gap-2 rounded-lg border border-critical/30 bg-critical-soft px-3.5 py-2.5 text-xs text-critical"
            >
                <ShieldAlert class="mt-0.5 size-4 shrink-0" />
                {{ error }}
            </p>
            <p
                v-else-if="notice"
                class="flex items-start gap-2 rounded-lg border border-positive/30 bg-positive-soft px-3.5 py-2.5 text-xs text-positive"
            >
                <ShieldCheck class="mt-0.5 size-4 shrink-0" />
                {{ notice }}
            </p>

            <p class="flex items-start gap-1.5 text-[0.6875rem] leading-relaxed text-ink-subtle">
                <Info class="mt-0.5 size-3 shrink-0" />
                The key is encrypted with the application key before it is written, is never returned by any
                endpoint, and is never written to a log. Removing it does not break anything — AI features fall
                back to the built-in implementations.
            </p>
        </div>
    </AppCard>
</template>
