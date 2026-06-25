import { Head } from '@inertiajs/react';
import { Pencil, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EditHoldingDialog } from '@/components/collection/edit-holding-dialog';
import { ProfileLinks } from '@/components/profile-links';
import type { ProfileSocials } from '@/components/profile-links';
import { OwnerLink } from '@/components/shared/owner-link';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cardHref, formatMoney } from '@/lib/format';
import type { GradingCompanyOption } from '@/types';

type Holding = {
    catalog_item_id: number | null;
    url?: string | null;
    name: string | null;
    number: string | null;
    image_url: string | null;
    set: string | null;
    state_label: string;
    quantity: number;
    unit_value: number | null;
    market_value: number | null;
    currency: string;
    // Owner-only edit fields (present when canEdit).
    id?: number;
    condition?: string | null;
    grade?: number | null;
    grading_company?: { id: number } | null;
    is_for_sale?: boolean;
    notes?: string | null;
    folder?: string | null;
};

type Props = {
    owner: {
        username: string;
        avatar?: string | null;
        bio?: string | null;
    } & ProfileSocials;
    collection: { name: string; slug: string; is_default: boolean };
    summary: {
        total_value: number;
        item_count: number;
        card_count: number;
        currency: string;
    };
    holdings: Holding[];
    canEdit?: boolean;
    collections?: { id: number; name: string; slug: string }[];
    gradingCompanies?: GradingCompanyOption[];
};

const ALL = '__all__';

const SORTS = [
    { value: 'value_desc', label: 'Value: high to low' },
    { value: 'value_asc', label: 'Value: low to high' },
    { value: 'name', label: 'Name: A to Z' },
    { value: 'quantity', label: 'Quantity' },
];

/** Sort nulls last regardless of direction. */
function byValue(a: number | null, b: number | null, dir: 1 | -1): number {
    if (a == null && b == null) {
        return 0;
    }

    if (a == null) {
        return 1;
    }

    if (b == null) {
        return -1;
    }

    return (a - b) * dir;
}

/**
 * Public, read-only view of a user's collection. Shows cards + market values,
 * but never cost basis or P&L (those stay private). Chrome from AppShell.
 * Sorting/filtering is client-side over the fully server-rendered list.
 */
