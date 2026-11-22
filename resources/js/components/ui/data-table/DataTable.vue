<script setup lang="ts" generic="TData, TValue">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableEmpty, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn, valueUpdater } from '@/lib/utils';
import type { StatusFilterOption } from '@/types/pagination';
import { router } from '@inertiajs/vue3';
import type { ColumnDef, ColumnFiltersState, PaginationState, SortingState } from '@tanstack/vue-table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { debouncedWatch } from '@vueuse/core';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import DataTableFacetedFilter from './DataTableFacetedFilter.vue';

const props = withDefaults(
    defineProps<{
        columns: ColumnDef<TData, TValue>[];
        data: TData[];
        rowCount?: number;
        pageCount?: number;
        currentPage?: number;
        filters?: Record<string, any>;
        filterOptions?: StatusFilterOption[];
        filterStyle?: 'tabs' | 'dropdown';
        facetedFilters?: { title: string; key: string; options: { label: string; value: string }[] }[];
        searchPlaceholder?: string;
        tabFilterKey?: string;
        hideFilters?: boolean;
        headerClass?: string;
        footerClass?: string;
        only?: string[];
        isLoading?: boolean;
    }>(),
    {
        hideFilters: false,
        isLoading: false,
    },
);

const emit = defineEmits<{
    (e: 'update:selectedRowIds', ids: any[]): void;
}>();

const sorting = ref<SortingState>(
    props.filters?.sort
        ? [
              {
                  id: String(props.filters.sort),
                  desc: props.filters?.order === 'desc',
              },
          ]
        : [],
);
const columnFilters = ref<ColumnFiltersState>([]);
const rowSelection = ref<Record<string, boolean>>({});

const pagination = ref<PaginationState>({
    pageIndex: (props.currentPage || 1) - 1,
    pageSize: Number(props.filters?.per_page ?? (typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('per_page') : null)) || 10,
});

const globalFilter = ref(props.filters?.search || '');
const tabKey = props.tabFilterKey || 'status';
const currentTab = ref(props.filters?.[tabKey] || 'all');

const selectedFilters = ref<Record<string, string[]>>({});

if (props.filters) {
    props.facetedFilters?.forEach((filter) => {
        const value = props.filters?.[filter.key];
        if (Array.isArray(value)) {
            selectedFilters.value[filter.key] = value;
        } else if (typeof value === 'string' && value !== '') {
            selectedFilters.value[filter.key] = value.split(',');
        } else {
            selectedFilters.value[filter.key] = [];
        }
    });
}

const isFiltered = computed(() => {
    const hasGlobalFilter = !!globalFilter.value;
    const hasTabFilter = currentTab.value !== 'all';
    const hasFacetedFilters = Object.values(selectedFilters.value).some((arr) => arr.length > 0);

    return hasGlobalFilter || hasTabFilter || hasFacetedFilters;
});

const updateParams = () => {
    const params: Record<string, any> = {
        page: pagination.value.pageIndex + 1,
        per_page: pagination.value.pageSize,
        search: globalFilter.value?.trim() || null,
    };

    if (props.filterOptions && props.filterOptions.length > 0) {
        params[tabKey] = currentTab.value;
    }

    if (props.facetedFilters) {
        props.facetedFilters.forEach((filter) => {
            const values = selectedFilters.value[filter.key];
            if (values && values.length > 0) {
                params[filter.key] = values.join(',');
            } else {
                params[filter.key] = null;
            }
        });
    }

    if (sorting.value.length > 0) {
        params.sort = sorting.value[0].id;
        params.order = sorting.value[0].desc ? 'desc' : 'asc';
    } else {
        params.sort = null;
        params.order = null;
    }

    const options: Record<string, any> = {
        preserveState: true,
        preserveScroll: true,
    };

    if (props.only) {
        options.only = props.only;
    }

    router.get(window.location.pathname, params, options);
};

const handleTabChange = (value: string) => {
    rowSelection.value = {};
    currentTab.value = value;
    pagination.value.pageIndex = 0;
    updateParams();
};

