import { Head, router } from '@inertiajs/react';
import { Eye, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EditCardDialog } from '@/components/admin/edit-card-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AdminCard, AdminCardFilters, AdminCardOptions } from '@/types';

type Props = {
    items: AdminCard[];
    pagination: { page: number; last_page: number; total: number };
    options: AdminCardOptions;
    filters: AdminCardFilters;
};

const SORTS = [
    { value: 'name', label: 'Name' },
    { value: 'number', label: 'Number' },
    { value: 'views', label: 'Most viewed' },
    { value: 'updated', label: 'Recently updated' },
];

const ALL = '__all__';

export default function AdminCards({ items, pagination, options, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const [editing, setEditing] = useState<AdminCard | null>(null);

    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/cards',
            { ...filters, q, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const onFilter = (key: keyof AdminCardFilters, value: string) =>
        apply({ [key]: value === ALL ? '' : value });

    const remove = (card: AdminCard) => {
        if (!confirm(`Delete ${card.name} ${card.number ?? ''}?`)) {
            return;
        }

        router.delete(`/admin/cards/${card.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Card deleted.'),
        });
    };

    return (
        <>
            <Head title="Admin · Cards" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {/* Filter bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        placeholder="Search name or number"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && apply()}
                        className="w-56"
                    />

                    <FilterSelect
                        placeholder="All sets"
                        value={filters.set}
                        onChange={(v) => onFilter('set', v)}
                        options={options.sets.map((s) => ({ value: s.slug, label: s.name }))}
                    />
                    <FilterSelect
                        placeholder="All rarities"
                        value={filters.rarity}
                        onChange={(v) => onFilter('rarity', v)}
                        options={options.rarities.map((r) => ({ value: r, label: r }))}
                    />
                    <FilterSelect
                        placeholder="All variants"
                        value={filters.variant}
                        onChange={(v) => onFilter('variant', v)}
                        options={options.variants.map((v) => ({ value: v, label: v }))}
                    />
                    <FilterSelect
                        placeholder="All languages"
                        value={filters.language}
                        onChange={(v) => onFilter('language', v)}
                        options={options.languages.map((l) => ({ value: l, label: l.toUpperCase() }))}
                    />

                    <Select value={filters.sort} onValueChange={(v) => onFilter('sort', v)}>
                        <SelectTrigger className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {SORTS.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    Sort: {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {(filters.set ||
                        filters.rarity ||
                        filters.variant ||
                        filters.language ||
                        filters.q) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setQ('');
                                router.get(
                                    '/admin/cards',
                                    { sort: filters.sort },
                                    { preserveScroll: true, replace: true },
                                );
                            }}
                        >
                            Clear
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <p className="mb-2 text-xs text-muted-foreground">
                            {pagination.total.toLocaleString()} cards
                        </p>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">Card</th>
                                    <th className="py-2 pr-3 font-medium">Set</th>
                                    <th className="py-2 pr-3 font-medium">Rarity</th>
                                    <th className="py-2 pr-3 font-medium">Variant</th>
                                    <th className="py-2 pr-3 text-right font-medium">Views</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((c) => (
                                    <tr key={c.id} className="border-b border-border/60 last:border-0">
                                        <td className="py-2 pr-3">
                                            <div className="flex items-center gap-2">
                                                {c.image_url && (
                                                    <img
                                                        src={c.image_url}
                                                        alt=""
                                                        className="h-10 w-auto rounded"
                                                        loading="lazy"
                                                    />
                                                )}
                                                <div className="min-w-0">
                                                    <p className="font-medium">{c.name}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {c.number}
                                                        {c.language ? ` · ${c.language.toUpperCase()}` : ''}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-2 pr-3 text-muted-foreground">{c.set}</td>
                                        <td className="py-2 pr-3">{c.rarity}</td>
                                        <td className="py-2 pr-3">
                                            {c.variant && (
                                                <Badge variant="secondary" className="text-[10px]">
                                                    {c.variant}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 pr-3 text-right text-muted-foreground">
                                            <span className="inline-flex items-center gap-1">
                                                <Eye className="size-3" />
                                                {c.views}
                                            </span>
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setEditing(c)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => remove(c)}
                                                className="text-muted-foreground hover:text-red-600"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {pagination.last_page > 1 && (
                            <div className="mt-3 flex items-center justify-between text-sm">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page <= 1}
                                    onClick={() => apply({ page: pagination.page - 1 })}
                                >
                                    Previous
                                </Button>
                                <span className="text-muted-foreground">
                                    Page {pagination.page} of {pagination.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page >= pagination.last_page}
                                    onClick={() => apply({ page: pagination.page + 1 })}
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <EditCardDialog
                card={editing}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />
        </>
    );
}

function FilterSelect({
    placeholder,
    value,
    onChange,
    options,
}: {
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value || ALL} onValueChange={onChange}>
            <SelectTrigger className="w-40">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

AdminCards.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Cards', href: '/admin/cards' },
    ],
};
