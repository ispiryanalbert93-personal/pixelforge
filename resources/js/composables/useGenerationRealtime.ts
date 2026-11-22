import { clearLiveWallet, setLiveWallet } from '@/composables/useWalletState';
import { getEcho } from '@/lib/echo';
import type { Generation, GenerationHistoryRow, WalletData } from '@/types';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export type RealtimeTransport = 'websocket' | 'idle';

export interface GenerationUpdatedBroadcastPayload {
    generation: Generation;
    wallet: WalletData;
    has_active_generations: boolean;
}

interface PusherStateChange {
    previous: string;
    current: string;
}

interface PusherConnectionLike {
    state?: string;
    bind: (event: string, callback: (data?: unknown) => void) => void;
    unbind: (event: string, callback: (data?: unknown) => void) => void;
}

const EMPTY_WALLET: WalletData = {
    balance_nanodollars: 0,
    balance_usd: '0.00',
    balance_formatted: '$0.00',
    reserved_nanodollars: 0,
    reserved_usd: '0.00',
    reserved_formatted: '$0.00',
};

export function useGenerationRealtime(
    initialGenerations: () => GenerationHistoryRow[],
    initialWallet: (() => WalletData) | undefined,
    initialHasActive: () => boolean,
    userId: () => number | undefined,
) {
    const generations = ref<GenerationHistoryRow[]>(initialGenerations());
    const wallet = ref<WalletData>(initialWallet ? initialWallet() : EMPTY_WALLET);
    const hasActiveGenerations = ref<boolean>(initialHasActive());
    const wsHealthy = ref(false);

    const transport = computed<RealtimeTransport>(() => (hasActiveGenerations.value && wsHealthy.value ? 'websocket' : 'idle'));

    let subscribedChannel: string | null = null;
    let boundConnection: PusherConnectionLike | null = null;
    let stateChangeHandler: ((data?: unknown) => void) | null = null;
    let errorHandler: (() => void) | null = null;

    function upsertGeneration(nextGen: Generation): void {
        const index = generations.value.findIndex((g) => g.id === nextGen.id);
        if (index >= 0) {
            const copy = [...generations.value];
            copy[index] = nextGen;
            generations.value = copy;
            return;
        }

        generations.value = [nextGen, ...generations.value];
    }

    function teardownRealtime(): void {
        if (boundConnection) {
            if (stateChangeHandler) {
                boundConnection.unbind('state_change', stateChangeHandler);
            }
            if (errorHandler) {
                boundConnection.unbind('error', errorHandler);
            }
            boundConnection = null;
        }

        stateChangeHandler = null;
        errorHandler = null;

        if (subscribedChannel) {
            const echo = getEcho();
            echo?.leave(subscribedChannel);
            subscribedChannel = null;
        }

        wsHealthy.value = false;
    }

    function setupRealtime(uid: number | undefined): void {
        teardownRealtime();

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
            upsertGeneration(payload.generation);
            wallet.value = payload.wallet;
            hasActiveGenerations.value = payload.has_active_generations;
            setLiveWallet(payload.wallet);
        });

        const connector = echo.connector as unknown as { pusher?: { connection?: PusherConnectionLike } };
        const connection = connector?.pusher?.connection;

        if (!connection) {
            return;
        }

        boundConnection = connection;
        wsHealthy.value = connection.state === 'connected';

        stateChangeHandler = (raw?: unknown) => {
            const stateChange = raw as PusherStateChange | undefined;
            const current = stateChange?.current;

            if (current === 'connected') {
                wsHealthy.value = true;
                return;
            }

            if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
                wsHealthy.value = false;
            }
        };

        errorHandler = () => {
            wsHealthy.value = false;
        };

        connection.bind('state_change', stateChangeHandler);
        connection.bind('error', errorHandler);
    }

    watch([initialGenerations, () => initialWallet?.(), initialHasActive], ([nextGens, nextWallet, nextActive]) => {
        generations.value = nextGens;
        if (nextWallet) {
            wallet.value = nextWallet;
        }
        hasActiveGenerations.value = nextActive;
        clearLiveWallet();
    });

    watch(
        userId,
        (uid) => {
            setupRealtime(uid);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        teardownRealtime();
    });

    return {
        generations,
        wallet,
        hasActiveGenerations,
        transport,
    };
}
