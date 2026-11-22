import type { GenerationStatusValue } from '@/types';

export function statusBadgeClass(status: GenerationStatusValue): string {
    switch (status) {
        case 'queued':
            return 'border-amber-500/30 bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'processing':
            return 'animate-pulse border-blue-500/30 bg-blue-500/15 text-blue-700 dark:text-blue-300';
        case 'succeeded':
            return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'failed':
            return 'border-destructive/30 bg-destructive/15 text-destructive';
    }
}

export function imageAspectClass(size: string): string {
    switch (size) {
        case '1536x1024':
            return 'aspect-[3/2]';
        case '1024x1536':
            return 'aspect-[2/3]';
        default:
            return 'aspect-square';
    }
}
