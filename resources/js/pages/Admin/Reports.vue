<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminReport, BreadcrumbItem, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    report: AdminReport;
}>();

const page = usePage<SharedData>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Usage Report',
        href: '/admin/reports',
    },
];

const startDate = ref(props.report.filters.start_date ?? props.report.filters.effective_start_date ?? '');
const endDate = ref(props.report.filters.end_date ?? props.report.filters.effective_end_date ?? '');

const filterErrors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const defaultStartDate = computed(() => props.report.filters.effective_start_date ?? '');
const defaultEndDate = computed(() => props.report.filters.effective_end_date ?? '');
const hasCustomFilter = computed(
    () =>
        Boolean(props.report.filters.start_date || props.report.filters.end_date) ||
        startDate.value !== defaultStartDate.value ||
        endDate.value !== defaultEndDate.value,
);
const total = computed(() => props.report.total);

watch(
    () => props.report.filters,
    (filters) => {
        startDate.value = filters.start_date ?? filters.effective_start_date ?? '';
        endDate.value = filters.end_date ?? filters.effective_end_date ?? '';
    },
);

function applyFilters(): void {
    router.get(
        route('admin.reports'),
        {
            start_date: startDate.value || undefined,
            end_date: endDate.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function resetFilters(): void {
    router.get(route('admin.reports'));
}
</script>

<template>
    <Head title="Usage Report" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header & Inline Date Filter -->
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <h1 class="text-lg font-semibold tracking-tight">Usage &amp; Profit Report</h1>
                    <p class="text-sm text-muted-foreground">
                        Customer credit spend, provider costs, and profit reconciled against Pixelforge
                        <code class="font-mono text-xs">GET /v1/usage</code>.
                    </p>
                    <p v-if="report.filters.effective_start_date && report.filters.effective_end_date" class="mt-1 text-xs text-muted-foreground">
                        Active UTC window:
                        <span class="font-mono">{{ report.filters.effective_start_date }}</span>
                        to
                        <span class="font-mono">{{ report.filters.effective_end_date }}</span>
                    </p>
                </div>

                <div class="space-y-1">
                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-2">
                        <div class="space-y-1">
                            <Label for="start_date" class="block text-[11px] text-muted-foreground">Start</Label>
                            <DatePicker id="start_date" v-model="startDate" placeholder="Start date" />
                        </div>
                        <div class="space-y-1">
                            <Label for="end_date" class="block text-[11px] text-muted-foreground">End</Label>
                            <DatePicker id="end_date" v-model="endDate" placeholder="End date" />
                        </div>
                        <Button type="submit" size="sm" class="h-8">Apply</Button>
                        <Button v-if="hasCustomFilter" type="button" variant="ghost" size="sm" class="h-8" @click="resetFilters"> Reset </Button>
                    </form>
                    <p v-if="filterErrors.start_date || filterErrors.end_date" class="text-xs text-destructive">
                        {{ filterErrors.start_date ?? filterErrors.end_date }}
                    </p>
                    <p v-else class="text-[11px] text-muted-foreground">Default: last 30 UTC days ending today · Max 92 days</p>
                </div>
            </div>

            <!-- Remote API Warning if unavailable -->
            <Alert
                v-if="!report.available && report.error"
                class="border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-200 [&>svg]:text-amber-600"
            >
                <AlertTriangle class="size-4" />
                <AlertTitle>Pixelforge Usage API Unavailable</AlertTitle>
                <AlertDescription>{{ report.error }} Showing local database figures only.</AlertDescription>
            </Alert>

            <!-- 4 KPI Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-1.5">
                        <CardTitle class="text-xs font-medium text-muted-foreground">Credit Spend</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-semibold tabular-nums">{{ total.credit_spend_formatted }}</div>
                        <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">${{ total.credit_spend_usd }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle class="text-xs font-medium text-muted-foreground">Provider Cost</CardTitle>
                            <Badge
                                v-if="report.available && total.matches_remote === true"
                                variant="outline"
                                class="gap-1 border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0 text-[10px] text-emerald-700 dark:text-emerald-300"
                            >
                                <CheckCircle2 class="size-3" />
                                Matches API
                            </Badge>
                            <Badge
                                v-else-if="report.available && total.matches_remote === false"
                                variant="outline"
                                class="gap-1 border-amber-500/40 bg-amber-500/10 px-1.5 py-0 text-[10px] text-amber-700 dark:text-amber-300"
                            >
                                <AlertTriangle class="size-3" />
                                Diff {{ total.delta_formatted }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-semibold tabular-nums">{{ total.provider_cost_formatted }}</div>
                        <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">
                            Local: ${{ total.provider_cost_usd }}
                            <span v-if="report.available"> · API: ${{ total.remote_cost_usd }}</span>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-1.5">
                        <CardTitle class="text-xs font-medium text-muted-foreground">Net Profit</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ total.profit_formatted }}
                        </div>
                        <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">${{ total.profit_usd }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-1.5">
                        <CardTitle class="text-xs font-medium text-muted-foreground">Generations</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-xl font-semibold tabular-nums">
                            {{ (total.succeeded_count + total.failed_count + total.active_count).toLocaleString() }}
                        </div>
                        <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">
                            <span class="text-emerald-600 dark:text-emerald-400">{{ total.succeeded_count }} ok</span>
                            <span> / </span>
                            <span class="text-destructive">{{ total.failed_count }} failed</span>
                            <span> / </span>
                            <span class="text-amber-600 dark:text-amber-400">{{ total.active_count }} active</span>
                            <span v-if="report.available"> · API: {{ total.remote_generations }}</span>
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- 3 Tabs: By Day (UTC), By User, By Day & User -->
            <Tabs default-value="daily" class="space-y-4">
                <TabsList>
                    <TabsTrigger value="daily">By Day (UTC)</TabsTrigger>
                    <TabsTrigger value="users">By User</TabsTrigger>
                    <TabsTrigger value="daily-by-user">By Day &amp; User</TabsTrigger>
                </TabsList>

                <!-- Tab 1: By Day (UTC) -->
                <TabsContent value="daily">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">By Day (UTC)</CardTitle>
                            <CardDescription>
                                Local billed totals grouped by UTC completion date, reconciled with
                                <code class="font-mono text-xs">GET /v1/usage</code>.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="report.days.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                                No dates found for the selected UTC date range.
                            </div>
                            <Table v-else>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>UTC Date</TableHead>
                                        <TableHead>Jobs</TableHead>
                                        <TableHead>Credit Spend</TableHead>
                                        <TableHead>Provider Cost (Local)</TableHead>
                                        <TableHead>Pixelforge /v1/usage</TableHead>
                                        <TableHead class="text-right">Profit</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="row in report.days" :key="row.date">
                                        <TableCell class="font-mono text-xs font-medium">{{ row.date }}</TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div>
                                                <span class="text-emerald-600 dark:text-emerald-400">{{ row.succeeded_count }}</span>
                                                <span class="text-muted-foreground"> / </span>
                                                <span class="text-destructive">{{ row.failed_count }}</span>
                                            </div>
                                            <div v-if="report.available" class="text-[11px] text-muted-foreground">
                                                API: {{ row.remote_generations }} · {{ row.total_tokens.toLocaleString() }} tok
                                            </div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ row.credit_spend_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.credit_spend_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ row.provider_cost_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.provider_cost_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <template v-if="report.available">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-medium">{{ row.remote_cost_formatted }}</span>
                                                    <Badge
                                                        v-if="row.matches_remote === true"
                                                        variant="outline"
                                                        class="border-emerald-500/30 bg-emerald-500/10 px-1 py-0 text-[10px] text-emerald-700 dark:text-emerald-300"
                                                    >
                                                        Match
                                                    </Badge>
                                                    <Badge
                                                        v-else-if="row.matches_remote === false"
                                                        variant="outline"
                                                        class="border-amber-500/40 bg-amber-500/10 px-1 py-0 text-[10px] text-amber-700 dark:text-amber-300"
                                                    >
                                                        Diff
                                                    </Badge>
                                                </div>
                                                <div class="text-[11px] text-muted-foreground">${{ row.remote_cost_usd }}</div>
                                            </template>
                                            <span v-else class="text-muted-foreground">—</span>
                                        </TableCell>
                                        <TableCell class="text-right font-mono text-xs tabular-nums">
                                            <div class="font-medium text-emerald-600 dark:text-emerald-400">{{ row.profit_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.profit_usd }}</div>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Tab 2: By User -->
                <TabsContent value="users">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">By User</CardTitle>
                            <CardDescription>
                                Customer credit spend, provider costs, profit, generation counts, and current wallet balances.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="report.users.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                                No customer accounts found.
                            </div>
                            <Table v-else>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Jobs</TableHead>
                                        <TableHead>Credit Spend</TableHead>
                                        <TableHead>Provider Cost</TableHead>
                                        <TableHead>Profit</TableHead>
                                        <TableHead>Balance</TableHead>
                                        <TableHead class="text-right">Reserved Hold</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="u in report.users" :key="u.id">
                                        <TableCell>
                                            <div class="font-medium">{{ u.name }}</div>
                                            <div class="text-xs text-muted-foreground">{{ u.email }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <span class="text-emerald-600 dark:text-emerald-400">{{ u.succeeded_count }} ok</span>
                                            <span class="text-muted-foreground"> / </span>
                                            <span class="text-destructive">{{ u.failed_count }} failed</span>
                                            <span class="text-muted-foreground"> / </span>
                                            <span class="text-amber-600 dark:text-amber-400">{{ u.active_count }} active</span>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ u.credit_spend_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ u.credit_spend_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ u.provider_cost_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ u.provider_cost_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium text-emerald-600 dark:text-emerald-400">{{ u.profit_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ u.profit_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div>{{ u.balance_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ u.balance_usd }}</div>
                                        </TableCell>
                                        <TableCell class="text-right font-mono text-xs tabular-nums">
                                            <div>{{ u.reserved_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ u.reserved_usd }}</div>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Tab 3: By Day & User -->
                <TabsContent value="daily-by-user">
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">By Day &amp; User</CardTitle>
                            <CardDescription> Per-user credit spend, provider costs, and net profit grouped by UTC completion date. </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="report.daily_by_user.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                                No completed generations found for the selected UTC date range.
                            </div>
                            <Table v-else>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>UTC Date</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Jobs</TableHead>
                                        <TableHead>Credit Spend</TableHead>
                                        <TableHead>Provider Cost</TableHead>
                                        <TableHead class="text-right">Profit</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="row in report.daily_by_user" :key="`${row.date}-${row.user_id}`">
                                        <TableCell class="font-mono text-xs font-medium">{{ row.date }}</TableCell>
                                        <TableCell>
                                            <div class="font-medium">{{ row.user_name }}</div>
                                            <div class="text-xs text-muted-foreground">{{ row.user_email }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <span class="text-emerald-600 dark:text-emerald-400">{{ row.succeeded_count }}</span>
                                            <span class="text-muted-foreground"> / </span>
                                            <span class="text-destructive">{{ row.failed_count }}</span>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ row.credit_spend_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.credit_spend_usd }}</div>
                                        </TableCell>
                                        <TableCell class="font-mono text-xs tabular-nums">
                                            <div class="font-medium">{{ row.provider_cost_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.provider_cost_usd }}</div>
                                        </TableCell>
                                        <TableCell class="text-right font-mono text-xs tabular-nums">
                                            <div class="font-medium text-emerald-600 dark:text-emerald-400">{{ row.profit_formatted }}</div>
                                            <div class="text-[11px] text-muted-foreground">${{ row.profit_usd }}</div>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>
    </AdminLayout>
</template>
