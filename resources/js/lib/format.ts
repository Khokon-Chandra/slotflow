/**
 * Formatting helpers.
 *
 * The server sends money as integer minor units and times as ISO-8601
 * instants; turning those into something a human reads is the browser's job,
 * because only the browser knows the viewer's locale.
 */

export function money(cents: number, currency = 'EUR', locale?: string): string {
    if (cents === 0) return 'Free';

    return new Intl.NumberFormat(locale ?? navigator.language, {
        style: 'currency',
        currency,
        minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
    }).format(cents / 100);
}

export function duration(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} hr` : `${hours} hr ${rest} min`;
}

/**
 * Render an instant in an explicit timezone.
 *
 * The timezone is a required argument, never the browser's default. A booking
 * shown in the viewer's zone when the appointment is in the salon's zone is
 * the bug this whole application is careful about.
 */
export function timeIn(iso: string, timeZone: string, locale?: string): string {
    return new Intl.DateTimeFormat(locale ?? navigator.language, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone,
    }).format(new Date(iso));
}

export function dateIn(iso: string, timeZone: string, locale?: string): string {
    return new Intl.DateTimeFormat(locale ?? navigator.language, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone,
    }).format(new Date(iso));
}

export function longDateIn(iso: string, timeZone: string, locale?: string): string {
    return new Intl.DateTimeFormat(locale ?? navigator.language, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone,
    }).format(new Date(iso));
}

export function relativeDay(isoDate: string, timeZone: string): string | null {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date());
    const tomorrow = new Intl.DateTimeFormat('en-CA', { timeZone }).format(
        new Date(Date.now() + 86_400_000),
    );

    if (isoDate === today) return 'Today';
    if (isoDate === tomorrow) return 'Tomorrow';

    return null;
}

/** The viewer's own IANA zone, used as the default booking timezone. */
export function browserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

export function percent(value: number): string {
    return `${Math.round(value)}%`;
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}
