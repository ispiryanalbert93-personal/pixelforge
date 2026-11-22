<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        align?: 'start' | 'end';
        class?: HTMLAttributes['class'];
    }>(),
    {
        align: 'start',
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'rounded-2xl px-4 py-3 text-sm leading-relaxed',
                props.align === 'end' ? 'bg-primary text-primary-foreground shadow-sm' : 'border bg-card text-card-foreground shadow-sm',
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
