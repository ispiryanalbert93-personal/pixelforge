<script setup lang="ts">
import ContextCostPopover from '@/components/ai-elements/ContextCostPopover.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { LiveCostEstimate } from '@/lib/pricing';
import type { PricingConfig, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ArrowUp, FlaskConical, Loader2, Maximize2, Paperclip, Plus, SlidersHorizontal, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    prompt: string;
    size: string;
    quality: string;
    referenceImages: string[];
    pricingConfig: PricingConfig;
    estimate: LiveCostEstimate;
    canAfford: boolean;
    canSubmit: boolean;
    processing: boolean;
    showSuggestions: boolean;
    errors?: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    (e: 'update:prompt', value: string): void;
    (e: 'update:size', value: string): void;
    (e: 'update:quality', value: string): void;
    (e: 'update:referenceImages', value: string[]): void;
    (e: 'toggleSuggestions'): void;
    (e: 'submit'): void;
}>();

const page = usePage<SharedData>();

const showRefInput = ref(false);
const draftRefUrl = ref('');

const appName = computed(() => page.props.name);
const isDevelopment = computed(() => Boolean(page.props.is_development));
const canAddMoreRefs = computed(() => props.referenceImages.length < props.pricingConfig.max_reference_images);
const promptCodePoints = computed(() => Array.from(props.prompt).length);
const isPromptTooLong = computed(() => promptCodePoints.value > 4000);

function displayRefLabel(url: string): string {
    try {
        const parsed = new URL(url);
        return parsed.hostname + (parsed.pathname.length > 1 ? parsed.pathname.slice(0, 18) : '');
    } catch {
        return url.length > 26 ? `${url.slice(0, 26)}...` : url;
    }
}

function openRefInput(): void {
    showRefInput.value = !showRefInput.value;
}

function addDraftRef(): void {
    const trimmed = draftRefUrl.value.trim();
    if (!trimmed || !canAddMoreRefs.value) {
        return;
    }

    emit('update:referenceImages', [...props.referenceImages, trimmed]);
    draftRefUrl.value = '';
    if (props.referenceImages.length + 1 >= props.pricingConfig.max_reference_images) {
        showRefInput.value = false;
    }
}

function removeRef(index: number): void {
    const next = [...props.referenceImages];
    next.splice(index, 1);
    emit('update:referenceImages', next);
}

function handleTextareaKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        if (props.canSubmit) {
            emit('submit');
        }
    }
}
</script>

