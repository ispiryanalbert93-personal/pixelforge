<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
import type { Generation } from '@/types';
import { AlertCircle, CheckCircle2, ChevronDown, CircleDot, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        generation: Generation;
        defaultOpen?: boolean;
    }>(),
    {
        defaultOpen: false,
    },
);

const isOpen = ref(props.defaultOpen);

const totalTokens = computed(() => {
    const usage = props.generation.usage;
    if (!usage) {
        return 0;
    }

    return (usage.input_text_tokens ?? 0) + (usage.input_image_tokens ?? 0) + (usage.output_tokens ?? 0) + (usage.reasoning_tokens ?? 0);
});

const summaryLabel = computed(() => {
    const gen = props.generation;

    if (gen.status === 'queued') {
        return `Queued with Pixelforge · Max hold $${gen.reserved_user_cost_usd}`;
    }

    if (gen.status === 'processing') {
        return `Generating image (${gen.size} · ${gen.quality})...`;
    }

    if (gen.status === 'failed') {
        return `Failed · Charged $0.0000 (Est. ${gen.estimated_user_cost_formatted})`;
    }

    return `Completed · Charged ${gen.actual_user_cost_formatted ?? '$0.00'} (Est. ${gen.estimated_user_cost_formatted}) · ${totalTokens.value.toLocaleString()} tokens`;
});

const pipelineSteps = computed(() => {
    const status = props.generation.status;
    const submittedToProvider = props.generation.pixelforge_id !== null;

    return [
        {
            label: 'Queued',
            done: status !== 'queued',
            active: status === 'queued',
            failed: false,
        },
        {
            label: 'Processing',
            done: status === 'succeeded' || (status === 'failed' && submittedToProvider),
            active: status === 'processing',
            failed: false,
        },
        {
            label: status === 'failed' ? 'Failed' : 'Succeeded',
            done: status === 'succeeded',
            active: false,
            failed: status === 'failed',
        },
    ];
});
</script>

<template>
    <Collapsible v-model:open="isOpen" class="w-full rounded-xl border bg-muted/30 text-xs">
        <CollapsibleTrigger class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left transition-colors hover:bg-muted/50">
            <div class="flex min-w-0 items-center gap-2">
                <Loader2
                    v-if="generation.status === 'queued' || generation.status === 'processing'"
                    class="size-3.5 shrink-0 animate-spin text-blue-600 dark:text-blue-400"
                />
                <CheckCircle2 v-else-if="generation.status === 'succeeded'" class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <AlertCircle v-else class="size-3.5 shrink-0 text-destructive" />

                <span class="truncate font-medium text-foreground">
                    {{ summaryLabel }}
                </span>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <Badge
                    v-if="generation.status === 'failed'"
                    variant="outline"
                    class="h-5 border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0 text-[10px] text-emerald-700 dark:text-emerald-300"
                >
                    $0.00 Free
                </Badge>
                <ChevronDown class="size-3.5 text-muted-foreground transition-transform duration-200" :class="{ 'rotate-180': isOpen }" />
            </div>
        </CollapsibleTrigger>

        <CollapsibleContent class="space-y-3 border-t px-3 py-3">
            <!-- Pipeline Steps -->
            <div class="flex flex-wrap items-center gap-2">
                <template v-for="(step, idx) in pipelineSteps" :key="step.label">
                    <div class="inline-flex items-center gap-1.5">
                        <CheckCircle2 v-if="step.done" class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        <Loader2 v-else-if="step.active" class="size-3.5 animate-spin text-blue-600 dark:text-blue-400" />
                        <AlertCircle v-else-if="step.failed" class="size-3.5 text-destructive" />
                        <CircleDot v-else class="size-3.5 text-muted-foreground/50" />
                        <span
                            :class="
                                step.done || step.active
                                    ? 'font-medium text-foreground'
                                    : step.failed
                                      ? 'font-medium text-destructive'
                                      : 'text-muted-foreground'
                            "
                        >
                            {{ step.label }}
                        </span>
                    </div>
                    <span v-if="idx < pipelineSteps.length - 1" class="text-muted-foreground">→</span>
                </template>
            </div>

            <Separator />

            <!-- Upfront Estimated vs Actual Charged -->
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="space-y-0.5">
                    <p class="text-[11px] text-muted-foreground">Upfront Estimate</p>
                    <p class="font-mono font-medium">${{ generation.estimated_user_cost_usd }}</p>
                    <p class="font-mono text-[10px] text-muted-foreground">
                        Range: ${{ generation.estimated_min_user_cost_usd }} – ${{ generation.estimated_max_user_cost_usd }}
                    </p>
                </div>

                <div class="space-y-0.5">
                    <p class="text-[11px] text-muted-foreground">Actual Charged</p>
                    <template v-if="generation.status === 'succeeded'">
                        <p class="font-mono font-semibold text-foreground">
                            {{ generation.actual_user_cost_formatted }} (${{ generation.actual_user_cost_usd }})
                        </p>
                    </template>
                    <template v-else-if="generation.status === 'failed'">
                        <p class="font-mono font-semibold text-emerald-600 dark:text-emerald-400">$0.000000000 ($0.00 Free)</p>
                        <p class="text-[10px] text-muted-foreground">Held credits released</p>
                    </template>
                    <template v-else>
                        <p class="font-mono font-medium text-amber-700 dark:text-amber-300">
                            Pending (Hold: ${{ generation.reserved_user_cost_usd }})
                        </p>
                    </template>
                </div>
            </div>

            <!-- Token Usage -->
            <div v-if="generation.usage" class="space-y-1.5">
                <p class="text-[11px] text-muted-foreground">Actual Token Usage</p>
                <div class="flex flex-wrap gap-1.5">
                    <Badge variant="secondary" class="font-mono text-[10px]"> Input Text: {{ generation.usage.input_text_tokens ?? 0 }} </Badge>
                    <Badge variant="secondary" class="font-mono text-[10px]"> Input Image: {{ generation.usage.input_image_tokens ?? 0 }} </Badge>
                    <Badge variant="secondary" class="font-mono text-[10px]"> Output: {{ generation.usage.output_tokens ?? 0 }} </Badge>
                    <Badge variant="secondary" class="font-mono text-[10px]"> Reasoning: {{ generation.usage.reasoning_tokens ?? 0 }} </Badge>
                </div>
            </div>

            <div v-if="generation.pixelforge_id" class="font-mono text-[10px] text-muted-foreground">
                Pixelforge ID: {{ generation.pixelforge_id }}
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
