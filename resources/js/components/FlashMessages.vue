<script setup lang="ts">
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedData } from '@/types';
import { toast } from '@/components/ui/sonner';

const page = usePage<SharedData>();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="false" />
</template>
