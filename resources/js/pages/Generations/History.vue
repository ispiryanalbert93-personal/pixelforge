<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ImageIcon, Plus, RefreshCw, Sparkles } from 'lucide-vue-next';
import type {
    BreadcrumbItem,
    GenerationHistoryRow,
    SharedData
} from '@/types';
import type {
    PaginatedPayloadMeta,
    StatusFilterOption
} from '@/types/pagination';
import { useGenerationRealtime } from '@/composables/useGenerationRealtime';
import { createGenerationColumns } from '@/columns/generations';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle
} from '@/components/ui/empty';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import ImagePreviewOverlay from '@/components/ImagePreviewOverlay.vue';


const props = defineProps<{
    generations: {
        data: GenerationHistoryRow[];
        meta: PaginatedPayloadMeta;
    };
    has_active_generations: boolean;
    filters: Record<string, any>;
    statusOptions: StatusFilterOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generation History',
        href: '/generations/history',
    },
];

const page = usePage<SharedData>();
const isReloading = ref(false);
const previewGeneration = ref<GenerationHistoryRow | null>(null);

const previewOpen = computed({
    get: () => previewGeneration.value !== null,
    set: (open: boolean) => {
        if (!open) {
            previewGeneration.value = null;
        }
    },
});

const columns = createGenerationColumns((generation) => {
    previewGeneration.value = generation;
});

const { generations } = useGenerationRealtime(
    () => props.generations.data,
    undefined,
    () => props.has_active_generations,
    () => page.props.auth?.user?.id,
);

const showEmptyState = computed(() => {
    const status = props.filters?.status ?? 'all';
    const search = props.filters?.search ?? '';

    return status === 'all' && search === '' && props.generations.meta.total === 0;
});

function refreshHistory(): void {
    isReloading.value = true;
    router.reload({
        only: ['generations', 'auth'],
        onFinish: () => {
            isReloading.value = false;
        },
    });
}
</script>

<template>
    <Head title="Generation History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex w-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Page Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight">Generation History</h1>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Track live generation status, inspect token usage, and compare upfront estimates against exact settled charges.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" :disabled="isReloading" @click="refreshHistory">
                        <RefreshCw class="size-3.5" :class="{ 'animate-spin': isReloading }" />
                        Refresh
                    </Button>
                    <Button size="sm" as-child>
                        <Link :href="route('dashboard')">
                            <Plus class="size-3.5" />
                            New Generation
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Empty State when customer has never generated any image -->
            <Empty v-if="showEmptyState" class="border border-dashed py-14">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <ImageIcon class="size-5 text-muted-foreground" />
                    </EmptyMedia>
                    <EmptyTitle>No generations yet</EmptyTitle>
                    <EmptyDescription> Head to the Image Studio to write a prompt and generate your first image. </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button as-child>
                        <Link :href="route('dashboard')">
                            <Sparkles class="size-4" />
                            Open Image Studio
                        </Link>
                    </Button>
                </EmptyContent>
            </Empty>

            <!-- Server-Side DataTable -->
            <DataTable
                v-else
                :columns="columns"
                :data="generations"
                :page-count="props.generations.meta.last_page"
                :row-count="props.generations.meta.total"
                :current-page="props.generations.meta.current_page"
                :filters="filters"
                :filter-options="statusOptions"
                :only="['generations', 'filters']"
                search-placeholder="Search by Pixelforge ID..."
                filter-style="tabs"
                :is-loading="isReloading"
            />

            <ImagePreviewOverlay
                v-model:open="previewOpen"
                :image-url="previewGeneration?.image_url ?? null"
                :prompt="previewGeneration?.prompt"
                :filename="previewGeneration?.pixelforge_id ?? undefined"
            />
        </div>
    </AppLayout>
</template>
