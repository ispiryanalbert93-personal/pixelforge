<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch
} from 'vue';
import {
    AlertCircle,
    ArrowRight,
    ImageOff,
    Loader2,
    Paperclip,
    Plus,
    RefreshCw,
    ShieldAlert,
    Sparkles,
} from 'lucide-vue-next';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { getEcho } from '@/lib/echo';
import { imageAspectClass } from '@/lib/generation';
import { calculateLiveEstimate, formatUsdDisplay } from '@/lib/pricing';
import { setLiveWallet, useWalletState } from '@/composables/useWalletState';
import type { BreadcrumbItem, Generation, PricingConfig, SharedData } from '@/types';
import type { GenerationUpdatedBroadcastPayload } from '@/composables/useGenerationRealtime';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import Trace from '@/components/ai-elements/Trace.vue';
import ImagePreviewOverlay from '@/components/ImagePreviewOverlay.vue';
import Bubble from '@/components/ai-elements/Bubble.vue';
import Message from '@/components/ai-elements/Message.vue';
import PromptInput from '@/components/ai-elements/PromptInput.vue';
import SuggestionBar from '@/components/ai-elements/SuggestionBar.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    pricing_config: PricingConfig | null;
    pricing_error?: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AI Image Studio',
        href: '/dashboard',
    },
];

const page = usePage<SharedData>();
const appName = computed(() => page.props.name);
const { balanceNano } = useWalletState();

const form = useForm({
    prompt: '',
    size: props.pricing_config?.sizes[0]?.value ?? '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const showSuggestions = ref(true);
const quickTopupProcessing = ref(false);
const expiredImageIds = ref<Record<number, boolean>>({});
const loadedImageIds = ref<Record<number, boolean>>({});
const scrollerRef = ref<HTMLElement | null>(null);
const previewOpen = ref(false);

const currentGeneration = ref<Generation | null>(page.props.flash?.current_generation ?? null);

let subscribedChannel: string | null = null;

function teardownChannel(): void {
    if (subscribedChannel) {
        getEcho()?.leave(subscribedChannel);
        subscribedChannel = null;
    }
}

watch(
    () => page.props.flash?.current_generation,
    (nextGeneration) => {
        if (nextGeneration) {
            currentGeneration.value = nextGeneration;
            previewOpen.value = false;
            scrollToBottom();
        }
    },
);

watch(
    () => page.props.auth?.user?.id,
    (uid) => {
        teardownChannel();

        if (!uid) {
            return;
        }

        const echo = getEcho();
        if (!echo) {
            return;
        }

        const channelName = `App.Models.User.${uid}`;
        subscribedChannel = channelName;

        echo.private(channelName).listen('.generation.updated', (payload: GenerationUpdatedBroadcastPayload) => {
            setLiveWallet(payload.wallet);

            if (currentGeneration.value && payload.generation.id === currentGeneration.value.id) {
                currentGeneration.value = payload.generation;
                scrollToBottom();
            }
        });
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    teardownChannel();
});

const formErrors = computed(() => form.errors as Record<string, string | undefined>);
const creditsError = computed(() => formErrors.value.credits);
const pricingError = computed(() => formErrors.value.pricing ?? props.pricing_error ?? null);

const estimate = computed(() => {
    if (!props.pricing_config) {
        return null;
    }

    return calculateLiveEstimate(form.prompt, form.size, form.quality, form.reference_images, props.pricing_config);
});

const canAffordMaxEstimate = computed(() => (estimate.value ? balanceNano.value >= estimate.value.maxUserCostNano : false));

const canSubmit = computed(() => !form.processing && props.pricing_config !== null && form.prompt.trim().length > 0 && canAffordMaxEstimate.value);

function scrollToBottom(): void {
    nextTick(() => {
        if (scrollerRef.value) {
            scrollerRef.value.scrollTo({
                top: scrollerRef.value.scrollHeight,
                behavior: 'smooth',
            });
        }
    });
}

onMounted(() => {
    if (currentGeneration.value) {
        scrollToBottom();
    }
});

function selectPromptSuggestion(text: string): void {
    form.prompt = text;
}

function toggleKeyword(tag: string): void {
    if (form.prompt.toLowerCase().includes(tag.toLowerCase())) {
        const escapedTag = tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        form.prompt = form.prompt
            .replace(new RegExp(escapedTag, 'gi'), '')
            .replace(/[ \t]{2,}/g, ' ')
            .trim();
        return;
    }

    const trimmed = form.prompt.trimEnd();
    const next = trimmed.length > 0 ? `${trimmed} ${tag}` : tag;
    if (Array.from(next).length <= 4000) {
        form.prompt = next;
    }
}

function submitGeneration(): void {
    if (!canSubmit.value) {
        return;
    }

    scrollToBottom();

    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.clearErrors();
            form.prompt = '';
            form.reference_images = [];
            scrollToBottom();
        },
    });
}

