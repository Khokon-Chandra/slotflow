<script setup lang="ts">
defineProps<{
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
    for?: string;
}>();
</script>

<template>
    <div class="space-y-1.5">
        <label :for="$props.for" class="block text-xs font-medium text-ink-muted">
            {{ label }}
            <span v-if="required" class="text-critical" aria-hidden="true">*</span>
        </label>

        <slot />

        <!-- Hint and error occupy the same slot: showing both at once makes
             people read neither. -->
        <p v-if="error" class="text-xs text-critical">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-ink-subtle">{{ hint }}</p>
    </div>
</template>
