<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * One button. Variants are a closed list, not free-form classes, so a new page
 * cannot quietly invent a fifth shade of primary.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md' | 'lg';
        href?: string;
        type?: 'button' | 'submit';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', disabled: false, loading: false, block: false },
);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium',
    'transition-[background-color,border-color,color,opacity] duration-150',
    'disabled:cursor-not-allowed disabled:opacity-55',
    props.block ? 'w-full' : '',
    {
        sm: 'px-2.5 py-1.5 text-xs',
        md: 'px-3.5 py-2 text-sm',
        lg: 'px-5 py-2.5 text-[0.9375rem]',
    }[props.size],
    {
        primary: 'bg-brand text-brand-ink hover:bg-brand-strong',
        secondary: 'border border-line-strong bg-surface text-ink hover:bg-surface-sunken',
        ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
        danger: 'bg-critical text-white hover:opacity-90',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled || loading" :class="classes">
        <svg
            v-if="loading"
            class="size-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z" />
        </svg>
        <slot />
    </button>
</template>
