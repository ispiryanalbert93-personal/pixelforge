import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    current_generation?: Generation | null;
}

export interface SharedData extends PageProps {
    name: string;
    is_development: boolean;
    quote?: { message: string; author: string };
    auth: Auth;
    flash?: FlashMessages;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    balance_nanodollars?: number;
    balance_usd?: string;
    balance_formatted?: string;
    reserved_nanodollars?: number;
    reserved_usd?: string;
    reserved_formatted?: string;
}

export interface GenerationUsage {
    input_text_tokens?: number;
    input_image_tokens?: number;
    output_tokens?: number;
    reasoning_tokens?: number;
    total_tokens?: number;
}

export type GenerationStatusValue = 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: number;
    pixelforge_id: string | null;
    status: GenerationStatusValue;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    usage: GenerationUsage | null;
    estimated_min_user_cost_nano: number;
    estimated_user_cost_nano: number;
    estimated_max_user_cost_nano: number;
    estimated_min_user_cost_usd: string;
    estimated_user_cost_usd: string;
    estimated_max_user_cost_usd: string;
    estimated_user_cost_formatted: string;
    reserved_user_cost_nano: number;
    reserved_user_cost_usd: string;
    actual_user_cost_nano: number | null;
    actual_user_cost_usd: string | null;
    actual_user_cost_formatted: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
}

export interface GenerationHistoryRow {
    id: number;
    pixelforge_id: string | null;
    status: GenerationStatusValue;
    prompt: string;
    size: string;
    quality: string;
    reference_images_count?: number;
    reference_images?: string[];
    usage: GenerationUsage | null;
    estimated_min_user_cost_usd: string;
    estimated_user_cost_formatted: string;
    estimated_max_user_cost_usd: string;
    reserved_user_cost_usd: string;
    actual_user_cost_usd: string | null;
    actual_user_cost_formatted: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string | null;
}

export interface GenerationHistoryFilters {
    search: string | null;
    status: 'all' | 'active' | 'succeeded' | 'failed';
    per_page: number;
}

export type WalletTransactionType = 'topup' | 'hold' | 'charge' | 'release';

export interface WalletTransaction {
    id: number;
    generation_id: number | null;
    type: WalletTransactionType;
    amount_nanodollars: number;
    amount_usd: string;
    amount_formatted: string;
    balance_after_nanodollars: number;
    balance_after_usd: string;
    reserved_after_nanodollars: number;
    reserved_after_usd: string;
    description: string;
    created_at: string | null;
}

export interface WalletData {
    balance_nanodollars: number;
    balance_usd: string;
    balance_formatted: string;
    reserved_nanodollars: number;
    reserved_usd: string;
    reserved_formatted: string;
}

export interface PricingRates {
    input_text_tokens: string;
    input_image_tokens: string;
    output_tokens: string;
    reasoning_tokens: string;
}

export interface PricingSizeOption {
    value: string;
    label: string;
    is_wide_or_tall: boolean;
}

export interface PricingQualityOption {
    value: string;
    label: string;
    reasoning_tokens: {
        min: number;
        midpoint: number;
        max: number;
    };
    output_tokens: Record<string, number>;
}

export interface PricingConfig {
    rates: PricingRates;
    characters_per_text_token: number;
    text_token_variance: number;
    tokens_per_reference_image: number;
    max_reference_images: number;
    markup_percent: number;
    sizes: PricingSizeOption[];
    qualities: PricingQualityOption[];
}

export interface AdminFinancialMetrics {
    credit_spend_nano: number;
    credit_spend_usd: string;
    credit_spend_formatted: string;
    provider_cost_nano: number;
    provider_cost_usd: string;
    provider_cost_formatted: string;
    profit_nano: number;
    profit_usd: string;
    profit_formatted: string;
}

export interface AdminUsageDayRow extends AdminFinancialMetrics {
    date: string;
    succeeded_count: number;
    failed_count: number;
    remote_generations: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
    remote_cost_nano: number;
    remote_cost_usd: string;
    remote_cost_formatted: string;
    delta_nano: number;
    delta_usd: string;
    delta_formatted: string;
    matches_remote: boolean | null;
}

export interface AdminUsageTotals extends AdminFinancialMetrics {
    succeeded_count: number;
    failed_count: number;
    active_count: number;
    remote_generations: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
    remote_cost_nano: number;
    remote_cost_usd: string;
    remote_cost_formatted: string;
    delta_nano: number;
    delta_usd: string;
    delta_formatted: string;
    matches_remote: boolean | null;
}

export interface AdminUserReportRow extends AdminFinancialMetrics {
    id: number;
    name: string;
    email: string;
    succeeded_count: number;
    failed_count: number;
    active_count: number;
    balance_nanodollars: number;
    balance_usd: string;
    balance_formatted: string;
    reserved_nanodollars: number;
    reserved_usd: string;
    reserved_formatted: string;
}

export interface AdminDailyByUserRow extends AdminFinancialMetrics {
    date: string;
    user_id: number;
    user_name: string;
    user_email: string;
    succeeded_count: number;
    failed_count: number;
}

export interface AdminReport {
    available: boolean;
    error: string | null;
    days: AdminUsageDayRow[];
    total: AdminUsageTotals;
    users: AdminUserReportRow[];
    daily_by_user: AdminDailyByUserRow[];
    filters: {
        start_date: string | null;
        end_date: string | null;
        effective_start_date: string | null;
        effective_end_date: string | null;
    };
}

export type BreadcrumbItemType = BreadcrumbItem;
