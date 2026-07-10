import { ref, readonly } from 'vue';

const STORAGE_KEY = 'slotflow-theme';

const isDark = ref(
    typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
);

export function useTheme() {
    const apply = (dark: boolean): void => {
        isDark.value = dark;
        document.documentElement.classList.toggle('dark', dark);
        localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
    };

    return {
        isDark: readonly(isDark),
        toggle: () => apply(!isDark.value),
        set: apply,
    };
}
