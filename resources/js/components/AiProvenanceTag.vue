<script setup lang="ts">
import { computed } from 'vue';
import { Sparkles, CircuitBoard, Info } from 'lucide-vue-next';
import type { AiProvenance } from '@/types';

/**
 * Says who wrote the text next to it.
 *
 * This tag is not decoration. A user reading a sentence about their own
 * business deserves to know whether a language model produced it, whether it
 * came from a cache, and whether the system quietly fell back because a budget
 * ran out. Presenting model output and template output identically is how
 * these features lose people's trust the first time one of them is wrong.
 */
const props = defineProps<{ ai: AiProvenance }>();

const reason = computed(() => {
    switch (props.ai.degraded_reason) {
        case 'no_api_key':
            return 'No API key configured, so this was written by the built-in fallback.';
        case 'monthly_budget_reached':
            return 'The monthly AI budget is spent. Falling back until next month.';
        case 'rate_limited':
            return 'Too many AI requests this minute. Falling back.';
        case 'api_error':
            return 'The AI request failed, so the fallback answered instead.';
        default:
            return null;
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-[0.6875rem] text-ink-subtle">
        <template v-if="ai.driver === 'claude'">
            <Sparkles class="size-3 text-brand" aria-hidden="true" />
            <span>Written by {{ ai.model ?? 'Claude' }}</span>
            <span v-if="ai.cached" class="text-ink-subtle">· cached</span>
        </template>

        <template v-else>
            <CircuitBoard class="size-3" aria-hidden="true" />
            <span>Built-in fallback</span>
            <span
                v-if="reason"
                class="inline-flex items-center"
                :title="reason"
            >
                <Info class="size-3" aria-hidden="true" />
                <span class="sr-only">{{ reason }}</span>
            </span>
        </template>
    </span>
</template>
