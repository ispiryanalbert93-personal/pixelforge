<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calculator,
    Coins,
    Sparkles,
    Webhook
} from 'lucide-vue-next';
import type { SharedData } from '@/types';

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const page = usePage<SharedData>();
const appName = computed(() => page.props.name);
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <Head :title="`${appName} | Transparent AI Image Studio`" />

    <div class="min-h-screen bg-background text-foreground">
        <!-- Top Navigation -->
        <header class="border-b">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link :href="route('home')" class="flex items-center gap-2.5">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <Sparkles class="size-4" />
                    </div>
                    <span class="text-base font-semibold tracking-tight">{{ appName }}</span>
                </Link>

                <nav class="flex items-center gap-2">
                    <Button v-if="user" as-child>
                        <Link :href="route('dashboard')">
                            Open Studio <ArrowRight class="size-4" />
                        </Link>
                    </Button>

                    <template v-else>
                        <Button variant="ghost" as-child>
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button as-child>
                            <Link :href="route('register')">Get Started</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <main class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
            <div class="mx-auto max-w-3xl text-center">
                <Badge variant="outline" class="mb-4 gap-1.5 px-3 py-1 text-xs">
                    <Sparkles class="size-3.5 text-primary" />
                    Powered by Pixelforge Token Billing
                </Badge>

                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    AI Image Generation with Exact, Upfront Token Pricing
                </h1>
                <p class="mt-4 text-lg text-muted-foreground">
                    {{ appName }} calculates every text, reference image, output, and reasoning token before you submit - reserving only the maximum
                    estimate and settling to exact cost upon completion.
                </p>
            </div>

            <!-- Feature Grid -->
            <div class="mt-16 grid gap-6 md:grid-cols-3">
                <Card>
                    <CardHeader>
                        <div class="mb-2 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Calculator class="size-5" />
                        </div>
                        <CardTitle class="text-lg">
                            Instant Upfront Cost Estimator
                        </CardTitle>
                        <CardDescription>
                            Know your exact min–max nanodollar range before generating.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Real-time client and server calculations account for prompt tokens, up to 4 reference images (+765 tokens each), output
                        resolution, reasoning tier, and transparent +25% platform markup.
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div
                            class="mb-2 flex size-10 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                        >
                            <Coins class="size-5" />
                        </div>
                        <CardTitle class="text-lg">
                            Safe Credit Holds & Zero-Risk Failures
                        </CardTitle>
                        <CardDescription>
                            Atomic ledger accounting with automatic refunds on failures.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        The maximum estimate is held safely in an immutable double-entry ledger at submission. Unspent reserves are released
                        immediately on completion, and failed jobs are billed <strong class="text-foreground">$0.00</strong>.
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="mb-2 flex size-10 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                            <Webhook class="size-5" />
                        </div>
                        <CardTitle class="text-lg">
                            Resilient Webhooks
                        </CardTitle>
                        <CardDescription>
                            HMAC-verified webhook ingestion with live WebSocket updates.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Idempotent webhook processing, duplicate/out-of-order protection,
                        and instant status updates.
                    </CardContent>
                </Card>
            </div>
        </main>
    </div>
</template>
