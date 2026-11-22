export interface PaginatedPayloadMeta {
    total: number;
    current_page: number;
    last_page: number;
    per_page?: number;
    from?: number | null;
    to?: number | null;
}

export interface PaginatedPayloadLinks {
    url: string | null;
    label: string;
    active: boolean;
}

export interface SortOption {
    label: string;
    sort: string;
    icon: string;
}

export interface StatusFilterOption {
    label: string;
    value: string;
    color?: string | null;
    icon?: string | null;
    count?: number | null;
}

export interface TypeFilterOption {
    label: string;
    value: string;
    icon?: string | null;
}
