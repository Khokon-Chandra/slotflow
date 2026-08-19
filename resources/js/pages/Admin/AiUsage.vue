<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Sparkles, CircuitBoard, Gauge, Wallet, Timer } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppEmpty from '@/components/ui/AppEmpty.vue';
import StatTile from '@/components/StatTile.vue';

const props = defineProps<{
    days: number;
    canManageCredentials: boolean;
    byTask: {
        task: string;
        task_label: string;
        driver: string;
        calls: number;
        cost_usd: number;
        input_tokens: number;
        output_tokens: number;
        avg_latency_ms: number;
        cache_hits: number;
        failures: number;
    }[];
    recent: {
        id: number;
        task: string;
        driver: string;
        model: string | null;
        latency_ms: number;
        cost_usd: number;
        cached: boolean;
        succeeded: boolean;
        failure_reason: string | null;
        created_at: string | null;
    }[];
    budget: { monthly_usd: number; spent_this_month_usd: number };
    config: {
        driver: string;
        provider: string | null;
        model: string | null;
        effort: string;
        cache_ttl: number;
        key_source: string;
        tracks_spend: boolean;
    };
}>();

const totals = computed(() => {
    const calls = props.byTask.reduce((sum, row) => sum + row.calls, 0);
    const cacheHits = props.byTask.reduce((sum, row) => sum + row.cache_hits, 0);
    const failures = props.byTask.reduce((sum, row) => sum + row.failures, 0);
    const cost = props.byTask.reduce((sum, row) => sum + row.cost_usd, 0);

    return {
        calls,
        cacheHits,
        failures,
        cost,
        cacheRate: calls === 0 ? 0 : Math.round((cacheHits / calls) * 100),
    };
});

const budgetUsed = computed(() =>
    props.budget.monthly_usd === 0
        ? 0
        : Math.min(100, (props.budget.spent_this_month_usd / props.budget.monthly_usd) * 100),
);


const usd = (value: number): string => (value === 0 ? '$0.00' : `$${value.toFixed(value < 0.01 ? 5 : 2)}`);

const when = (iso: string | null): string =>
    iso === null
        ? '—'
        : new Intl.DateTimeFormat(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          }).format(new Date(iso));
</script>

