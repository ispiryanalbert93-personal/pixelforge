<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    AlertCircle,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    Loader2
} from 'lucide-vue-next';
import type { WalletTransaction, WalletTransactionType } from '@/types';
import { formatDateTime, formatSignedUsd } from '@/lib/pricing';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';


interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const transactions = ref<WalletTransaction[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

let abortController: AbortController | null = null;

async function fetchTransactions(pageNumber = 1): Promise<void> {
    abortController?.abort();
    const controller = new AbortController();
    abortController = controller;

    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(route('wallet.index', { page: pageNumber }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        const payload = (await response.json()) as {
            data: WalletTransaction[];
            meta: PaginationMeta;
        };

        transactions.value = payload.data ?? [];
        meta.value = payload.meta ?? null;
    } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') {
            return;
        }

        error.value = 'Unable to load wallet history. Please try again.';
    } finally {
        if (abortController === controller) {
            abortController = null;
            loading.value = false;
        }
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            void fetchTransactions(1);
        } else {
            abortController?.abort();
            abortController = null;
        }
    },
);

onBeforeUnmount(() => {
    abortController?.abort();
});

function transactionBadgeClass(type: WalletTransactionType): string {
    switch (type) {
        case 'topup':
            return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'hold':
            return 'border-amber-500/30 bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'charge':
            return 'border-blue-500/30 bg-blue-500/15 text-blue-700 dark:text-blue-300';
        case 'release':
            return 'border-slate-500/30 bg-slate-500/15 text-slate-700 dark:text-slate-300';
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(val) => emit('update:open', val)">
        <DialogContent class="max-h-[85vh] max-w-3xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Wallet History</DialogTitle>
                <DialogDescription> Top-ups, generation holds, settled charges, and released holds. </DialogDescription>
            </DialogHeader>

            <div v-if="loading && transactions.length === 0" class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                <Loader2 class="size-4 animate-spin" />
                <span>Loading wallet history...</span>
            </div>

            <div v-else-if="error" class="flex flex-col items-center justify-center gap-3 py-8 text-center">
                <div class="flex items-center gap-2 text-sm text-destructive">
                    <AlertCircle class="size-4" />
                    <span>{{ error }}</span>
                </div>
                <Button type="button" variant="outline" size="sm" @click="fetchTransactions(meta?.current_page ?? 1)">Retry</Button>
            </div>

            <Empty v-else-if="transactions.length === 0" class="border border-dashed py-8">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <CreditCard class="size-5 text-muted-foreground" />
                    </EmptyMedia>
                    <EmptyTitle class="text-base">No wallet activity yet</EmptyTitle>
                    <EmptyDescription>Top up your wallet or submit a generation to view transactions.</EmptyDescription>
                </EmptyHeader>
            </Empty>

            <template v-else>
                <div :class="{ 'pointer-events-none opacity-60 transition-opacity': loading }">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Type</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Balance After</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead class="text-right">Timestamp</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="transaction in transactions" :key="transaction.id">
                                <TableCell>
                                    <Badge variant="outline" :class="transactionBadgeClass(transaction.type)" class="capitalize">
                                        {{ transaction.type }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="font-mono text-xs font-medium">
                                    <span :class="transaction.amount_nanodollars >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-foreground'">
                                        {{ transaction.amount_nanodollars > 0 ? '+' : '' }}{{ transaction.amount_formatted }}
                                    </span>
                                    <div class="text-[10px] text-muted-foreground">
                                        {{ formatSignedUsd(transaction.amount_nanodollars, transaction.amount_usd) }}
                                    </div>
                                </TableCell>
                                <TableCell class="font-mono text-xs"> ${{ transaction.balance_after_usd }} </TableCell>
                                <TableCell class="max-w-[220px] text-xs text-muted-foreground">
                                    {{ transaction.description }}
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-right text-xs text-muted-foreground">
                                    {{ formatDateTime(transaction.created_at) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                    <span>
                        Showing {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} of {{ meta.total }} · Page {{ meta.current_page }} of {{ meta.last_page }}
                    </span>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="loading || meta.current_page <= 1"
                            @click="fetchTransactions(meta.current_page - 1)"
                        >
                            <ChevronLeft class="size-4" />
                            Previous
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="loading || meta.current_page >= meta.last_page"
                            @click="fetchTransactions(meta.current_page + 1)"
                        >
                            Next
                            <ChevronRight class="size-4" />
                        </Button>
                    </div>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
