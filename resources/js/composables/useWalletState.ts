import type { SharedData, WalletData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const liveWalletOverride = ref<WalletData | null>(null);
let inertiaResetBound = false;

function ensureInertiaResetListener(): void {
    if (inertiaResetBound || typeof window === 'undefined') {
        return;
    }

    inertiaResetBound = true;
    router.on('success', () => {
        liveWalletOverride.value = null;
    });
}

export function setLiveWallet(wallet: WalletData): void {
    ensureInertiaResetListener();
    liveWalletOverride.value = wallet;
}

export function clearLiveWallet(): void {
    liveWalletOverride.value = null;
}

export function useWalletState() {
    ensureInertiaResetListener();

    const page = usePage<SharedData>();
    const user = computed(() => page.props.auth?.user);

    const balanceNano = computed(() => liveWalletOverride.value?.balance_nanodollars ?? user.value?.balance_nanodollars ?? 0);
    const balanceFormatted = computed(() => liveWalletOverride.value?.balance_formatted ?? user.value?.balance_formatted ?? '$0.00');
    const reservedNano = computed(() => liveWalletOverride.value?.reserved_nanodollars ?? user.value?.reserved_nanodollars ?? 0);
    const reservedFormatted = computed(() => liveWalletOverride.value?.reserved_formatted ?? user.value?.reserved_formatted ?? '$0.00');

    return {
        user,
        balanceNano,
        balanceFormatted,
        reservedNano,
        reservedFormatted,
        setLiveWallet,
        clearLiveWallet,
    };
}
