<script setup lang="ts">
import { computed } from 'vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import type { RiskAssessment } from '@/types';

const props = defineProps<{ risk: RiskAssessment | null; showScore?: boolean }>();

const tone = computed(() => {
    switch (props.risk?.band) {
        case 'high':
            return 'critical' as const;
        case 'medium':
            return 'warning' as const;
        default:
            return 'positive' as const;
    }
});
</script>

<template>
    <AppBadge v-if="risk" :tone="tone" size="sm">
        <span
            class="size-1.5 rounded-full bg-current"
            aria-hidden="true"
        />
        {{ risk.band_label }}
        <span v-if="showScore" class="tnum opacity-70">{{ risk.score }}</span>
    </AppBadge>
    <span v-else class="text-xs text-ink-subtle">—</span>
</template>
