/**
 * Shapes shared between the Laravel controllers and the Vue pages.
 *
 * Hand-written rather than generated: the surface is small, and a type that
 * mirrors exactly what the controller sends is easier to read than a
 * 4,000-line generated file. If this grew, the answer would be to generate it
 * from the API resources — not to let it drift.
 */

export type RiskBand = 'low' | 'medium' | 'high';
export type BookingStatus = 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'no_show';
/**
 * A provider id — "anthropic", "openai", "deepseek", or whatever a workspace
 * called its custom endpoint — or "heuristic" for the built-in fallback.
 *
 * Deliberately open: the provider catalogue is configuration, so a closed
 * union here would need editing every time somebody adds one.
 */
export type AiDriver = 'heuristic' | (string & {});

export interface AiProvenance {
    driver: AiDriver;
    model: string | null;
    cached: boolean;
    degraded_reason: string | null;
}

export interface RiskFactor {
    code: string;
    label: string;
    points: number;
}

export interface RiskAssessment {
    score: number;
    band: RiskBand;
    band_label: string;
    factors: RiskFactor[];
    rationale: string | null;
    recommended_action: string | null;
    generated_by: string;
    model: string | null;
}

export interface ServiceSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    duration_minutes: number;
    buffer_minutes?: number;
    price_cents: number;
    color: string;
    staff: { id: number; name: string; title: string | null }[];
}

export interface Slot {
    starts_at: string;
    ends_at: string;
    local_starts_at: string;
    local_ends_at: string;
    local_date: string;
    local_time: string;
    staff_id: number;
    staff_name: string;
    timezone: string;
}

export interface BookingRow {
    reference: string;
    status: BookingStatus;
    status_label: string;
    source: string;
    source_label: string;
    starts_at: string;
    local_starts_at: string;
    local_ends_at: string;
    duration_minutes: number;
    price_cents: number;
    service: { name: string; color: string };
    staff: { id: number; name: string };
    customer: {
        name: string;
        email: string;
        phone: string | null;
        completed_count: number;
        no_show_count: number;
    };
    risk: RiskAssessment | null;
}

export interface DayStats {
    date: string;
    booking_count: number;
    high_risk_count: number;
    revenue_cents: number;
    currency: string;
    utilisation_percent: number;
    first_start: string | null;
    last_end: string | null;
    largest_gap_minutes: number;
    largest_gap_start: string | null;
    busiest_staff: string | null;
    quiet_staff: string | null;
    per_staff: { name: string; bookings: number; booked_minutes: number }[];
}

export interface Briefing {
    headline: string;
    bullets: { text: string; tone: 'neutral' | 'warning' | 'positive' }[];
    focus: string;
    stats: DayStats;
    ai: AiProvenance;
}

export interface SharedProps {
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            role: string;
            timezone: string;
            is_admin: boolean;
        } | null;
    };
    tenant: {
        name: string;
        slug: string;
        timezone: string;
        currency: string;
    } | null;
    ai: { live: boolean };
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}