watch(
    selectedFilters,
    () => {
        rowSelection.value = {};
        pagination.value.pageIndex = 0;
        updateParams();
    },
    { deep: true },
);

watch(
    sorting,
    () => {
        rowSelection.value = {};
        updateParams();
    },
    { deep: true },
);

const table = useVueTable({
    get data() {
        return props.data;
    },
    get columns() {
        return props.columns;
    },
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row) => String((row as any).id),
    enableRowSelection: true,
    manualPagination: true,
    manualFiltering: true,
    manualSorting: true,
    get rowCount() {
        return props.rowCount;
    },
    get pageCount() {
        return props.pageCount;
    },
    onSortingChange: (updaterOrValue) => valueUpdater(updaterOrValue, sorting),
    onColumnFiltersChange: (updaterOrValue) => valueUpdater(updaterOrValue, columnFilters),
    onRowSelectionChange: (updaterOrValue) => valueUpdater(updaterOrValue, rowSelection),
    onPaginationChange: (updaterOrValue) => valueUpdater(updaterOrValue, pagination),
    state: {
        get sorting() {
            return sorting.value;
        },
        get columnFilters() {
            return columnFilters.value;
        },
        get rowSelection() {
            return rowSelection.value;
        },
        get pagination() {
            return pagination.value;
        },
    },
});

watch(
    rowSelection,
    () => {
        const selectedIds = Object.keys(rowSelection.value).filter((key) => rowSelection.value[key]);
        emit('update:selectedRowIds', selectedIds);
    },
    { deep: true },
);

watch(
    () => pagination.value,
    () => {
        updateParams();
    },
);

debouncedWatch(
    globalFilter,
    (newValue) => {
        const currentServerSearch = (props.filters?.search || '').trim();
        const newSearch = (newValue || '').trim();

        if (newSearch !== currentServerSearch) {
            rowSelection.value = {};
            pagination.value.pageIndex = 0;
            updateParams();
        }
    },
    { debounce: 300 },
);

const serializedFilters = computed(() => {
    if (!props.filters) return '';
    const clean = { ...props.filters };
    delete clean.page;
    delete clean.per_page;
    return JSON.stringify(clean);
});

watch(serializedFilters, () => {
    rowSelection.value = {};
});

const handlePageSizeChange = (value: any) => {
    if (value) {
        table.setPageSize(Number(value));
    }
};
</script>

