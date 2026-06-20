export type WishlistRow = {
    id: number;
    /** Money in integer minor units (cents). */
    target_price: number | null;
    current_value: number | null;
    below_target: boolean;
    notes: string | null;
    currency: string;
    catalog_item?: {
        id: number;
        name: string;
        display_name?: string;
        number: string | null;
        url?: string | null;
        image_url: string | null;
        set: { name: string; code: string | null } | null;
    };
};

export type WishlistSummary = {
    item_count: number;
    below_target: number;
    currency: string;
};
