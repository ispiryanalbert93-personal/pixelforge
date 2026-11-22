<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const page = usePage<SharedData>();

const presetAmounts = [5, 10, 25, 50];

const topupForm = useForm({
    amount_usd: 10,
});

const appName = computed(() => page.props.name);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            topupForm.clearErrors();
        }
    },
);

function submitTopup(): void {
    topupForm.post(route('wallet.store'), {
        preserveScroll: true,
        onSuccess: () => {
            topupForm.clearErrors();
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(val) => emit('update:open', val)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Top Up Wallet</DialogTitle>
                <DialogDescription>
                    Add prepaid USD credits to your {{ appName }} wallet. Funds never expire and failed jobs are never billed.
                </DialogDescription>
            </DialogHeader>
            <form @submit.prevent="submitTopup" class="space-y-4">
                <div class="space-y-2">
                    <Label>Quick Presets</Label>
                    <div class="grid grid-cols-4 gap-2">
                        <Button
                            v-for="preset in presetAmounts"
                            :key="preset"
                            type="button"
                            :variant="Number(topupForm.amount_usd) === preset ? 'default' : 'outline'"
                            size="sm"
                            @click="topupForm.amount_usd = preset"
                        >
                            +${{ preset.toFixed(2) }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="sidebar_amount_usd">Custom Amount (USD)</Label>
                    <Input id="sidebar_amount_usd" v-model="topupForm.amount_usd" type="number" min="0.50" max="1000" step="0.50" required />
                    <p v-if="topupForm.errors.amount_usd" class="text-xs text-destructive">
                        {{ topupForm.errors.amount_usd }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancel</Button>
                    <Button type="submit" :disabled="topupForm.processing">
                        <Loader2 v-if="topupForm.processing" class="size-4 animate-spin" />
                        Add ${{ Number(topupForm.amount_usd || 0).toFixed(2) }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
