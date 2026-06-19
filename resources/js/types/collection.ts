export type Holding = {
    id: number;
    state_key: string;
    state_label: string;
    condition: string | null;
    grade: number | null;
    grading_company?: { id: number; slug: string; name: string } | null;
    quantity: number;
    is_for_sale: boolean;
    notes: string | null;
    folder: string | null;
    /** Money fields are integer minor units (cents); null = no computed value. */
    unit_value: number | null;
    market_value: number | null;
    cost_basis: number;
    unrealized_gain: number | null;
    currency: string;
    catalog_item?: {
        id: number;
        name: string;
        display_name?: string;
        number: string | null;
        image_url: string | null;
        set: { name: string; code: string | null } | null;
    };
};

export type PortfolioSummary = {
    total_value: number;
    total_cost: number;
    cost_basis_valued: number;
    unrealized_gain: number;
    unrealized_pct: number | null;
    item_count: number;
    card_count: number;
    valued_count: number;
    currency: string;
};

export type Allocation = {
    label: string;
    brand: string | null;
    value: number;
    pct: number;
};

export type PortfolioMover = {
    id: number;
    name: string | null;
    number: string | null;
    state: string;
    gain: number | null;
    pct: number | null;
};

export type GradingCompanyOption = {
    id: number;
    slug: string;
    name: string;
    scale_max: number;
    supports_half_grades: boolean;
};
