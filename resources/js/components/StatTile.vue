<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        value: string;
        caption?: string;
        tone?: 'neutral' | 'positive' | 'warning' | 'critical';
    }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <div class="panel p-4">
        <div class="flex items-start justify-between gap-2">
            <p class="text-xs font-medium text-ink-muted">{{ label }}</p>
            <slot name="icon" />
        </div>
        <p
            class="tnum mt-2 text-2xl font-semibold tracking-tight"
            :class="{
                'text-ink': tone === 'neutral',
                'text-positive': tone === 'positive',
                'text-warning': tone === 'warning',
                'text-critical': tone === 'critical',
            }"
        >
            {{ value }}
        </p>
        <p v-if="caption" class="mt-1 text-xs text-ink-subtle">{{ caption }}</p>
    </div>
</template>
