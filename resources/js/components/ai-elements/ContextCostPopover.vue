<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { formatUsdDisplay, nanoToUsdString, type LiveCostEstimate } from '@/lib/pricing';
import type { PricingConfig } from '@/types';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    estimate: LiveCostEstimate;
    pricingConfig: PricingConfig;
    promptLength: number;
    size: string;
    quality: string;
    canAfford: boolean;
}>();

const open = ref(false);
let hoverCloseTimer: ReturnType<typeof setTimeout> | null = null;

const maxChars = 4000;
const progressRatio = computed(() => Math.min(1, props.promptLength / maxChars));
const circumference = 2 * Math.PI * 7;
const strokeDashoffset = computed(() => circumference * (1 - progressRatio.value));

function handleMouseEnter(): void {
    if (hoverCloseTimer) {
        clearTimeout(hoverCloseTimer);
        hoverCloseTimer = null;
    }
    open.value = true;
}

function handleMouseLeave(): void {
    hoverCloseTimer = setTimeout(() => {
        open.value = false;
    }, 180);
}

onBeforeUnmount(() => {
    if (hoverCloseTimer) {
        clearTimeout(hoverCloseTimer);
        hoverCloseTimer = null;
    }
});
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-2 rounded-full border px-2.5 text-xs transition-colors hover:bg-accent"
                :class="canAfford ? 'border-input bg-background text-foreground' : 'border-destructive/50 bg-destructive/10 text-destructive'"
                @mouseenter="handleMouseEnter"
                @mouseleave="handleMouseLeave"
            >
                <!-- Circular Prompt Token Gauge -->
                <svg class="size-4 -rotate-90" viewBox="0 0 18 18" aria-hidden="true">
                    <circle cx="9" cy="9" r="7" fill="none" stroke="currentColor" stroke-width="2" class="opacity-20" />
                    <circle
                        cx="9"
                        cy="9"
                        r="7"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        :stroke-dasharray="circumference"
                        :stroke-dashoffset="strokeDashoffset"
                    />
                </svg>

                <span class="font-mono font-semibold"> Est. {{ formatUsdDisplay(estimate.expectedUserCostNano) }} </span>
                <span class="hidden font-mono text-[10px] text-muted-foreground sm:inline">
                    ({{ formatUsdDisplay(estimate.minUserCostNano) }}–{{ formatUsdDisplay(estimate.maxUserCostNano) }})
                </span>
            </button>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                side="top"
                align="end"
                :side-offset="8"
                class="z-50 w-80 rounded-xl border bg-popover p-4 text-popover-foreground shadow-lg outline-none sm:w-96"
                @mouseenter="handleMouseEnter"
                @mouseleave="handleMouseLeave"
            >
                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold">Upfront Cost & Token Context</p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ promptLength.toLocaleString() }} / {{ maxChars.toLocaleString() }} prompt chars ·
                                {{ estimate.estimatedTextTokens }} text tok
                            </p>
                        </div>
                        <Badge variant="outline" class="font-mono text-[10px]"> +{{ pricingConfig.markup_percent }}% Markup </Badge>
                    </div>

                    <Separator />

                    <!-- Itemized Token & Rate Breakdown -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span>
                                Input Text
                                <span class="font-mono text-muted-foreground"> ({{ estimate.minTextTokens }}–{{ estimate.maxTextTokens }} tok) </span>
                            </span>
                            <span class="font-mono text-muted-foreground">${{ pricingConfig.rates.input_text_tokens }}/1M</span>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <span>
                                Input Image
                                <span class="font-mono text-muted-foreground">
                                    ({{ estimate.refImageCount }} × {{ pricingConfig.tokens_per_reference_image }} =
                                    {{ estimate.inputImageTokens }} tok)
                                </span>
                            </span>
                            <span class="font-mono text-muted-foreground">${{ pricingConfig.rates.input_image_tokens }}/1M</span>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <span>
                                Output ({{ size }})
                                <span class="font-mono text-muted-foreground">({{ estimate.outputTokens.toLocaleString() }} tok)</span>
                            </span>
                            <span class="font-mono text-muted-foreground">${{ pricingConfig.rates.output_tokens }}/1M</span>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <span>
                                Reasoning ({{ quality }})
                                <span class="font-mono text-muted-foreground">
                                    ({{ estimate.minReasoningTokens }}–{{ estimate.maxReasoningTokens }} tok)
                                </span>
                            </span>
                            <span class="font-mono text-muted-foreground">${{ pricingConfig.rates.reasoning_tokens }}/1M</span>
                        </div>
                    </div>

                    <Separator />

                    <!-- Price Calculation -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between text-muted-foreground">
                            <span>Provider Base Cost (Expected)</span>
                            <span class="font-mono">${{ nanoToUsdString(estimate.expectedProviderCostNano) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-muted-foreground">
                            <span>Platform Markup (+{{ pricingConfig.markup_percent }}%)</span>
                            <span class="font-mono">${{ nanoToUsdString(estimate.markupNano) }}</span>
                        </div>
                        <div class="flex items-center justify-between font-medium text-foreground">
                            <span>Estimated Midpoint</span>
                            <span class="font-mono">
                                {{ formatUsdDisplay(estimate.expectedUserCostNano) }} (${{ nanoToUsdString(estimate.expectedUserCostNano) }})
                            </span>
                        </div>
                        <div class="flex items-center justify-between font-medium" :class="canAfford ? 'text-foreground' : 'text-destructive'">
                            <span>Pre-auth Max Hold</span>
                            <span class="font-mono">
                                {{ formatUsdDisplay(estimate.maxUserCostNano) }} (${{ nanoToUsdString(estimate.maxUserCostNano) }})
                            </span>
                        </div>
                    </div>

                    <p class="text-[11px] leading-relaxed text-muted-foreground">
                        Settled to exact provider token usage on completion. Failed generations cost <strong class="text-foreground">$0.00</strong>.
                    </p>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
