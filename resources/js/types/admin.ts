export type AdminStats = {
    sets: number;
    items: number;
    valued: number;
    images: number;
    users: number;
    premium: number;
    admins: number;
};

export type SetHealth = {
    name: string;
    code: string | null;
    items: number;
    valued: number;
    images: number;
};

export type AdminSet = {
    id: number;
    name: string;
    brand: string | null;
    code: string | null;
    series: string | null;
    language: string | null;
    released_at: string | null;
    ptcgio_id: string | null;
    logo_url: string | null;
    description: string | null;
    items: number;
    valued: number;
    images: number;
};

export type AdminBrand = {
    id: number;
    slug: string;
    name: string;
    vertical: string | null;
    logo_url: string | null;
    description: string | null;
    sets: number;
    items: number;
};

export type AdminVertical = {
    id: number;
    name: string;
};

/** A {id, name} pick-list entry used by the catalog create forms. */
export type AdminOption = {
    id: number;
    name: string;
};

export type AdminSetOption = {
    id: number;
    name: string;
    brand: string | null;
    series: string | null;
    language: string | null;
    code: string | null;
};

export type AdminCardCreateOptions = {
    sets: AdminSetOption[];
    languages: string[];
    variants: string[];
};

export type SetSearchResult = {
    id: string;
    name: string;
    series: string | null;
    released_at: string | null;
    total: number | null;
    imported: boolean;
};

export type AdminCard = {
    id: number;
    name: string;
    number: string | null;
    language: string | null;
    set: string | null;
    image_url: string | null;
    primary_image_path: string | null;
    rarity: string | null;
    variant: string | null;
    illustrator: string | null;
    hp: number | null;
    type: string | null;
    views: number;
    updated_at: string | null;
};

export type AdminCardOptions = {
    sets: { slug: string; name: string; code: string | null }[];
    rarities: string[];
    variants: string[];
    languages: string[];
};

export type AdminCardFilters = {
    q: string;
    set: string;
    rarity: string;
    variant: string;
    language: string;
    sort: string;
};

export type AdminPagination = {
    page: number;
    last_page: number;
    total: number;
};

export type AdminUser = {
    id: number;
    name: string;
    username: string | null;
    email: string;
    tier: string;
    is_admin: boolean;
    banned_at: string | null;
    banned_reason: string | null;
    credits: number;
    has_subscription: boolean;
    cancel_scheduled: boolean;
    renews_at: string | null;
    transactions_count: number;
    lifetime_amount: number;
    created_at: string | null;
};

export type AdminUserFilters = {
    q: string;
    tier: string;
    role: string;
    sort: string;
};

/** One detection inside a scan log (AI read + top catalog match). */
export type AdminScanResult = {
    name: string | null;
    number: string | null;
    source: string | null;
    match: {
        id: number | null;
        name: string | null;
        number: string | null;
        set: string | null;
        image_url: string | null;
        url: string | null;
    } | null;
};

export type AdminUserScan = {
    id: number;
    mode: string;
    image_url: string | null;
    card_count: number;
    ai_reads: number;
    cache_hits: number;
    results: AdminScanResult[];
    created_at: string | null;
};

export type AdminUserSession = {
    ip_address: string | null;
    user_agent: string | null;
    last_activity: string | null;
};

export type AdminUserDetail = AdminUser & {
    avatar: string | null;
    email_verified_at: string | null;
    provider: string | null;
    last_seen_at: string | null;
};

export type AdminUserStats = {
    collection_items: number;
    collections: number;
    wishlist_items: number;
    wishlists: number;
    followers: number;
    following: number;
    scans: number;
    contributions: number;
    card_reports: number;
    contribution_points: number;
    monthly_entries: number;
    level: string | null;
};

export type AdminUserLinks = {
    profile: string;
    collection: string;
    wishlist: string;
} | null;

export type AdminTransaction = {
    id: number;
    type: string;
    status: string;
    description: string | null;
    amount: number | null;
    currency: string | null;
    tier: string | null;
    credits: number | null;
    dodo_payment_id: string | null;
    created_at: string | null;
    user: { id: number; name: string; email: string } | null;
};

export type AdminTransactionFilters = {
    q: string;
    type: string;
    status: string;
};

export type AdminTransactionTotals = {
    gross: number;
    refunded: number;
    count: number;
};

export type MissingReport = {
    expected: number;
    present: number;
    missing: { id: string; number: string | null; name: string | null }[];
};