<template>
    <div>
        <div class="space-y-4">
            <template v-if="!hideFilters">
                <!-- Tabs Filter -->
                <div v-if="filterStyle === 'tabs' && filterOptions" class="flex items-center space-x-1 overflow-x-auto border-b pb-1">
                    <button
                        v-for="option in filterOptions"
                        :key="option.value"
                        type="button"
                        @click="handleTabChange(option.value)"
                        :class="[
                            'whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            currentTab === option.value
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                        ]"
                    >
                        {{ option.label }}
                        <span v-if="option.count !== undefined && option.count !== null" class="ml-1 rounded-full border bg-background px-1.5 py-0.5 text-xs">
                            {{ option.count }}
                        </span>
                    </button>
                </div>

                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <!-- Left: Faceted Filters -->
                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="facetedFilters">
                            <DataTableFacetedFilter
                                v-for="filter in facetedFilters"
                                :key="filter.key"
                                :title="filter.title"
                                :options="filter.options"
                                :selected-values="selectedFilters[filter.key]"
                                @update:selected-values="(vals) => (selectedFilters[filter.key] = vals)"
                            />
                        </template>
                    </div>

                    <!-- Right: Search -->
                    <div class="w-full md:max-w-sm lg:w-80">
                        <Input
                            class="w-full"
                            :placeholder="searchPlaceholder || 'Search...'"
                            :title="searchPlaceholder || 'Search...'"
                            v-model="globalFilter"
                        />
                    </div>
                </div>
            </template>

            <div class="overflow-hidden rounded-md border">
                <Table>
                    <TableHeader :class="cn('sticky top-0 z-10 bg-muted', props.headerClass)">
                        <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                            <TableHead v-for="header in headerGroup.headers" :key="header.id" :col-span="header.colSpan" class="h-10">
                                <FlexRender v-if="!header.isPlaceholder" :render="header.column.columnDef.header" :props="header.getContext()" />
                            </TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <template v-if="isLoading">
                            <TableRow>
                                <TableCell :colspan="columns.length" class="h-32 text-center">
                                    <div class="flex flex-col items-center justify-center space-y-2 py-4">
                                        <Loader2 class="size-6 animate-spin text-primary" />
                                        <span class="text-xs font-medium text-muted-foreground">Loading data...</span>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>

                        <template v-else-if="table.getRowModel().rows?.length">
                            <TableRow
                                v-for="row in table.getRowModel().rows"
                                :key="row.id + '-' + (table.getState().rowSelection[row.id] ? 'selected' : 'unselected')"
                                :data-state="row.getIsSelected() ? 'selected' : undefined"
                            >
                                <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id" class="h-12">
                                    <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
                                </TableCell>
                            </TableRow>
                        </template>

                        <TableEmpty v-else :colspan="columns.length"> No results. </TableEmpty>
                    </TableBody>

                    <TableFooter v-if="rowCount" :class="cn('w-full', props.footerClass)">
                        <TableRow>
                            <TableCell :colspan="columns.length">
                                <div class="flex flex-wrap items-center justify-between gap-4 px-2">
                                    <div class="text-sm text-muted-foreground">
                                        Showing
                                        <span class="font-medium">{{ pagination.pageIndex * pagination.pageSize + 1 }}</span>
                                        to
                                        <span class="font-medium">
                                            {{ Math.min((pagination.pageIndex + 1) * pagination.pageSize, rowCount) }}
                                        </span>
                                        of
                                        <span class="font-medium">{{ rowCount }}</span>
                                        {{ isFiltered ? 'filtered' : '' }} results
                                    </div>

                                    <div class="flex items-center space-x-6 lg:space-x-8">
                                        <div class="flex items-center space-x-2">
                                            <p class="text-sm font-medium">Rows per page</p>
                                            <Select :model-value="`${table.getState().pagination.pageSize}`" @update:model-value="handlePageSizeChange">
                                                <SelectTrigger class="h-8 w-[70px]">
                                                    <SelectValue :placeholder="`${table.getState().pagination.pageSize}`" />
                                                </SelectTrigger>
                                                <SelectContent side="top">
                                                    <SelectItem v-for="pageSize in [10, 20, 30, 40, 50]" :key="pageSize" :value="`${pageSize}`">
                                                        {{ pageSize }}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div class="flex w-[100px] items-center justify-center text-sm font-medium">
                                            Page {{ table.getState().pagination.pageIndex + 1 }} of
                                            {{ table.getPageCount() }}
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <Button
                                                variant="outline"
                                                class="hidden h-8 w-8 p-0 lg:flex"
                                                :disabled="!table.getCanPreviousPage()"
                                                @click="table.setPageIndex(0)"
                                            >
                                                <span class="sr-only">Go to first page</span>
                                                <ChevronsLeft class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                class="h-8 w-8 p-0"
                                                :disabled="!table.getCanPreviousPage()"
                                                @click="table.previousPage()"
                                            >
                                                <span class="sr-only">Go to previous page</span>
                                                <ChevronLeft class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                class="h-8 w-8 p-0"
                                                :disabled="!table.getCanNextPage()"
                                                @click="table.nextPage()"
                                            >
                                                <span class="sr-only">Go to next page</span>
                                                <ChevronRight class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                class="hidden h-8 w-8 p-0 lg:flex"
                                                :disabled="!table.getCanNextPage()"
                                                @click="table.setPageIndex(table.getPageCount() - 1)"
                                            >
                                                <span class="sr-only">Go to last page</span>
                                                <ChevronsRight class="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
        </div>
    </div>
</template>
