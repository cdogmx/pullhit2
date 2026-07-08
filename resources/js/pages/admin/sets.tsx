import { Head, router } from '@inertiajs/react';
import {
    Loader2,
    Package,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { AddSealedDialog } from '@/components/admin/add-sealed-dialog';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableColumn } from '@/components/admin/data-table';
import { EditSetDialog } from '@/components/admin/edit-set-dialog';
import { ManageSealedDialog } from '@/components/admin/manage-sealed-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { languageLabel } from '@/lib/format';
import type {
    AdminOption,
    AdminSet,
    CatalogItem,
    MissingReport,
    SetSearchResult,
} from '@/types';

const ALL = '__all__';

type Props = {
    sets: AdminSet[];
    sealedTypes: string[];
    languages: string[];
    productLines: AdminOption[];
};

export default function AdminSets({
    sets,
    sealedTypes,
    languages,
    productLines,
}: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SetSearchResult[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [missing, setMissing] = useState<{
        name: string;
        report: MissingReport;
    } | null>(null);
    const [checking, setChecking] = useState<number | null>(null);
    const [sealedSet, setSealedSet] = useState<AdminSet | null>(null);
    // Manage-sealed is keyed by id so it reflects fresh props after an edit/delete.
    const [managingSetId, setManagingSetId] = useState<number | null>(null);
    const [editingSealed, setEditingSealed] = useState<CatalogItem | null>(null);
    const [editingSet, setEditingSet] = useState<AdminSet | null>(null);
    const [creatingSet, setCreatingSet] = useState(false);
    const [lang, setLang] = useState('');
    const [brand, setBrand] = useState('');

    // Languages actually present, for the table's language filter.
    const setLanguages = useMemo(
        () =>
            Array.from(
                new Set(sets.map((s) => s.language).filter(Boolean)),
            ) as string[],
        [sets],
    );

    const shownSets = useMemo(
        () =>
            sets.filter(
                (s) =>
                    (!lang || s.language === lang) &&
                    (!brand || s.brand === brand),
            ),
        [sets, lang, brand],
    );

    // The live set behind the manage-sealed dialog (from props, so it refreshes).
    const managingSet =
        managingSetId != null
            ? (sets.find((s) => s.id === managingSetId) ?? null)
            : null;

    const deleteSealed = (item: CatalogItem) => {
        if (!confirm(`Delete "${item.name}"? This removes the product and its values.`)) {
            return;
        }

        router.delete(`/admin/cards/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Sealed product deleted.'),
        });
    };

    const removeSet = (set: AdminSet) => {
        const tail =
            set.items > 0
                ? ` This permanently deletes ${set.items} card(s), and removes them from every user's collection and wishlist.`
                : '';

        if (!confirm(`Delete ${set.name}?${tail}`)) {
            return;
        }

        router.delete(`/admin/sets/${set.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Deleted ${set.name}.`),
        });
    };

    const search = async () => {
        if (!query.trim()) {
            return;
        }

        setSearching(true);

        try {
            const res = await fetch(
                `/admin/sets/search?q=${encodeURIComponent(query)}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );
            const body = await res.json();
            setResults(body.results ?? []);
        } finally {
            setSearching(false);
        }
    };

    const importSet = (id: string) =>
        router.post(
            '/admin/sets/import',
            { set_id: id },
            {
                preserveScroll: true,
                onSuccess: () => toast.success(`Import queued: ${id}`),
            },
        );

    const resync = (set: AdminSet) =>
        router.post(
            `/admin/sets/${set.id}/resync`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(`Re-sync queued: ${set.name}`),
            },
        );

    const checkMissing = async (set: AdminSet) => {
        setChecking(set.id);

        try {
            const res = await fetch(`/admin/sets/${set.id}/missing`, {
                headers: { Accept: 'application/json' },
            });
            setMissing({ name: set.name, report: await res.json() });
        } finally {
            setChecking(null);
        }
    };

    const columns: DataTableColumn<AdminSet>[] = [
        {
            key: 'name',
            header: 'Set',
            sortable: true,
            value: (s) => s.name,
            cell: (s) => (
                <>
                    <span className="font-medium">{s.name}</span>{' '}
                    <span className="text-xs text-muted-foreground">
                        {s.ptcgio_id}
                        {s.series ? ` · ${s.series}` : ''}
                    </span>
                </>
            ),
        },
        {
            key: 'brand',
            header: 'Brand',
            sortable: true,
            value: (s) => s.brand,
            cell: (s) => (
                <span className="text-muted-foreground">{s.brand ?? '—'}</span>
            ),
        },
        {
            key: 'released',
            header: 'Released',
            sortable: true,
            value: (s) => s.released_at,
            cell: (s) => s.released_at ?? '—',
        },
        {
            key: 'language',
            header: 'Lang',
            sortable: true,
            value: (s) => s.language,
            cell: (s) => (s.language ? languageLabel(s.language) : '—'),
        },
        {
            key: 'items',
            header: 'Items',
            align: 'right',
            sortable: true,
            value: (s) => s.items,
        },
        {
            key: 'valued',
            header: 'Valued',
            align: 'right',
            sortable: true,
            value: (s) => s.valued,
        },
        {
            key: 'images',
            header: 'Images',
            align: 'right',
            sortable: true,
            value: (s) => s.images,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            cellClassName: 'whitespace-nowrap',
            cell: (s) => (
                <>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditingSet(s)}
                    >
                        <Pencil className="size-4" /> Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => checkMissing(s)}
                        disabled={checking === s.id}
                    >
                        {checking === s.id ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : null}
                        Check missing
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setManagingSetId(s.id)}
                    >
                        <Package className="size-4" /> Sealed ({s.sealed?.length ?? 0})
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => resync(s)}>
                        <RefreshCw className="size-4" /> Re-sync
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => removeSet(s)}
                        className="text-muted-foreground hover:text-red-600"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </>
            ),
        },
    ];

    return (
        <>
            <Head title="Admin · Sets" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <p className="text-sm text-muted-foreground">
                        Import sets from PokémonTCG.io, or create one by hand
                        and add cards afterwards.
                    </p>
                    <Button size="sm" onClick={() => setCreatingSet(true)}>
                        <Plus className="size-4" /> New set
                    </Button>
                </div>

                {/* Find + import */}
                <Card>
                    <CardContent className="space-y-3 pt-6">
                        <p className="text-sm font-medium">Import a set</p>
                        <div className="flex gap-2">
                            <Input
                                placeholder="Search by name (e.g. Surging Sparks) or paste a set id"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && search()}
                            />
                            <Button
                                onClick={search}
                                disabled={searching}
                                variant="secondary"
                            >
                                {searching ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Search className="size-4" />
                                )}
                                Search
                            </Button>
                            <Button
                                onClick={() => importSet(query.trim())}
                                disabled={!query.trim()}
                            >
                                Import id
                            </Button>
                        </div>

                        {results && (
                            <ul className="divide-y divide-border rounded-md border border-border">
                                {results.length === 0 && (
                                    <li className="p-3 text-sm text-muted-foreground">
                                        No matches.
                                    </li>
                                )}
                                {results.map((r) => (
                                    <li
                                        key={r.id}
                                        className="flex items-center justify-between gap-3 p-3 text-sm"
                                    >
                                        <span className="min-w-0 truncate">
                                            {r.name}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {r.id} · {r.series} · {r.total}{' '}
                                                cards
                                            </span>
                                        </span>
                                        {r.imported ? (
                                            <Badge variant="secondary">
                                                Imported
                                            </Badge>
                                        ) : (
                                            <Button
                                                size="sm"
                                                onClick={() => importSet(r.id)}
                                            >
                                                Import
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {/* Imported sets */}
                <Card>
                    <CardContent className="pt-6">
                        <DataTable
                            data={shownSets}
                            columns={columns}
                            getRowKey={(s) => s.id}
                            searchPlaceholder="Search sets…"
                            initialSort={{ key: 'released', dir: 'desc' }}
                            toolbar={
                                <>
                                    {productLines.length > 1 && (
                                        <Combobox
                                            triggerClassName="w-40"
                                            placeholder="All brands"
                                            searchPlaceholder="Search brands…"
                                            value={brand}
                                            onChange={setBrand}
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'All brands',
                                                },
                                                ...productLines.map((p) => ({
                                                    value: p.name,
                                                    label: p.name,
                                                })),
                                            ]}
                                        />
                                    )}
                                    {setLanguages.length > 1 && (
                                        <Select
                                            value={lang || ALL}
                                            onValueChange={(v) =>
                                                setLang(v === ALL ? '' : v)
                                            }
                                        >
                                            <SelectTrigger className="w-36">
                                                <SelectValue placeholder="All languages" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={ALL}>
                                                    All languages
                                                </SelectItem>
                                                {setLanguages.map((l) => (
                                                    <SelectItem
                                                        key={l}
                                                        value={l}
                                                    >
                                                        {languageLabel(l)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </>
                            }
                        />
                    </CardContent>
                </Card>
            </div>

            <ManageSealedDialog
                set={managingSet}
                open={managingSet !== null}
                onOpenChange={(o) => !o && setManagingSetId(null)}
                onAdd={() => {
                    const s = managingSet;
                    setManagingSetId(null);
                    setSealedSet(s);
                }}
                onEdit={(item) => {
                    setManagingSetId(null);
                    setEditingSealed(item);
                }}
                onDelete={deleteSealed}
            />

            {/* Create (from a set) */}
            <AddSealedDialog
                set={sealedSet}
                sealedTypes={sealedTypes}
                languages={languages}
                open={sealedSet !== null}
                onOpenChange={(o) => !o && setSealedSet(null)}
            />

            {/* Edit (an existing sealed product) */}
            <AddSealedDialog
                item={editingSealed}
                sealedTypes={sealedTypes}
                languages={languages}
                open={editingSealed !== null}
                onOpenChange={(o) => !o && setEditingSealed(null)}
            />

            <EditSetDialog
                set={creatingSet ? null : editingSet}
                productLines={productLines}
                languages={languages}
                open={editingSet !== null || creatingSet}
                onOpenChange={(o) => {
                    if (!o) {
                        setEditingSet(null);
                        setCreatingSet(false);
                    }
                }}
            />

            <Dialog
                open={missing !== null}
                onOpenChange={(o) => !o && setMissing(null)}
            >
                <DialogContent className="max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            Missing cards · {missing?.name}
                        </DialogTitle>
                    </DialogHeader>
                    {missing && (
                        <div className="space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                {missing.report.present} of{' '}
                                {missing.report.expected} imported ·{' '}
                                {missing.report.missing.length} missing
                            </p>
                            {missing.report.missing.length === 0 ? (
                                <p className="text-emerald-600 dark:text-emerald-400">
                                    Complete — nothing missing.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border rounded-md border border-border">
                                    {missing.report.missing.map((m) => (
                                        <li key={m.id} className="p-2">
                                            {m.number} · {m.name}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                ({m.id})
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminSets.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Sets', href: '/admin/sets' },
    ],
};