<template>
    <Head title="AI usage" />

    <AdminLayout title="AI usage" :subtitle="`Last ${days} days`">
        <div class="space-y-6">
            <!-- Why this page exists -->
            <div class="rounded-lg border border-line bg-surface px-4 py-3">
                <p class="text-xs leading-relaxed text-ink-muted">
                    Every AI call this workspace makes is recorded: which task, which driver, how many
                    tokens, how long, what it cost, and whether it fell back. A feature you cannot
                    budget for or explain is a feature you cannot keep — so this page exists before
                    the spend does.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Calls" :value="String(totals.calls)" :caption="`${days} days`">
                    <template #icon><Sparkles class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="Spend"
                    :value="usd(totals.cost)"
                    :caption="`Budget ${usd(budget.monthly_usd)} / month`"
                >
                    <template #icon><Wallet class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="Served from cache"
                    :value="`${totals.cacheRate}%`"
                    :caption="`${totals.cacheHits} of ${totals.calls} calls`"
                    :tone="totals.cacheRate > 30 ? 'positive' : 'neutral'"
                >
                    <template #icon><Gauge class="size-4 text-ink-subtle" /></template>
                </StatTile>

                <StatTile
                    label="Fell back"
                    :value="String(totals.failures)"
                    caption="Answered by the built-in fallback"
                    :tone="totals.failures > 0 ? 'warning' : 'neutral'"
                >
                    <template #icon><CircuitBoard class="size-4 text-ink-subtle" /></template>
                </StatTile>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                <AppCard title="By task" :padded="false">
                    <AppEmpty
                        v-if="byTask.length === 0"
                        title="No AI calls recorded"
                        description="Use the booking assistant or open the dashboard to generate some."
                    >
                        <template #icon><Sparkles class="size-5" /></template>
                    </AppEmpty>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-2xl text-sm">
                            <thead>
                                <tr class="border-b border-line text-left text-xs text-ink-subtle">
                                    <th scope="col" class="px-4 py-2.5 font-medium">Task</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Driver</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Calls</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Tokens in/out</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Avg latency</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="row in byTask" :key="`${row.task}-${row.driver}`">
                                    <td class="px-4 py-3 text-ink">{{ row.task_label }}</td>
                                    <td class="px-4 py-3">
                                        <AppBadge :tone="row.driver === 'heuristic' ? 'neutral' : 'brand'" size="sm">
                                            {{ row.driver }}
                                        </AppBadge>
                                    </td>
                                    <td class="tnum px-4 py-3 text-right text-ink">{{ row.calls }}</td>
                                    <td class="tnum px-4 py-3 text-right text-ink-muted">
                                        {{ row.input_tokens.toLocaleString() }} /
                                        {{ row.output_tokens.toLocaleString() }}
                                    </td>
                                    <td class="tnum px-4 py-3 text-right text-ink-muted">{{ row.avg_latency_ms }} ms</td>
                                    <td class="tnum px-4 py-3 text-right text-ink">{{ usd(row.cost_usd) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </AppCard>

                <div class="space-y-6">
                    <AppCard title="This month's budget">
                        <div class="space-y-3">
                            <div class="flex items-baseline justify-between">
                                <span class="tnum text-2xl font-semibold text-ink">
                                    {{ usd(budget.spent_this_month_usd) }}
                                </span>
                                <span class="tnum text-xs text-ink-subtle">of {{ usd(budget.monthly_usd) }}</span>
                            </div>

                            <div class="h-2 overflow-hidden rounded-full bg-surface-sunken">
                                <div
                                    class="h-full rounded-full transition-[width] duration-500"
                                    :class="budgetUsed > 80 ? 'bg-warning' : 'bg-brand'"
                                    :style="{ width: `${Math.max(budgetUsed, 1)}%` }"
                                />
                            </div>

                            <p class="text-[0.6875rem] leading-relaxed text-ink-subtle">
                                Crossing the ceiling does not break anything — the app stops calling the
                                API and serves the built-in fallback until the month rolls over.
                                A degraded answer beats an unbounded bill.
                            </p>
                        </div>
                    </AppCard>

                    <AppCard title="Configuration">
                        <template v-if="canManageCredentials" #actions>
                            <Link href="/admin/ai-providers" class="text-xs text-brand hover:underline">
                                Manage providers
                            </Link>
                        </template>

                        <dl class="space-y-2 text-xs">
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Driver</dt>
                                <dd class="font-mono text-ink">{{ config.driver }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Model</dt>
                                <dd class="font-mono text-ink">{{ config.model }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Provider</dt>
                                <dd class="font-mono text-ink">{{ config.provider ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Key</dt>
                                <dd class="font-mono text-ink">
                                    {{
                                        config.key_source === 'workspace'
                                            ? 'this workspace'
                                            : config.key_source === 'platform'
                                              ? 'platform'
                                              : 'none'
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Effort</dt>
                                <dd class="font-mono text-ink">{{ config.effort }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-subtle">Cache TTL</dt>
                                <dd class="tnum font-mono text-ink">{{ config.cache_ttl }}s</dd>
                            </div>
                        </dl>
                    </AppCard>
                </div>
            </div>

            <AppCard title="Recent calls" :padded="false">
                <ul class="divide-y divide-line">
                    <li
                        v-for="row in recent"
                        :key="row.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-xs"
                    >
                        <span class="tnum w-24 shrink-0 text-ink-subtle">{{ when(row.created_at) }}</span>
                        <span class="min-w-32 flex-1 text-ink">{{ row.task }}</span>

                        <AppBadge :tone="row.driver === 'heuristic' ? 'neutral' : 'brand'" size="sm">
                            {{ row.model ?? row.driver }}
                        </AppBadge>

                        <AppBadge v-if="row.cached" tone="positive" size="sm">cached</AppBadge>
                        <AppBadge v-if="!row.succeeded" tone="critical" size="sm">
                            {{ row.failure_reason }}
                        </AppBadge>
                        <AppBadge
                            v-else-if="row.failure_reason && row.driver === 'heuristic'"
                            tone="warning"
                            size="sm"
                        >
                            {{ row.failure_reason }}
                        </AppBadge>

                        <span class="tnum inline-flex w-20 items-center justify-end gap-1 text-ink-subtle">
                            <Timer class="size-3" />
                            {{ row.latency_ms }} ms
                        </span>
                        <span class="tnum w-16 text-right text-ink-muted">{{ usd(row.cost_usd) }}</span>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
