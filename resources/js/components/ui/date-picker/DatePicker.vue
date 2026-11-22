<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { DateFormatter, type DateValue, parseDate } from '@internationalized/date';
import { Calendar as CalendarIcon } from 'lucide-vue-next';
import { computed, ref, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        placeholder?: string;
        id?: string;
        minDate?: string;
        maxDate?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        modelValue: '',
        placeholder: 'Pick a date',
    },
);

const emits = defineEmits<{
    'update:modelValue': [value: string];
}>();

const open = ref(false);

const df = new DateFormatter('en-US', {
    dateStyle: 'medium',
    timeZone: 'UTC',
});

function toDateValue(isoDate?: string): DateValue | undefined {
    if (!isoDate) {
        return undefined;
    }

    try {
        return parseDate(isoDate);
    } catch {
        return undefined;
    }
}

const selectedDate = computed<DateValue | undefined>(() => toDateValue(props.modelValue));
const minValue = computed<DateValue | undefined>(() => toDateValue(props.minDate));
const maxValue = computed<DateValue | undefined>(() => toDateValue(props.maxDate));

const formattedLabel = computed(() => {
    if (!selectedDate.value) {
        return props.placeholder;
    }

    return df.format(selectedDate.value.toDate('UTC'));
});

function handleSelect(value: DateValue | undefined): void {
    if (!value) {
        return;
    }

    emits('update:modelValue', value.toString());
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                size="sm"
                :class="
                    cn(
                        'h-8 w-[155px] justify-start text-left text-xs font-normal',
                        !selectedDate && 'text-muted-foreground',
                        props.class,
                    )
                "
            >
                <CalendarIcon class="mr-2 size-3.5 shrink-0" />
                <span class="truncate">{{ formattedLabel }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="selectedDate"
                :min-value="minValue"
                :max-value="maxValue"
                initial-focus
                @update:model-value="handleSelect"
            />
        </PopoverContent>
    </Popover>
</template>
