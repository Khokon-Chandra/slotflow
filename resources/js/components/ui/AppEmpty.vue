<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="mb-3 flex size-11 items-center justify-center rounded-full bg-surface-sunken text-ink-subtle">
            <slot name="icon" />
        </div>
        <p class="text-sm font-medium text-ink">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-sm text-xs text-ink-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
