<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Check, PlusCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface Option {
    label: string;
    value: string;
    icon?: any;
}

interface Props {
    title?: string;
    options: Option[];
    selectedValues?: Set<string> | string[];
}

const props = defineProps<Props>();
const emits = defineEmits(['update:selectedValues', 'clear']);

const selectedSet = computed(() => {
    if (Array.isArray(props.selectedValues)) {
        return new Set(props.selectedValues);
    }

    return (props.selectedValues as Set<string>) || new Set();
});

const isSelected = (value: string) => selectedSet.value.has(value);

const toggleValue = (value: string) => {
    const newSet = new Set(selectedSet.value);

    if (newSet.has(value)) {
        newSet.delete(value);
    } else {
        newSet.add(value);
    }

    emits('update:selectedValues', Array.from(newSet));
};

const clearFilters = () => {
    emits('update:selectedValues', []);
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" class="h-8 border-dashed">
                <PlusCircle class="mr-2 h-4 w-4" />
                {{ title }}
                <template v-if="selectedSet.size > 0">
                    <Separator orientation="vertical" class="mx-2 h-4" />
                    <Badge variant="secondary" class="rounded-sm px-1 font-normal lg:hidden">
                        {{ selectedSet.size }}
                    </Badge>
                    <div class="hidden space-x-1 lg:flex">
                        <Badge v-if="selectedSet.size > 2" variant="secondary" class="rounded-sm px-1 font-normal">
                            {{ selectedSet.size }} selected
                        </Badge>

                        <template v-else>
                            <Badge
                                v-for="option in options.filter((option) => isSelected(option.value))"
                                :key="option.value"
                                variant="secondary"
                                class="rounded-sm px-1 font-normal"
                            >
                                {{ option.label }}
                            </Badge>
                        </template>
                    </div>
                </template>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-[200px]">
            <DropdownMenuLabel>{{ title }}</DropdownMenuLabel>
            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                @select.prevent="toggleValue(option.value)"
                class="relative flex cursor-default items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
            >
                <div v-if="isSelected(option.value)" class="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
                    <Check class="h-4 w-4" />
                </div>
                <span>{{ option.label }}</span>
            </DropdownMenuItem>

            <template v-if="selectedSet.size > 0">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="justify-center text-center" @click="clearFilters"> Clear filters </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