export default function PublicCollection({
    owner,
    collection,
    summary,
    holdings,
    canEdit = false,
    collections = [],
    gradingCompanies = [],
}: Props) {
    const title = collection.is_default
        ? `${owner.username}'s collection`
        : `${owner.username} · ${collection.name}`;

    const [q, setQ] = useState('');
    const [set, setSet] = useState(ALL);
    const [sort, setSort] = useState('value_desc');
    const [editing, setEditing] = useState<Holding | null>(null);

    // Sets present in this collection, for the filter dropdown.
    const sets = useMemo(
        () =>
            Array.from(
                new Set(
                    holdings.map((h) => h.set).filter((s): s is string => !!s),
                ),
            ).sort((a, b) => a.localeCompare(b)),
        [holdings],
    );

    const visible = useMemo(() => {
        const needle = q.trim().toLowerCase();

        const filtered = holdings.filter((h) => {
            if (set !== ALL && h.set !== set) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return (
                (h.name ?? '').toLowerCase().includes(needle) ||
                (h.number ?? '').toLowerCase().includes(needle) ||
                (h.set ?? '').toLowerCase().includes(needle)
            );
        });

        const sorted = [...filtered];
        sorted.sort((a, b) => {
            switch (sort) {
                case 'value_asc':
                    return byValue(a.market_value, b.market_value, 1);
                case 'name':
                    return (a.name ?? '').localeCompare(b.name ?? '');
                case 'quantity':
                    return b.quantity - a.quantity;
                default:
                    return byValue(a.market_value, b.market_value, -1);
            }
        });

        return sorted;
    }, [holdings, q, set, sort]);

    return (
        <>
            <Head title={title} />

            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <OwnerLink
                        username={owner.username}
                        avatar={owner.avatar}
                    />
                    {owner.bio && (
                        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                            {owner.bio}
                        </p>
                    )}
                    <ProfileLinks
                        location={owner.location}
                        website={owner.website}
                        x_handle={owner.x_handle}
                        instagram_handle={owner.instagram_handle}
                        className="mt-2"
                    />
                    <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">
                        {title}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {summary.card_count.toLocaleString()} cards ·{' '}
                        {summary.item_count.toLocaleString()} holdings ·{' '}
                        <span className="font-medium text-foreground">
                            {formatMoney(summary.total_value, summary.currency)}
                        </span>{' '}
                        total value
                    </p>
                </div>

                {holdings.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border py-20 text-center text-sm text-muted-foreground">
                        This collection is empty.
                    </div>
                ) : (
                    <>
                        {/* Controls */}
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Search cards"
                                    className="w-52 pl-8"
                                />
                            </div>

                            {sets.length > 1 && (
                                <Select value={set} onValueChange={setSet}>
                                    <SelectTrigger className="w-44">
                                        <SelectValue placeholder="All sets" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            All sets
                                        </SelectItem>
                                        {sets.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {s}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}

                            <Select value={sort} onValueChange={setSort}>
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SORTS.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            Sort: {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <span className="ml-auto text-xs text-muted-foreground">
                                {visible.length.toLocaleString()} of{' '}
                                {holdings.length.toLocaleString()}
                            </span>
                        </div>

                        {visible.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-border py-20 text-center text-sm text-muted-foreground">
                                No cards match your filters.
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                {visible.map((h, i) => (
                                    <div
                                        key={`${h.catalog_item_id}-${i}`}
                                        className="relative"
                                    >
                                        {canEdit && h.id != null && (
                                            <button
                                                type="button"
                                                onClick={() => setEditing(h)}
                                                className="absolute top-2 right-2 z-10 flex size-7 items-center justify-center rounded-md border border-border bg-background/90 text-muted-foreground shadow-sm hover:text-foreground"
                                                aria-label="Edit holding"
                                                title="Edit holding"
                                            >
                                                <Pencil className="size-3.5" />
                                            </button>
                                        )}
                                        <a
                                            href={cardHref(h)}
                                            className="group block overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring"
                                        >
                                            <div className="aspect-[3/4] overflow-hidden bg-muted">
                                                {h.image_url ? (
                                                    <img
                                                        src={h.image_url}
                                                        alt={h.name ?? ''}
                                                        loading="lazy"
                                                        className="size-full object-contain transition-transform group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                                                        No image
                                                    </div>
                                                )}
                                            </div>
                                            <div className="space-y-1 p-3">
                                                <p
                                                    className="truncate text-sm font-medium"
                                                    title={h.name ?? ''}
                                                >
                                                    {h.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {h.set}
                                                    {h.number
                                                        ? ` · ${h.number}`
                                                        : ''}
                                                </p>
                                                <div className="flex items-center justify-between gap-2 text-xs">
                                                    <span className="truncate text-muted-foreground">
                                                        {h.state_label}
                                                        {h.quantity > 1
                                                            ? ` · ×${h.quantity}`
                                                            : ''}
                                                    </span>
                                                    {h.market_value != null && (
                                                        <span className="shrink-0 font-semibold">
                                                            {formatMoney(
                                                                h.market_value,
                                                                h.currency,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>

            <EditHoldingDialog
                holding={
                    editing && editing.id != null
                        ? {
                              id: editing.id,
                              name: editing.name ?? 'Holding',
                              condition: editing.condition ?? null,
                              grade: editing.grade ?? null,
                              grading_company: editing.grading_company ?? null,
                              quantity: editing.quantity,
                              is_for_sale: editing.is_for_sale ?? false,
                              notes: editing.notes ?? null,
                              folder: editing.folder ?? null,
                          }
                        : null
                }
                collections={collections
                    .filter((col) => col.slug !== collection.slug)
                    .map((col) => ({ id: col.id, name: col.name }))}
                gradingCompanies={gradingCompanies}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />
        </>
    );
}
