export type EbayListing = {
    title: string;
    price_cents: number;
    currency: string;
    image: string | null;
    condition: string | null;
    url: string;
};

export type EbayOption = { label: string; group: string; url: string };

export type CardListings = {
    listings: EbayListing[];
    ebay_options: EbayOption[];
    configured: boolean;
};

export type PricePoint = { t: string; price: number };

export type OwnedState = {
    state_label: string;
    quantity: number;
    market_value: number | null;
    cost_basis: number;
    unrealized_gain: number | null;
};

export type RetailerLink = {
    retailer: string;
    url: string;
    price_cents: number | null;
};

export type CatalogItem = {
    id: number;
    name: string;
    /** Display name with edition/variant/error qualifiers, e.g. "Charizard (1st Edition)". */
    display_name?: string;
    number: string | null;
    item_type: string;
    language: string | null;
    rarity: string | null;
    variant: string | null;
    image_url: string | null;
    base_key: string | null;
    /** MSRP in cents; release date; "where to buy" links (each with its price). */
    msrp?: number | null;
    released_at?: string | null;
    retailer_links?: RetailerLink[] | null;
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
    /** Share of comps from the single most-frequent seller (null = too few to judge). */
    top_seller_share?: number | null;
    is_estimated: boolean;
    half_life_days: number;
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
    editions: string[];
};

export type CatalogFilters = {
    q: string | null;
    vertical: string | null;
    product_line: string | null;
    series: string | null;
    set: string | null;
    subset: string | null;
    item_type: string | null;
    language: string | null;
    rarity: string | null;
    variant: string | null;
    edition: string | null;
    sort: string;
    direction: string;
    group: boolean;
    all: boolean;
    view: 'grid' | 'list';
    per_page: number;
};

export type SaleObservation = {
    price: number;
    currency: string;
    venue: string;
    observed_at: string | null;
    sold_on: string | null;
    title: string | null;
    url: string | null;
    is_outlier: boolean;
    is_synthetic: boolean;
};

export type PriceBreakdown = {
    value: MarketValue | null;
    observations: SaleObservation[];
    sources: Record<string, number>;
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
