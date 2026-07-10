<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next';
import { useTheme } from '@/lib/theme';

const { isDark, toggle } = useTheme();
</script>

<template>
    <button
        type="button"
        class="rounded-lg p-2 text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
        :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
        @click="toggle"
    >
        <Sun v-if="isDark" class="size-4" />
        <Moon v-else class="size-4" />
    </button>
</template>
