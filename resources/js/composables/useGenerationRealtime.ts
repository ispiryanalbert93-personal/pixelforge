import { setLiveWallet } from '@/composables/useWalletState';
import { getEcho } from '@/lib/echo';
import { mergeGenerationMonotonic, toHistoryRow } from '@/lib/generation';
import type { Generation, GenerationHistoryFilters, GenerationHistoryRow, WalletData } from '@/types';
import { onBeforeUnmount, ref, watch } from 'vue';

export interface GenerationUpdatedBroadcastPayload {
    generation: Generation;
    wallet: WalletData;
    has_active_generations: boolean;
}

type GenerationListener = (payload: GenerationUpdatedBroadcastPayload) => void;

const liveGenerationsById = ref<Record<number, Generation>>({});
const listeners = new Set<GenerationListener>();

let activeUserId: number | undefined;
let subscribedChannel: string | null = null;

function ensureChannelSubscription(uid: number | undefined): void {
    if (!uid) {
        teardownSharedChannel();
        return;
    }

    if (activeUserId === uid && subscribedChannel !== null) {
        return;
    }

    teardownSharedChannel();

    const echo = getEcho();
    if (!echo) {
        return;
    }

    const channelName = `App.Models.User.${uid}`;
    activeUserId = uid;
    subscribedChannel = channelName;

    echo.private(channelName).listen('.generation.updated', (payload: GenerationUpdatedBroadcastPayload) => {
        const merged = mergeGenerationMonotonic(liveGenerationsById.value[payload.generation.id], payload.generation);

        liveGenerationsById.value = {
            ...liveGenerationsById.value,
            [merged.id]: merged,
        };

        setLiveWallet(payload.wallet);

        const mergedPayload: GenerationUpdatedBroadcastPayload = {
            ...payload,
            generation: merged,
        };

        listeners.forEach((listener) => listener(mergedPayload));
    });
}

function teardownSharedChannel(): void {
    if (subscribedChannel) {
        getEcho()?.leave(subscribedChannel);
        subscribedChannel = null;
    }
    activeUserId = undefined;
}

export function resetRealtimeState(): void {
    teardownSharedChannel();
    liveGenerationsById.value = {};
}

function matchesHistoryFilters(row: GenerationHistoryRow, filters: GenerationHistoryFilters): boolean {
    if (filters.status === 'active' && row.status !== 'queued' && row.status !== 'processing') {
        return false;
    }

    if (filters.status === 'succeeded' && row.status !== 'succeeded') {
        return false;
    }

    if (filters.status === 'failed' && row.status !== 'failed') {
        return false;
    }

    const search = filters.search?.trim() ?? '';
    if (search !== '' && row.pixelforge_id !== search) {
        return false;
    }

    return true;
}

export function useGenerationRealtimeSubscription(userId: () => number | undefined, onUpdate?: GenerationListener) {
    if (onUpdate) {
        listeners.add(onUpdate);
    }

    watch(
        userId,
        (uid) => {
            ensureChannelSubscription(uid);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        if (onUpdate) {
            listeners.delete(onUpdate);
        }

        if (listeners.size === 0) {
            teardownSharedChannel();
        }
    });

    function getMergedGeneration(generation: Generation | null): Generation | null {
        if (!generation) {
            return null;
        }

        const cached = liveGenerationsById.value[generation.id];

        return cached ? mergeGenerationMonotonic(generation, cached) : generation;
    }

    return {
        liveGenerationsById,
        getMergedGeneration,
    };
}

export function useGenerationRealtime(
    initialGenerations: () => GenerationHistoryRow[],
    filters: () => GenerationHistoryFilters,
    currentPage: () => number,
    userId: () => number | undefined,
) {
    const generations = ref<GenerationHistoryRow[]>(initialGenerations());

    useGenerationRealtimeSubscription(userId, (payload) => {
        const nextRow = toHistoryRow(payload.generation);
        const currentFilters = filters();
        const matches = matchesHistoryFilters(nextRow, currentFilters);
        const index = generations.value.findIndex((g) => g.id === nextRow.id);

        if (index >= 0) {
            const copy = [...generations.value];
            if (matches) {
                copy[index] = nextRow;
            } else {
                copy.splice(index, 1);
            }
            generations.value = copy;
            return;
        }

        if (currentPage() === 1 && matches) {
            generations.value = [nextRow, ...generations.value];
        }
    });

    watch(initialGenerations, (nextGens) => {
        generations.value = nextGens;
    });

    return {
        generations,
    };
}
