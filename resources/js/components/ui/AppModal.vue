<script setup lang="ts">
import { watch, onUnmounted } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps<{ open: boolean; title: string; wide?: boolean }>();
const emit = defineEmits<{ close: [] }>();

// Escape closes, and the page behind does not scroll while a dialog is open.
// Both are things people expect and notice only when they are missing.
const onKey = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') emit('close');
};

watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
        open
            ? window.addEventListener('keydown', onKey)
            : window.removeEventListener('keydown', onKey);
    },
);

onUnmounted(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 overflow-y-auto">
                <div
                    class="fixed inset-0 bg-ink/40 backdrop-blur-[2px]"
                    @click="emit('close')"
                />

                <div class="relative flex min-h-full items-end justify-center p-4 sm:items-center">
                    <div
                        role="dialog"
                        aria-modal="true"
                        :aria-label="title"
                        class="panel relative w-full shadow-xl"
                        :class="wide ? 'max-w-3xl' : 'max-w-lg'"
                    >
                        <header class="flex items-center justify-between border-b border-line px-5 py-4">
                            <h2 class="text-sm font-semibold text-ink">{{ title }}</h2>
                            <button
                                type="button"
                                class="rounded-md p-1 text-ink-subtle transition hover:bg-surface-sunken hover:text-ink"
                                aria-label="Close"
                                @click="emit('close')"
                            >
                                <X class="size-4" />
                            </button>
                        </header>

                        <div class="max-h-[70vh] overflow-y-auto p-5">
                            <slot />
                        </div>

                        <footer
                            v-if="$slots.footer"
                            class="flex justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3"
                        >
                            <slot name="footer" />
                        </footer>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
