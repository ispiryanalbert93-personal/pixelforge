<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Download, Loader2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    open: boolean;
    imageUrl: string | null;
    prompt?: string | null;
    filename?: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const isDownloading = ref(false);

function close(): void {
    emit('update:open', false);
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open) {
        close();
    }
}

async function downloadImage(): Promise<void> {
    if (!props.imageUrl || isDownloading.value) {
        return;
    }

    const baseName = (props.filename || 'pixelforge-image').replace(/[^a-zA-Z0-9_-]/g, '-');
    const downloadName = `${baseName}.png`;

    isDownloading.value = true;

    try {
        const response = await fetch(props.imageUrl);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = downloadName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
    } catch {
        // Fallback when remote CDN blocks CORS blob reads
        const link = document.createElement('a');
        link.href = props.imageUrl;
        link.download = downloadName;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        document.body.appendChild(link);
        link.click();
        link.remove();
    } finally {
        isDownloading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return;
        }

        if (isOpen) {
            window.addEventListener('keydown', handleKeydown);
            document.body.style.overflow = 'hidden';
        } else {
            window.removeEventListener('keydown', handleKeydown);
            document.body.style.overflow = '';
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', handleKeydown);
    }
    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open && imageUrl"
                role="dialog"
                aria-modal="true"
                aria-label="Full page image preview"
                class="fixed inset-0 z-50 flex flex-col bg-black/90 backdrop-blur-sm"
                @click.self="close"
            >
                <!-- Top Toolbar -->
                <div class="flex shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-black/40 px-4 py-3 text-white md:px-6">
                    <p class="min-w-0 truncate text-sm text-white/85" :title="prompt ?? undefined">
                        {{ prompt || 'Generated Image' }}
                    </p>

                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            :disabled="isDownloading"
                            @click="downloadImage"
                        >
                            <Loader2 v-if="isDownloading" class="size-4 animate-spin" />
                            <Download v-else class="size-4" />
                            Download
                        </Button>

                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-white hover:bg-white/15 hover:text-white"
                            aria-label="Close preview"
                            @click="close"
                        >
                            <X class="size-4" />
                            Close
                        </Button>
                    </div>
                </div>

                <!-- Centered Image -->
                <div class="flex min-h-0 flex-1 items-center justify-center p-4 md:p-8" @click.self="close">
                    <img
                        :src="imageUrl"
                        :alt="prompt ?? 'Generated image preview'"
                        class="max-h-full max-w-full rounded-lg object-contain shadow-2xl"
                    />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
