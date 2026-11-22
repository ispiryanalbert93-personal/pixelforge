<script setup lang="ts">
import { ref } from 'vue';
import { CreditCard, Plus } from 'lucide-vue-next';
import { useWalletState } from '@/composables/useWalletState';
import { Button } from '@/components/ui/button';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger
} from '@/components/ui/tooltip';
import WalletHistoryDialog from '@/components/wallet/WalletHistoryDialog.vue';
import TopUpWalletDialog from '@/components/wallet/TopUpWalletDialog.vue';

const { balanceFormatted, reservedNano, reservedFormatted } = useWalletState();

const transactionsDialogOpen = ref(false);
const topupDialogOpen = ref(false);
</script>

<template>
    <div>
        <!-- Expanded Sidebar Wallet Card -->
        <div class="px-2 pb-1 group-data-[collapsible=icon]:hidden">
            <div
                role="button"
                tabindex="0"
                aria-label="Open wallet history"
                class="flex cursor-pointer items-center justify-between gap-2 rounded-xl border border-sidebar-border bg-sidebar-accent/40 p-2.5 text-left transition-colors hover:bg-sidebar-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                @click="transactionsDialogOpen = true"
                @keydown.enter.self="transactionsDialogOpen = true"
                @keydown.space.prevent.self="transactionsDialogOpen = true"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                        <CreditCard class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium text-muted-foreground">Wallet</p>
                        <p class="truncate font-mono text-sm font-semibold text-foreground">
                            {{ balanceFormatted }}
                        </p>
                        <p v-if="reservedNano > 0" class="truncate font-mono text-[10px] text-amber-600 dark:text-amber-400">
                            ({{ reservedFormatted }} held)
                        </p>
                    </div>
                </div>

                <TooltipProvider :delay-duration="150">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-7 shrink-0 rounded-lg hover:bg-background"
                                aria-label="Add funds"
                                title="Add funds"
                                @click.stop="topupDialogOpen = true"
                            >
                                <Plus class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <p class="text-xs">Top up wallet</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>

        <!-- Collapsed Icon-Only Sidebar Button -->
        <SidebarMenu class="hidden group-data-[collapsible=icon]:flex">
            <SidebarMenuItem>
                <SidebarMenuButton :tooltip="`Wallet: ${balanceFormatted}`" @click="transactionsDialogOpen = true">
                    <CreditCard />
                    <span>Wallet</span>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>

        <WalletHistoryDialog v-model:open="transactionsDialogOpen" />
        <TopUpWalletDialog v-model:open="topupDialogOpen" />
    </div>
</template>