<template>
    <div class="space-y-1.5">
        <form
            @submit.prevent="emit('submit')"
            class="overflow-hidden rounded-2xl border bg-background shadow-sm transition-shadow focus-within:border-primary/50 focus-within:ring-2 focus-within:ring-primary/15"
        >
            <!-- Top Attachment Strip (Reference Image Chips + Inline Add Input) -->
            <div v-if="referenceImages.length > 0 || showRefInput" class="space-y-2 border-b bg-muted/20 px-3 py-2.5">
                <div v-if="referenceImages.length > 0" class="flex flex-wrap items-center gap-1.5">
                    <div
                        v-for="(url, index) in referenceImages"
                        :key="`${url}-${index}`"
                        class="inline-flex items-center gap-1.5 rounded-full border bg-background py-1 pl-2.5 pr-1.5 text-xs"
                    >
                        <Paperclip class="size-3 text-muted-foreground" />
                        <span class="max-w-[180px] truncate">{{ displayRefLabel(url) }}</span>
                        <Badge variant="secondary" class="px-1.5 py-0 font-mono text-[10px]">
                            +{{ pricingConfig.tokens_per_reference_image }} tok
                        </Badge>
                        <button
                            type="button"
                            aria-label="Remove reference image"
                            class="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                            @click="removeRef(index)"
                        >
                            <X class="size-3" />
                        </button>
                    </div>
                </div>

                <!-- Inline URL Input -->
                <div v-if="showRefInput && canAddMoreRefs" class="flex items-center gap-2">
                    <Input
                        v-model="draftRefUrl"
                        type="text"
                        inputmode="url"
                        placeholder="https://example.com/reference.png"
                        class="h-8 flex-1 bg-background text-xs"
                        @keydown.enter.prevent="addDraftRef"
                    />
                    <Button type="button" size="sm" variant="secondary" class="h-8 px-2.5 text-xs" @click="addDraftRef">
                        <Plus class="size-3.5" />
                        Attach
                    </Button>
                    <Button type="button" size="icon" variant="ghost" class="size-8" @click="showRefInput = false">
                        <X class="size-3.5" />
                    </Button>
                </div>

                <!-- Reference Image Errors -->
                <div v-if="errors" class="space-y-0.5">
                    <p v-if="errors.reference_images" class="text-xs text-destructive">{{ errors.reference_images }}</p>
                    <template v-for="(_, index) in referenceImages" :key="`err-${index}`">
                        <p v-if="errors[`reference_images.${index}`]" class="text-xs text-destructive">
                            {{ errors[`reference_images.${index}`] }}
                        </p>
                    </template>
                </div>
            </div>

            <!-- Seamless Prompt Textarea -->
            <div class="px-3 pb-1 pt-2.5">
                <Textarea
                    :model-value="prompt"
                    rows="2"
                    :placeholder="`Ask ${appName} to generate an image...`"
                    class="min-h-[64px] resize-none border-0 bg-transparent p-1 text-sm shadow-none focus-visible:ring-0"
                    @update:model-value="(val) => emit('update:prompt', String(val))"
                    @keydown="handleTextareaKeydown"
                />
            </div>

            <!-- Bottom Addon Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-t bg-muted/25 px-3 py-2">
                <!-- Left Controls -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 rounded-full bg-background px-2.5 text-xs"
                        :disabled="!canAddMoreRefs"
                        @click="openRefInput"
                    >
                        <Paperclip class="size-3.5 text-muted-foreground" />
                        <span class="hidden sm:inline">Ref Image</span>
                        <Badge v-if="referenceImages.length > 0" variant="secondary" class="px-1.5 py-0 text-[10px]">
                            {{ referenceImages.length }}/{{ pricingConfig.max_reference_images }}
                        </Badge>
                    </Button>

                    <Select :model-value="size" @update:model-value="(val) => emit('update:size', String(val))">
                        <SelectTrigger class="h-8 w-auto gap-1.5 rounded-full bg-background px-2.5 text-xs font-medium">
                            <Maximize2 class="size-3.5 text-muted-foreground" />
                            <SelectValue placeholder="Size" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="sizeOpt in pricingConfig.sizes" :key="sizeOpt.value" :value="sizeOpt.value">
                                {{ sizeOpt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select :model-value="quality" @update:model-value="(val) => emit('update:quality', String(val))">
                        <SelectTrigger class="h-8 w-auto gap-1.5 rounded-full bg-background px-2.5 text-xs font-medium">
                            <SlidersHorizontal class="size-3.5 text-muted-foreground" />
                            <SelectValue placeholder="Quality" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="qualOpt in pricingConfig.qualities" :key="qualOpt.value" :value="qualOpt.value">
                                {{ qualOpt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="isDevelopment"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-8 rounded-full px-2.5 text-xs transition-colors"
                        :class="
                            showSuggestions
                                ? 'border-indigo-500/70 bg-indigo-500/15 font-medium text-indigo-700 hover:bg-indigo-500/25 hover:text-indigo-800 dark:border-indigo-400/70 dark:bg-indigo-500/25 dark:text-indigo-200 dark:hover:bg-indigo-500/35'
                                : 'bg-background text-muted-foreground hover:text-foreground'
                        "
                        @click="emit('toggleSuggestions')"
                    >
                        <FlaskConical class="size-3.5" />
                        <span class="hidden sm:inline">Test Tags</span>
                    </Button>
                </div>

                <!-- Right Controls: Context Cost Popover + Submit Button -->
                <div class="ml-auto flex items-center gap-2">
                    <ContextCostPopover
                        :estimate="estimate"
                        :pricing-config="pricingConfig"
                        :prompt-length="Array.from(prompt).length"
                        :size="size"
                        :quality="quality"
                        :can-afford="canAfford"
                    />

                    <Button type="submit" size="icon" class="size-8 rounded-full" :disabled="!canSubmit" aria-label="Generate image">
                        <Loader2 v-if="processing" class="size-4 animate-spin" />
                        <ArrowUp v-else class="size-4" />
                    </Button>
                </div>
            </div>
        </form>

        <!-- Prompt / Size / Quality Field Errors -->
        <div v-if="isPromptTooLong || errors?.prompt || errors?.size || errors?.quality" class="space-y-0.5 px-2">
            <p v-if="isPromptTooLong" class="text-xs text-destructive">
                Prompt exceeds the 4,000-character limit ({{ promptCodePoints.toLocaleString() }} / 4,000).
            </p>
            <p v-if="errors?.prompt" class="text-xs text-destructive">{{ errors.prompt }}</p>
            <p v-if="errors?.size" class="text-xs text-destructive">{{ errors.size }}</p>
            <p v-if="errors?.quality" class="text-xs text-destructive">{{ errors.quality }}</p>
        </div>
    </div>
</template>
