import type { SharedProps } from './index';

declare module '@inertiajs/core' {
    interface PageProps extends SharedProps {}
}

export {};
