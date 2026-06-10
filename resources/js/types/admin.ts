export type AdminStats = {
    sets: number;
    items: number;
    valued: number;
    images: number;
    users: number;
    premium: number;
    admins: number;
};

export type AdminSet = {
    id: number;
    name: string;
    code: string | null;
    series: string | null;
    released_at: string | null;
    ptcgio_id: string | null;
    items: number;
    valued: number;
    images: number;
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
};

export type MissingReport = {
    expected: number;
    present: number;
    missing: { id: string; number: string | null; name: string | null }[];
};
