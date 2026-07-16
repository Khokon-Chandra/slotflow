<script setup lang="ts">
import { computed } from 'vue';
import AiProvenanceTag from '@/components/AiProvenanceTag.vue';
import RiskBadge from '@/components/RiskBadge.vue';
import type { RiskAssessment } from '@/types';

/**
 * The full breakdown behind a risk badge.
 *
 * The factor list is the point. A score with no arithmetic behind it is
 * something people either over-trust or ignore; showing the additions and
 * subtractions lets the owner disagree with it, which is the only way they
 * will ever come to rely on it.
 */
const props = defineProps<{ risk: RiskAssessment }>();

const provenance = computed(() => ({
    driver: props.risk.generated_by === 'claude' ? ('claude' as const) : ('heuristic' as const),
    model: props.risk.model,
    cached: false,
    degraded_reason: null,
}));
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <RiskBadge :risk="risk" show-score />
            <span class="tnum text-xs text-ink-subtle">{{ risk.score }} / 100</span>
        </div>

        <!-- A bar, not a gauge: the number is ordinal and roughly linear, and
             a gauge implies a precision this heuristic does not have. -->
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-sunken">
            <div
                class="h-full rounded-full transition-[width] duration-500"
                :class="{
                    'bg-critical': risk.band === 'high',
                    'bg-warning': risk.band === 'medium',
                    'bg-positive': risk.band === 'low',
                }"
                :style="{ width: `${Math.max(risk.score, 3)}%` }"
            />
        </div>

        <div v-if="risk.rationale" class="rounded-lg bg-surface-sunken p-3">
            <p class="text-xs leading-relaxed text-ink">{{ risk.rationale }}</p>
            <p v-if="risk.recommended_action" class="mt-2 text-xs font-medium text-ink">
                → {{ risk.recommended_action }}
            </p>
            <div class="mt-2">
                <AiProvenanceTag :ai="provenance" />
            </div>
        </div>

        <div>
            <p class="mb-2 text-[0.6875rem] font-medium uppercase tracking-wide text-ink-subtle">
                How the score was reached
            </p>
            <ul class="divide-y divide-line text-xs">
                <li
                    v-for="factor in risk.factors"
                    :key="factor.code"
                    class="flex items-center justify-between gap-3 py-1.5"
                >
                    <span class="text-ink-muted">{{ factor.label }}</span>
                    <span
                        class="tnum shrink-0 font-medium"
                        :class="factor.points > 0 ? 'text-critical' : 'text-positive'"
                    >
                        {{ factor.points > 0 ? '+' : '' }}{{ factor.points }}
                    </span>
                </li>
            </ul>
        </div>

        <p class="text-[0.6875rem] leading-relaxed text-ink-subtle">
            The score and these factors are computed by the application and are the
            same every time for the same booking. Only the sentence above is written
            by a language model — and these weights are explainable defaults, not a
            model fitted to this business's history.
        </p>
    </div>
</template>
