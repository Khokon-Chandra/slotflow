/**
 * Thin wrapper over the JSON API.
 *
 * The admin panel and the public booking flow both call /api/v1 rather than
 * bespoke Inertia endpoints. That is deliberate: it keeps one API rather than
 * two, and it means every response shape is exercised by the product itself,
 * not only by the test suite.
 */

const csrf = (): string =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export interface ApiError {
    code: string;
    message: string;
    fields?: Record<string, string[]>;
    context?: Record<string, unknown>;
}

export class ApiRequestError extends Error {
    constructor(
        public readonly status: number,
        public readonly error: ApiError,
    ) {
        super(error.message);
        this.name = 'ApiRequestError';
    }
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        // Every endpoint returns the same envelope, so one branch handles
        // validation failures, 409 conflicts and 500s alike.
        throw new ApiRequestError(
            response.status,
            payload?.error ?? { code: 'unknown_error', message: 'Something went wrong.' },
        );
    }

    return payload as T;
}

export const api = {
    get: <T>(path: string) => request<T>('GET', path),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};

/** Builds a /api/v1 URL with query parameters, skipping empty ones. */
export function apiUrl(path: string, params: Record<string, string | number | null | undefined> = {}): string {
    const url = new URL(`/api/v1${path}`, window.location.origin);

    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }

    return url.pathname + url.search;
}
