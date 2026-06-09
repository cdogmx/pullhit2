export type CatalogItem = {
    id: number;
    name: string;
    number: string | null;
    item_type: string;
    language: string | null;
    rarity: string | null;
    variant: string | null;
    image_url: string | null;
    base_key: string | null;
    variants_count?: number;
    attributes?: Record<string, string | number | null>;
    variants?: CatalogItem[];
    market_value?: MarketValue | null;
    market_values?: MarketValue[];
    set?: { slug: string; name: string; code: string | null } | null;
    product_line?: { slug: string; name: string } | null;
    vertical?: { slug: string; name: string } | null;
};

export type MarketValue = {
    state_key: string;
    label: string;
    condition: string | null;
    grade: number | null;
    grading_company?: { slug: string; name: string } | null;
    /** Money fields are integer minor units (cents). */
    median: number;
    p25: number;
    p75: number;
    low: number;
    high: number;
    n_sales: number;
    confidence: number;
    confidence_label: string;
    is_estimated: boolean;
    trend_30d: number | null;
    currency: string;
    computed_at: string | null;
};

export type CatalogFilterOptions = {
    verticals: { slug: string; name: string }[];
    product_lines: { slug: string; name: string }[];
    sets: { slug: string; name: string; code: string | null }[];
    item_types: string[];
    languages: string[];
    rarities: string[];
    variants: string[];
};

export type CatalogFilters = {
    q: string | null;
    vertical: string | null;
    product_line: string | null;
    set: string | null;
    item_type: string | null;
    language: string | null;
    rarity: string | null;
    variant: string | null;
    sort: string;
    direction: string;
    group: boolean;
    view: 'grid' | 'list';
    per_page: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        to: number | null;
        last_page: number;
        per_page: number;
        total: number;
        links: PaginationLink[];
    };
};
