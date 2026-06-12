import { Head, Link, useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMoney } from '@/lib/format';

type ImportableRow = {
    catalog_item_id: number;
    name: string;
    set: string | null;
    number: string | null;
    condition: string | null;
    grading_company_id: number | null;
    grade: number | null;
    state_label: string;
    quantity: number;
    unit_cost: number;
    acquired_at: string | null;
    folder: string | null;
    notes: string | null;
};

type Props = {
    importable?: ImportableRow[];
    counts?: {
        parsed: number;
        matched: number;
        ambiguous: number;
        unmatched: number;
    };
    skipped?: { bucket: string; count: number }[];
};

export default function ImportCollection({
    importable,
    counts,
    skipped,
}: Props) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const commit = useForm<{ rows: ImportableRow[] }>({ rows: importable ?? [] });

    const submitUpload = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post('/collection/import/preview', { forceFormData: true });
    };

    const submitImport = () => {
        commit.post('/collection/import');
    };

    return (
        <>
            <Head title="Import collection" />

            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <h1 className="text-2xl font-bold tracking-tight">
                    Import your collection
                </h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    Upload a <span className="font-medium">PriceCharting</span>{' '}
                    collection export (CSV). We match each card to our catalog,
                    preserve your folders and cost basis, and import the
                    confident matches.
                </p>

                {!counts ? (
                    <form
                        onSubmit={submitUpload}
                        className="mt-8 rounded-xl border border-border bg-card p-6"
                    >
                        <label
                            htmlFor="csv"
                            className="text-sm font-medium"
                        >
                            PriceCharting CSV
                        </label>
                        <input
                            id="csv"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) =>
                                upload.setData('file', e.target.files?.[0] ?? null)
                            }
                            className="mt-2 block w-full text-sm text-muted-foreground file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-foreground hover:file:bg-primary/90"
                        />
                        {upload.errors.file && (
                            <p className="mt-2 text-sm text-destructive">
                                {upload.errors.file}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="mt-4"
                            disabled={!upload.data.file || upload.processing}
                        >
                            <Upload className="size-4" />
                            Preview import
                        </Button>
                    </form>
                ) : (
                    <div className="mt-8 space-y-6">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Stat label="Rows" value={counts.parsed} />
                            <Stat
                                label="Importable"
                                value={counts.matched}
                                emphasis
                            />
                            <Stat label="Ambiguous" value={counts.ambiguous} />
                            <Stat label="Unmatched" value={counts.unmatched} />
                        </div>

                        <p className="text-sm text-muted-foreground">
                            We import the {counts.matched} confident matches.
                            Ambiguous printings and cards from sets we don&rsquo;t
                            carry yet are skipped — re-import after they&rsquo;re
                            added.
                        </p>

                        {importable && importable.length > 0 && (
                            <div className="overflow-hidden rounded-xl border border-border">
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <thead className="sticky top-0 bg-muted text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">
                                                    Card
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    State
                                                </th>
                                                <th className="px-3 py-2 text-right font-medium">
                                                    Qty
                                                </th>
                                                <th className="px-3 py-2 text-right font-medium">
                                                    Cost
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border">
                                            {importable.map((row, i) => (
                                                <tr key={i}>
                                                    <td className="px-3 py-2">
                                                        <div className="font-medium">
                                                            {row.name}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {row.set}
                                                            {row.number
                                                                ? ` · ${row.number}`
                                                                : ''}
                                                            {row.folder
                                                                ? ` · ${row.folder}`
                                                                : ''}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2 text-muted-foreground">
                                                        {row.state_label}
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        {row.quantity}
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-muted-foreground">
                                                        {row.unit_cost
                                                            ? formatMoney(
                                                                  row.unit_cost,
                                                              )
                                                            : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {skipped && skipped.length > 0 && (
                            <div className="rounded-xl border border-dashed border-border p-4">
                                <h3 className="text-sm font-semibold">
                                    Skipped — sets we don&rsquo;t carry yet
                                </h3>
                                <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                                    {skipped.map((s) => (
                                        <li key={s.bucket}>
                                            <span className="tabular-nums">
                                                {s.count}
                                            </span>{' '}
                                            · {s.bucket}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex items-center gap-3">
                            <Button
                                onClick={submitImport}
                                disabled={
                                    !importable ||
                                    importable.length === 0 ||
                                    commit.processing
                                }
                            >
                                Import {counts.matched} cards
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href="/collection/import">
                                    Upload a different file
                                </Link>
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function Stat({
    label,
    value,
    emphasis,
}: {
    label: string;
    value: number;
    emphasis?: boolean;
}) {
    return (
        <Card>
            <CardHeader className="pb-1">
                <CardTitle className="text-xs font-medium text-muted-foreground">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <span
                    className={
                        emphasis
                            ? 'text-2xl font-bold text-primary'
                            : 'text-2xl font-bold'
                    }
                >
                    {value.toLocaleString()}
                </span>
            </CardContent>
        </Card>
    );
}
