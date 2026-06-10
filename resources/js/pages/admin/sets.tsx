import { Head, router } from '@inertiajs/react';
import { Loader2, RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { AdminSet, MissingReport, SetSearchResult } from '@/types';

type Props = { sets: AdminSet[] };

export default function AdminSets({ sets }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SetSearchResult[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [missing, setMissing] = useState<{ name: string; report: MissingReport } | null>(null);
    const [checking, setChecking] = useState<number | null>(null);

    const search = async () => {
        if (!query.trim()) {
            return;
        }

        setSearching(true);

        try {
            const res = await fetch(`/admin/sets/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            });
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
            { preserveScroll: true, onSuccess: () => toast.success(`Import queued: ${id}`) },
        );

    const resync = (set: AdminSet) =>
        router.post(
            `/admin/sets/${set.id}/resync`,
            {},
            { preserveScroll: true, onSuccess: () => toast.success(`Re-sync queued: ${set.name}`) },
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

    return (
        <>
            <Head title="Admin · Sets" />
            <div className="flex flex-1 flex-col gap-6 p-4">
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
                            <Button onClick={search} disabled={searching} variant="secondary">
                                {searching ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Search className="size-4" />
                                )}
                                Search
                            </Button>
                            <Button onClick={() => importSet(query.trim())} disabled={!query.trim()}>
                                Import id
                            </Button>
                        </div>

                        {results && (
                            <ul className="divide-y divide-border rounded-md border border-border">
                                {results.length === 0 && (
                                    <li className="p-3 text-sm text-muted-foreground">No matches.</li>
                                )}
                                {results.map((r) => (
                                    <li
                                        key={r.id}
                                        className="flex items-center justify-between gap-3 p-3 text-sm"
                                    >
                                        <span className="min-w-0 truncate">
                                            {r.name}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {r.id} · {r.series} · {r.total} cards
                                            </span>
                                        </span>
                                        {r.imported ? (
                                            <Badge variant="secondary">Imported</Badge>
                                        ) : (
                                            <Button size="sm" onClick={() => importSet(r.id)}>
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
                    <CardContent className="overflow-x-auto pt-6">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">Set</th>
                                    <th className="py-2 pr-3 text-right font-medium">Items</th>
                                    <th className="py-2 pr-3 text-right font-medium">Valued</th>
                                    <th className="py-2 pr-3 text-right font-medium">Images</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {sets.map((s) => (
                                    <tr key={s.id} className="border-b border-border/60 last:border-0">
                                        <td className="py-2 pr-3">
                                            <span className="font-medium">{s.name}</span>{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {s.ptcgio_id}
                                                {s.released_at ? ` · ${s.released_at}` : ''}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-3 text-right">{s.items}</td>
                                        <td className="py-2 pr-3 text-right">{s.valued}</td>
                                        <td className="py-2 pr-3 text-right">{s.images}</td>
                                        <td className="py-2 text-right whitespace-nowrap">
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
                                                onClick={() => resync(s)}
                                            >
                                                <RefreshCw className="size-4" /> Re-sync
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={missing !== null} onOpenChange={(o) => !o && setMissing(null)}>
                <DialogContent className="max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Missing cards · {missing?.name}</DialogTitle>
                    </DialogHeader>
                    {missing && (
                        <div className="space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                {missing.report.present} of {missing.report.expected} imported ·{' '}
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
