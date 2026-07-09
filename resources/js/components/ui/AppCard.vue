<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        subtitle?: string;
        padded?: boolean;
    }>(),
    { padded: true },
);
</script>

<template>
    <section class="panel overflow-hidden">
        <header
            v-if="title || $slots.actions"
            class="flex items-start justify-between gap-4 border-b border-line px-5 py-4"
        >
            <div class="min-w-0">
                <h2 v-if="title" class="text-sm font-semibold text-ink">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-ink-muted">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions" />
            </div>
        </header>

        <div :class="padded ? 'p-5' : ''">
            <slot />
        </div>
    </section>
</template>
