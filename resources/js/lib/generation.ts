import type { Generation, GenerationHistoryRow, GenerationStatusValue } from '@/types';

const STATUS_RANK: Record<GenerationStatusValue, number> = {
    queued: 0,
    processing: 1,
    succeeded: 2,
    failed: 2,
};

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

export function isImageExpired(expiresAt: string | null | undefined): boolean {
    if (!expiresAt) {
        return false;
    }

    const timestamp = Date.parse(expiresAt);

    return !Number.isNaN(timestamp) && timestamp <= Date.now();
}

export function mergeGenerationMonotonic(existing: Generation | undefined, incoming: Generation): Generation {
    if (!existing) {
        return incoming;
    }

    const isRecoveringAmbiguousLocalFailure = existing.status === 'failed' && existing.pixelforge_id === null && incoming.pixelforge_id !== null;

    if (!isRecoveringAmbiguousLocalFailure && STATUS_RANK[incoming.status] < STATUS_RANK[existing.status]) {
        return existing;
    }

    if (!isRecoveringAmbiguousLocalFailure && STATUS_RANK[existing.status] === 2 && existing.status !== incoming.status) {
        return existing;
    }

    return incoming;
}

export function toHistoryRow(gen: Generation): GenerationHistoryRow {
    return {
        id: gen.id,
        pixelforge_id: gen.pixelforge_id,
        status: gen.status,
        prompt: gen.prompt,
        size: gen.size,
        quality: gen.quality,
        reference_images_count: gen.reference_images?.length ?? 0,
        usage: gen.usage,
        estimated_min_user_cost_usd: gen.estimated_min_user_cost_usd,
        estimated_user_cost_formatted: gen.estimated_user_cost_formatted,
        estimated_max_user_cost_usd: gen.estimated_max_user_cost_usd,
        reserved_user_cost_usd: gen.reserved_user_cost_usd,
        actual_user_cost_usd: gen.actual_user_cost_usd,
        actual_user_cost_formatted: gen.actual_user_cost_formatted,
        image_url: gen.image_url,
        image_url_expires_at: gen.image_url_expires_at,
        error_message: gen.error_message,
        created_at: gen.created_at,
    };
}