function quickAddTenCredits(): void {
    quickTopupProcessing.value = true;
    router.post(
        route('wallet.store'),
        { amount_usd: 10 },
        {
            preserveScroll: true,
            onFinish: () => {
                quickTopupProcessing.value = false;
            },
        },
    );
}

function markImageLoaded(id: number): void {
    loadedImageIds.value[id] = true;
    scrollToBottom();
}

function markImageExpired(id: number): void {
    expiredImageIds.value[id] = true;
    loadedImageIds.value[id] = true;
}
</script>

<template>
    <Head title="AI Image Studio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-[calc(100dvh-4rem)] min-h-0 w-full flex-col overflow-hidden px-4 pt-3 md:h-[calc(100dvh-5rem)] md:px-6 md:group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-[calc(100dvh-4rem)]"
        >
            <!-- Compact Header Bar -->
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b pb-3">
                <h1 class="text-xl font-bold tracking-tight">AI Image Studio</h1>

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('generations.history')"
                        class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        Full History
                        <ArrowRight class="size-3" />
                    </Link>
                </div>
            </div>

            <!-- Middle-Only Scrollable Chat Viewport -->
            <div ref="scrollerRef" class="chat-scroll flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto overflow-x-hidden py-4 pr-1">
                <!-- Optimistic Submitting State -->
                <div v-if="form.processing" class="space-y-4">
                    <Message align="end" author="You">
                        <Bubble align="end">
                            <p class="whitespace-pre-wrap">{{ form.prompt }}</p>
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                <Badge
                                    variant="secondary"
                                    class="border-transparent bg-primary-foreground/15 font-mono text-[10px] text-primary-foreground"
                                >
                                    {{ form.size }}
                                </Badge>
                                <Badge
                                    variant="secondary"
                                    class="border-transparent bg-primary-foreground/15 font-mono text-[10px] capitalize text-primary-foreground"
                                >
                                    {{ form.quality }}
                                </Badge>
                            </div>
                        </Bubble>
                    </Message>

                    <Message align="start" :author="`${appName} AI`" status="queued">
                        <div
                            :class="[
                                imageAspectClass(form.size),
                                'relative flex w-full max-w-[300px] flex-col items-center justify-center overflow-hidden rounded-xl border bg-muted/30 p-6 text-center',
                            ]"
                        >
                            <Skeleton class="absolute inset-0 rounded-none opacity-60" />
                            <div class="relative z-10 flex flex-col items-center gap-2">
                                <Loader2 class="size-7 animate-spin text-primary" />
                                <p class="text-xs font-semibold">Submitting to Pixelforge...</p>
                            </div>
                        </div>
                    </Message>
                </div>

                <!-- Empty State -->
                <div v-else-if="!currentGeneration" class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                    <div class="flex size-14 items-center justify-center rounded-2xl border bg-primary/10 text-primary shadow-sm">
                        <Sparkles class="size-7" />
                    </div>
                    <div class="max-w-md space-y-1.5">
                        <h2 class="text-xl font-semibold tracking-tight">What would you like to create today?</h2>
                        <p class="text-sm text-muted-foreground">
                            Every prompt shows its exact upfront token estimate before submission. Unspent reserves are refunded and failed
                            generations cost <strong class="text-foreground">$0.00</strong>.
                        </p>
                    </div>
                </div>

                <!-- Single Active / Just-Generated Turn -->
                <div v-else class="space-y-4">
                    <!-- User Prompt Turn -->
                    <Message align="end" author="You" :timestamp="currentGeneration.created_at">
                        <Bubble align="end">
                            <p class="whitespace-pre-wrap">{{ currentGeneration.prompt }}</p>
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                <Badge
                                    variant="secondary"
                                    class="border-transparent bg-primary-foreground/15 font-mono text-[10px] text-primary-foreground"
                                >
                                    {{ currentGeneration.size }}
                                </Badge>
                                <Badge
                                    variant="secondary"
                                    class="border-transparent bg-primary-foreground/15 font-mono text-[10px] capitalize text-primary-foreground"
                                >
                                    {{ currentGeneration.quality }}
                                </Badge>
                                <a
                                    v-for="(refUrl, idx) in currentGeneration.reference_images"
                                    :key="`${currentGeneration.id}-ref-${idx}`"
                                    :href="refUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded-full bg-primary-foreground/15 px-2 py-0.5 text-[10px] text-primary-foreground hover:bg-primary-foreground/25"
                                >
                                    <Paperclip class="size-2.5" />
                                    Ref #{{ idx + 1 }}
                                </a>
                            </div>
                        </Bubble>
                    </Message>

                    <!-- Pixelforge Agent Turn -->
                    <Message
                        align="start"
                        :author="`${appName} AI`"
                        :status="currentGeneration.status"
                        :timestamp="currentGeneration.completed_at ?? currentGeneration.created_at"
                    >
                        <div class="w-full space-y-2.5">
                            <!-- Collapsible Trace / Reasoning Bar -->
                            <Trace :generation="currentGeneration" />

                            <!-- Image / Active Shimmer / Error Output -->
                            <div
                                v-if="currentGeneration.status === 'succeeded'"
                                class="w-full max-w-[320px] overflow-hidden rounded-xl border bg-card shadow-sm"
                            >
                                <button
                                    v-if="currentGeneration.image_url && !expiredImageIds[currentGeneration.id]"
                                    type="button"
                                    :class="[
                                        imageAspectClass(currentGeneration.size),
                                        'group relative block w-full cursor-zoom-in bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    ]"
                                    aria-label="Preview generated image"
                                    @click="previewOpen = true"
                                >
                                    <div
                                        v-if="!loadedImageIds[currentGeneration.id]"
                                        class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-muted/40"
                                    >
                                        <Skeleton class="absolute inset-0 rounded-none opacity-60" />
                                        <Loader2 class="relative z-10 size-6 animate-spin text-primary" />
                                        <span class="relative z-10 text-[11px] font-medium text-muted-foreground">Loading image...</span>
                                    </div>
                                    <img
                                        :src="currentGeneration.image_url"
                                        :alt="currentGeneration.prompt"
                                        class="h-full w-full object-contain transition-all duration-200 group-hover:scale-[1.01]"
                                        :class="loadedImageIds[currentGeneration.id] ? 'opacity-100' : 'opacity-0'"
                                        @load="markImageLoaded(currentGeneration.id)"
                                        @error="markImageExpired(currentGeneration.id)"
                                    />
                                </button>
                                <div
                                    v-else
                                    :class="[imageAspectClass(currentGeneration.size), 'flex flex-col items-center justify-center p-6 text-center']"
                                >
                                    <ImageOff class="size-8 text-muted-foreground" />
                                    <p class="mt-2 text-xs font-medium">Signed image URL expired (24h TTL)</p>
                                    <p class="mt-1 text-[11px] text-muted-foreground">Pixelforge signed URLs remain valid for 24 hours.</p>
                                </div>
                            </div>

                            <div
                                v-else-if="currentGeneration.status === 'queued' || currentGeneration.status === 'processing'"
                                :class="[
                                    imageAspectClass(currentGeneration.size),
                                    'relative flex w-full max-w-[320px] flex-col items-center justify-center overflow-hidden rounded-xl border bg-muted/30 p-6 text-center',
                                ]"
                            >
                                <Skeleton class="absolute inset-0 rounded-none opacity-60" />
                                <div class="relative z-10 flex flex-col items-center gap-2">
                                    <Loader2 class="size-7 animate-spin text-primary" />
                                    <p class="text-xs font-semibold">
                                        {{ currentGeneration.status === 'queued' ? 'Queued with Pixelforge...' : 'Generating image...' }}
                                    </p>
                                    <p
                                        v-if="currentGeneration.error_message"
                                        class="max-w-[240px] rounded-md bg-background/85 px-2.5 py-1 text-[11px] text-amber-700 dark:text-amber-300"
                                    >
                                        {{ currentGeneration.error_message }}
                                    </p>
                                </div>
                            </div>

                            <Alert v-else variant="destructive" class="max-w-md">
                                <AlertCircle class="size-4" />
                                <AlertTitle class="font-mono text-xs">
                                    {{ currentGeneration.error_code ?? 'GENERATION_FAILED' }}
                                </AlertTitle>
                                <AlertDescription class="text-xs">
                                    {{ currentGeneration.error_message ?? 'Generation failed. Held credits were released ($0.00 charged).' }}
                                </AlertDescription>
                            </Alert>
                        </div>
                    </Message>
                </div>
            </div>

            <!-- Bottom Docked SuggestionBar + PromptInput -->
            <div :class="['shrink-0 space-y-2.5 bg-background pb-4', showSuggestions ? 'border-t pt-3' : 'pt-2']">
                <!-- Pricing API Unavailable Banner -->
                <Alert v-if="pricingError || !pricing_config" variant="destructive" class="py-2.5">
                    <ShieldAlert class="size-4" />
                    <AlertTitle class="text-xs font-semibold">Pricing Unavailable</AlertTitle>
                    <AlertDescription class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span>{{ pricingError ?? 'Unable to load current Pixelforge pricing.' }}</span>
                        <Button type="button" size="sm" variant="outline" class="h-7 bg-background text-xs text-foreground" @click="router.reload()">
                            <RefreshCw class="size-3" />
                            Retry
                        </Button>
                    </AlertDescription>
                </Alert>

                <!-- Error / Insufficient Credits Banner -->
                <Alert v-else-if="creditsError || (estimate && !canAffordMaxEstimate)" variant="destructive" class="py-2.5">
                    <ShieldAlert class="size-4" />
                    <AlertTitle class="text-xs font-semibold">Insufficient Credits</AlertTitle>
                    <AlertDescription class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span>
                            {{ creditsError ?? `Insufficient credits to cover max pre-auth hold (${formatUsdDisplay(estimate!.maxUserCostNano)}).` }}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-7 bg-background text-xs text-foreground"
                            :disabled="quickTopupProcessing"
                            @click="quickAddTenCredits"
                        >
                            <Loader2 v-if="quickTopupProcessing" class="size-3 animate-spin" />
                            <Plus v-else class="size-3" />
                            + Add $10.00 Credits
                        </Button>
                    </AlertDescription>
                </Alert>

                <!-- Horizontal Suggestion & Test Keyword Pills -->
                <SuggestionBar
                    v-if="showSuggestions && pricing_config"
                    :prompt="form.prompt"
                    @select-prompt="selectPromptSuggestion"
                    @toggle-keyword="toggleKeyword"
                />

                <!-- Unified AI Elements PromptInput -->
                <PromptInput
                    v-if="pricing_config && estimate"
                    v-model:prompt="form.prompt"
                    v-model:size="form.size"
                    v-model:quality="form.quality"
                    v-model:reference-images="form.reference_images"
                    :pricing-config="pricing_config"
                    :estimate="estimate"
                    :can-afford="canAffordMaxEstimate"
                    :can-submit="canSubmit"
                    :processing="form.processing"
                    :show-suggestions="showSuggestions"
                    :errors="formErrors"
                    @toggle-suggestions="showSuggestions = !showSuggestions"
                    @submit="submitGeneration"
                />
            </div>

            <ImagePreviewOverlay
                v-model:open="previewOpen"
                :image-url="currentGeneration?.image_url ?? null"
                :prompt="currentGeneration?.prompt"
                :filename="currentGeneration?.pixelforge_id ?? undefined"
            />
        </div>
    </AppLayout>
</template>
