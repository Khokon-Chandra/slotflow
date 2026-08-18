<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, LayoutDashboard, LogOut } from 'lucide-vue-next';
import AppButton from '@/components/ui/AppButton.vue';
import { initials } from '@/lib/format';

/**
 * The signed-in person, and what they can do about it.
 *
 * This exists because the header previously branched
 * `admin ? Admin : (guest ? Sign in : nothing)` — and a signed-in customer is
 * neither an admin nor a guest, so they fell through both arms and had no way
 * to sign out at all. The account menu covers every role by construction
 * instead of by remembering to add a branch.
 */
const page = usePage();
const user = computed(() => page.props.auth.user);
const canAccessAdmin = computed(() => user.value?.is_admin === true || user.value?.role === 'staff');

const open = ref(false);
const container = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

function close(returnFocus = false): void {
    open.value = false;

    // Sending focus back to the trigger matters for keyboard users: closing a
    // menu should not drop them at the top of the document.
    if (returnFocus) trigger.value?.focus();
}

function onDocumentPointerDown(event: PointerEvent): void {
    if (!container.value?.contains(event.target as Node)) close();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') close(true);
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown);
        document.addEventListener('keydown', onKeydown);
    } else {
        document.removeEventListener('pointerdown', onDocumentPointerDown);
        document.removeEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    document.removeEventListener('keydown', onKeydown);
});

// Inertia navigates away on success, so there is no "signed out" state to
// render here — but the flag stops a double submit on a slow connection.
const signingOut = ref(false);

function signOut(): void {
    signingOut.value = true;

    router.post('/logout', {}, {
        onFinish: () => {
            signingOut.value = false;
            close();
        },
    });
}
</script>

<template>
    <AppButton v-if="!user" href="/login" variant="ghost" size="sm">Sign in</AppButton>

    <div v-else ref="container" class="relative">
        <button
            ref="trigger"
            type="button"
            class="flex items-center gap-2 rounded-lg py-1.5 pl-1.5 pr-2 text-sm transition hover:bg-surface-sunken"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open = !open"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-brand-soft text-[0.6875rem] font-semibold text-brand-strong"
                aria-hidden="true"
            >
                {{ initials(user.name) }}
            </span>
            <span class="hidden max-w-32 truncate text-ink sm:block">{{ user.name }}</span>
            <ChevronDown
                class="size-3.5 text-ink-subtle transition-transform"
                :class="open ? 'rotate-180' : ''"
                aria-hidden="true"
            />
            <span class="sr-only">Account menu</span>
        </button>

        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-75 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                role="menu"
                class="panel absolute right-0 z-50 mt-2 w-56 overflow-hidden shadow-lg"
            >
                <div class="border-b border-line px-3.5 py-3">
                    <p class="truncate text-sm font-medium text-ink">{{ user.name }}</p>
                    <p class="truncate text-xs text-ink-subtle">{{ user.email }}</p>
                    <p class="mt-1 text-[0.6875rem] capitalize text-ink-subtle">{{ user.role }}</p>
                </div>

                <div class="p-1">
                    <Link
                        v-if="canAccessAdmin"
                        href="/admin"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
                        @click="close()"
                    >
                        <LayoutDashboard class="size-4" />
                        Admin panel
                    </Link>

                    <button
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-ink-muted transition hover:bg-critical-soft hover:text-critical disabled:opacity-60"
                        :disabled="signingOut"
                        @click="signOut"
                    >
                        <LogOut class="size-4" />
                        {{ signingOut ? 'Signing out…' : 'Sign out' }}
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>
