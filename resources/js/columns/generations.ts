import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { isImageExpired, statusBadgeClass } from '@/lib/generation';
import { formatDateTime } from '@/lib/pricing';
import type { GenerationHistoryRow } from '@/types';
import type { ColumnDef } from '@tanstack/vue-table';
import { AlertCircle, ExternalLink, ImageOff, Loader2, RefreshCw } from 'lucide-vue-next';
import { h } from 'vue';

export interface GenerationColumnCallbacks {
    onPreview: (generation: GenerationHistoryRow) => void;
    onSync: (generation: GenerationHistoryRow) => void;
    isSyncing: (generationId: number) => boolean;
    isImageBroken: (generation: GenerationHistoryRow) => boolean;
    markImageBroken: (generationId: number) => void;
}

export function createGenerationColumns(callbacks: GenerationColumnCallbacks): ColumnDef<GenerationHistoryRow>[] {
    return [
        {
            id: 'preview',
            header: 'Preview',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;
                const expired = isImageExpired(gen.image_url_expires_at);
                const broken = callbacks.isImageBroken(gen);

                if (gen.status === 'succeeded' && gen.image_url && !expired && !broken) {
                    return h(
                        'button',
                        {
                            type: 'button',
                            class: 'block size-12 cursor-zoom-in overflow-hidden rounded-md border bg-muted transition-opacity hover:opacity-85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            title: 'Preview generated image',
                            'aria-label': 'Preview generated image',
                            onClick: () => callbacks.onPreview(gen),
                        },
                        [
                            h('img', {
                                src: gen.image_url,
                                alt: gen.prompt,
                                class: 'h-full w-full object-cover',
                                onError: () => callbacks.markImageBroken(gen.id),
                            }),
                        ],
                    );
                }

                if (gen.status === 'queued' || gen.status === 'processing') {
                    return h('div', { class: 'flex size-12 items-center justify-center rounded-md border bg-muted/40' }, [
                        h(Loader2, { class: 'size-4 animate-spin text-primary' }),
                    ]);
                }

                if (gen.status === 'failed') {
                    return h('div', { class: 'flex size-12 items-center justify-center rounded-md border border-destructive/20 bg-destructive/5' }, [
                        h(AlertCircle, { class: 'size-4 text-destructive' }),
                    ]);
                }

                return h(
                    'div',
                    {
                        class: 'flex size-12 items-center justify-center rounded-md border bg-muted/40',
                        title: expired ? 'Signed image URL expired (24h TTL)' : 'Image unavailable',
                    },
                    [h(ImageOff, { class: 'size-4 text-muted-foreground' })],
                );
            },
        },
        {
            accessorKey: 'prompt',
            header: 'Prompt',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;
                const metaParts: string[] = [];
                const refCount = gen.reference_images_count ?? gen.reference_images?.length ?? 0;

                if (refCount > 0) {
                    metaParts.push(`${refCount} ref`);
                }
                if (gen.pixelforge_id) {
                    metaParts.push(gen.pixelforge_id);
                }

                return h('div', { class: 'max-w-[340px] space-y-1' }, [
                    h('p', { class: 'line-clamp-2 text-sm font-medium leading-snug', title: gen.prompt }, gen.prompt),
                    gen.status === 'failed' && gen.error_message
                        ? h('p', { class: 'line-clamp-1 text-xs text-destructive' }, gen.error_message)
                        : null,
                    metaParts.length > 0 ? h('p', { class: 'truncate font-mono text-[11px] text-muted-foreground' }, metaParts.join(' · ')) : null,
                ]);
            },
        },
        {
            accessorKey: 'status',
            header: 'Status',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;

                return h('div', { class: 'flex flex-col items-start gap-1' }, [
                    h(
                        Badge,
                        {
                            variant: 'outline',
                            class: [statusBadgeClass(gen.status), 'capitalize'],
                        },
                        () => gen.status,
                    ),
                    gen.status === 'failed'
                        ? h('span', { class: 'text-[10px] font-medium text-emerald-600 dark:text-emerald-400' }, 'Free ($0.00)')
                        : null,
                ]);
            },
        },
        {
            accessorKey: 'size',
            header: 'Specs',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;

                return h('div', { class: 'flex flex-wrap items-center gap-1' }, [
                    h(Badge, { variant: 'secondary', class: 'font-mono text-[10px]' }, () => gen.size),
                    h(Badge, { variant: 'secondary', class: 'font-mono text-[10px] capitalize' }, () => gen.quality),
                ]);
            },
        },
        {
            accessorKey: 'estimated_user_cost_nano',
            header: 'Estimated',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;

                return h('div', { class: 'font-mono text-xs' }, [
                    h('div', { class: 'font-medium text-foreground' }, gen.estimated_user_cost_formatted),
                    h(
                        'div',
                        { class: 'text-[10px] text-muted-foreground' },
                        `$${gen.estimated_min_user_cost_usd} – $${gen.estimated_max_user_cost_usd}`,
                    ),
                ]);
            },
        },
        {
            accessorKey: 'actual_user_cost_nano',
            header: 'Actual Charged',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;

                if (gen.status === 'succeeded') {
                    return h('div', { class: 'font-mono text-xs' }, [
                        h('div', { class: 'font-semibold text-foreground' }, gen.actual_user_cost_formatted ?? `$${gen.actual_user_cost_usd}`),
                        h('div', { class: 'text-[10px] text-muted-foreground' }, `$${gen.actual_user_cost_usd}`),
                    ]);
                }

                if (gen.status === 'failed') {
                    return h('div', { class: 'font-mono text-xs' }, [
                        h('div', { class: 'font-semibold text-emerald-600 dark:text-emerald-400' }, '$0.00'),
                        h('div', { class: 'text-[10px] text-muted-foreground' }, 'Not billed'),
                    ]);
                }

                return h('div', { class: 'font-mono text-xs' }, [
                    h('div', { class: 'text-amber-700 dark:text-amber-300' }, 'Pending'),
                    h('div', { class: 'text-[10px] text-muted-foreground' }, `Hold: $${gen.reserved_user_cost_usd}`),
                ]);
            },
        },
        {
            id: 'tokens',
            header: 'Tokens',
            enableSorting: false,
            cell: ({ row }) => {
                const usage = row.original.usage;

                if (!usage) {
                    return h('span', { class: 'text-xs text-muted-foreground' }, '—');
                }

                const inputTokens = (usage.input_text_tokens ?? 0) + (usage.input_image_tokens ?? 0);

                return h('div', { class: 'space-y-0.5 font-mono text-[11px] text-muted-foreground' }, [
                    h('div', {}, `In: ${inputTokens} · Out: ${usage.output_tokens ?? 0}`),
                    h('div', {}, `Reason: ${usage.reasoning_tokens ?? 0}`),
                ]);
            },
        },
        {
            accessorKey: 'created_at',
            header: 'Created',
            enableSorting: false,
            cell: ({ row }) => {
                return h('span', { class: 'whitespace-nowrap text-xs text-muted-foreground' }, formatDateTime(row.original.created_at));
            },
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) => {
                const gen = row.original;

                if (gen.status === 'queued' || gen.status === 'processing') {
                    const syncing = callbacks.isSyncing(gen.id);

                    return h(
                        Button,
                        {
                            type: 'button',
                            variant: 'ghost',
                            size: 'icon',
                            class: 'size-8',
                            disabled: syncing,
                            title: 'Sync status from Pixelforge',
                            'aria-label': 'Sync status from Pixelforge',
                            onClick: () => callbacks.onSync(gen),
                        },
                        () => h(RefreshCw, { class: ['size-4', syncing ? 'animate-spin' : ''] }),
                    );
                }

                if (gen.status !== 'succeeded' || !gen.image_url || isImageExpired(gen.image_url_expires_at)) {
                    return null;
                }

                return h(
                    Button,
                    {
                        variant: 'ghost',
                        size: 'icon',
                        class: 'size-8',
                        asChild: true,
                    },
                    () =>
                        h(
                            'a',
                            {
                                href: gen.image_url!,
                                target: '_blank',
                                rel: 'noopener noreferrer',
                                title: 'Open generated image',
                                'aria-label': 'Open generated image',
                            },
                            [h(ExternalLink, { class: 'size-4' })],
                        ),
                );
            },
        },
    ];
}
