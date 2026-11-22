<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Check, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    prompt?: string;
    showPrompts?: boolean;
}>();

const emit = defineEmits<{
    (e: 'selectPrompt', text: string): void;
    (e: 'toggleKeyword', tag: string): void;
}>();

const page = usePage<SharedData>();

const samplePrompts = [
    'Cyberpunk rainy Tokyo alley at midnight with neon reflections',
    'Minimalist ceramic vase on oak table in warm morning sunlight',
    'Studio portrait of an astronaut holding a glowing lantern',
];

const testKeywords = [
    { tag: '[succeed]', description: 'Forces clean async completion with a generated sample image' },
    { tag: '[fail]', description: 'Simulates async failure (held credits released, $0.00 billed)' },
    { tag: '[slow]', description: 'Simulates slow processing (60–90s) to verify live status updates' },
    { tag: '[duplicate]', description: 'Simulates duplicate webhook delivery (idempotent handling)' },
    { tag: '[overloaded]', description: 'Simulates transient 503 overload / retryable provider error' },
    { tag: '[blocked]', description: 'Simulates safety/policy block (rejected & $0.00 billed)' },
];

const isDevelopment = computed(() => Boolean(page.props.is_development));

function isKeywordActive(tag: string): boolean {
    return (props.prompt ?? '').toLowerCase().includes(tag.toLowerCase());
}
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <div class="flex w-full items-center gap-1.5 overflow-x-auto pb-1">
            <!-- Sample Creative Prompts -->
            <template v-if="showPrompts !== false">
                <Button
                    v-for="sample in samplePrompts"
                    :key="sample"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-7 shrink-0 rounded-full px-3 text-xs font-normal"
                    @click="emit('selectPrompt', sample)"
                >
                    <Sparkles class="size-3 text-primary" />
                    {{ sample }}
                </Button>
            </template>

            <!-- Pixelforge Test Keywords -->
            <template v-if="isDevelopment">
                <Tooltip v-for="kw in testKeywords" :key="kw.tag">
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 shrink-0 rounded-full px-2.5 font-mono text-[11px] transition-colors"
                            :class="
                                isKeywordActive(kw.tag)
                                    ? 'shadow-xs border-indigo-500/70 bg-indigo-500/15 font-semibold text-indigo-700 hover:bg-indigo-500/25 hover:text-indigo-800 dark:border-indigo-400/70 dark:bg-indigo-500/25 dark:text-indigo-200 dark:hover:bg-indigo-500/35'
                                    : 'border-border bg-background text-muted-foreground hover:border-indigo-500/40 hover:bg-indigo-500/10 hover:text-indigo-700 dark:hover:text-indigo-300'
                            "
                            @click="emit('toggleKeyword', kw.tag)"
                        >
                            <Check v-if="isKeywordActive(kw.tag)" class="size-3" />
                            {{ kw.tag }}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p class="max-w-xs text-xs">{{ kw.description }} ({{ isKeywordActive(kw.tag) ? 'click to remove' : 'click to add' }})</p>
                    </TooltipContent>
                </Tooltip>
            </template>
        </div>
    </TooltipProvider>
</template>
