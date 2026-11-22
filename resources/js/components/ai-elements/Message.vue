<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { statusBadgeClass } from '@/lib/generation';
import { formatDateTime } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import type { GenerationStatusValue } from '@/types';
import { Sparkles, User as UserIcon } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        align?: 'start' | 'end';
        author?: string;
        timestamp?: string | null;
        status?: GenerationStatusValue;
        class?: HTMLAttributes['class'];
    }>(),
    {
        align: 'start',
    },
);
</script>

<template>
    <div :class="cn('flex w-full gap-3', props.align === 'end' ? 'justify-end' : 'justify-start', props.class)">
        <!-- Assistant Avatar -->
        <div
            v-if="props.align === 'start'"
            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border bg-primary/10 text-primary"
        >
            <Sparkles class="size-4" />
        </div>

        <div :class="cn('flex max-w-[88%] flex-col gap-1.5 sm:max-w-[78%]', props.align === 'end' ? 'items-end' : 'items-start')">
            <div v-if="author || timestamp || status" class="flex flex-wrap items-center gap-2 px-1 text-[11px] text-muted-foreground">
                <span v-if="author" class="font-medium text-foreground">{{ author }}</span>
                <Badge v-if="status" variant="outline" :class="statusBadgeClass(status)" class="h-5 px-1.5 py-0 text-[10px] capitalize">
                    {{ status }}
                </Badge>
                <span v-if="timestamp">{{ formatDateTime(timestamp) }}</span>
            </div>

            <slot />
        </div>

        <!-- User Avatar -->
        <div
            v-if="props.align === 'end'"
            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border bg-muted text-muted-foreground"
        >
            <UserIcon class="size-4" />
        </div>
    </div>
</template>
