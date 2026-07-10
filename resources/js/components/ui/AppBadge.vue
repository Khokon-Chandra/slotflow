<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'brand' | 'positive' | 'warning' | 'critical';
        size?: 'sm' | 'md';
    }>(),
    { tone: 'neutral', size: 'md' },
);

const classes = computed(() => [
    'inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap',
    props.size === 'sm' ? 'px-2 py-0.5 text-[0.6875rem]' : 'px-2.5 py-1 text-xs',
    {
        neutral: 'bg-surface-sunken text-ink-muted',
        brand: 'bg-brand-soft text-brand-strong',
        positive: 'bg-positive-soft text-positive',
        warning: 'bg-warning-soft text-warning',
        critical: 'bg-critical-soft text-critical',
    }[props.tone],
]);
</script>

<template>
    <span :class="classes"><slot /></span>
</template>
