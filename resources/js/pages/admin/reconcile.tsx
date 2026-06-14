import { Head, router } from '@inertiajs/react';
import { Check, ChevronDown, Loader2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

type SetRow = {
    set_id: number;
    set_name: string;
    total: number;
    counts: Record<string, number>;
};

type Change = {
    id: number;
    action: string;
    reason: string;
    label: string;
    attributes: Record<string, string>;
    ungraded: number | null;
    psa10: number | null;
};

type Props = { sets: SetRow[]; applied: number; pending: number };

const money = (cents: number | null) =>
    cents ? `$${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}` : '—';

const reload = { preserveScroll: true, only: ['sets', 'applied', 'pending'] };

export default function AdminReconcile({ sets, applied, pending }: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const [changes, setChanges] = useState<Change[]>([]);
    const [loading, setLoading] = useState(false);

    const expand = async (setId: number) => {
        if (open === setId) {
            setOpen(null);
            return;
        }
        setOpen(setId);
        setLoading(true);
        try {
            const res = await fetch(`/admin/reconcile/${setId}/changes`, {
                headers: { Accept: 'application/json' },
            });
            setChanges((await res.json()).changes ?? []);
        } finally {
            setLoading(false);
        }
    };

    const act = (url: string, data: Record<string, string | number> = {}) =>
        router.post(url, data, {
            ...reload,
            onSuccess: () => toast.success('Done'),
        });

    return (
        <>
            <Head title="Admin · Reconcile" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-4 text-sm">
                    <span className="text-muted-foreground">
                        PriceCharting reconciliation queue
                    </span>
                    <Badge variant="secondary">{applied.toLocaleString()} applied</Badge>
                    <Badge>{pending.toLocaleString()} pending</Badge>
                </div>

                <div className="space-y-2">
                    {sets.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                Nothing in the review queue. 🎉
                            </CardContent>
                        </Card>
                    )}

                    {sets.map((s) => (
                        <Card key={s.set_id}>
                            <CardContent className="pt-4">
                                <button
                                    type="button"
                                    onClick={() => expand(s.set_id)}
                                    className="flex w-full items-center justify-between gap-3 text-left"
                                >
                                    <span className="flex items-center gap-2">
                                        <ChevronDown
                                            className={`size-4 transition-transform ${open === s.set_id ? 'rotate-180' : ''}`}
                                        />
                                        <span className="font-medium">{s.set_name}</span>
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        {Object.entries(s.counts).map(([action, n]) => (
                                            <Badge key={action} variant="outline" className="text-[10px]">
                                                {action.replace('add_', '')}: {n}
                                            </Badge>
                                        ))}
                                    </span>
                                </button>

                                {open === s.set_id && (
                                    <div className="mt-4 space-y-3">
                                        <div className="flex flex-wrap gap-2">
                                            {Object.keys(s.counts).map((action) => (
                                                <Button
                                                    key={action}
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        act('/admin/reconcile/approve-batch', {
                                                            set_id: s.set_id,
                                                            action,
                                                        })
                                                    }
                                                >
                                                    <Check className="size-4" /> Approve all{' '}
                                                    {action.replace('add_', '')}
                                                </Button>
                                            ))}
                                        </div>

                                        {loading ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <ul className="divide-y divide-border rounded-md border border-border text-sm">
                                                {changes.map((c) => (
                                                    <li
                                                        key={c.id}
                                                        className="flex items-center justify-between gap-3 p-2"
                                                    >
                                                        <span className="min-w-0 truncate">
                                                            <Badge
                                                                variant="outline"
                                                                className="mr-2 text-[10px]"
                                                            >
                                                                {c.action.replace('add_', '')}
                                                            </Badge>
                                                            {c.label}
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                raw {money(c.ungraded)} · PSA10{' '}
                                                                {money(c.psa10)} · {c.reason}
                                                            </span>
                                                        </span>
                                                        <span className="flex shrink-0 gap-1">
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="size-7"
                                                                onClick={() =>
                                                                    act(`/admin/reconcile/${c.id}/approve`)
                                                                }
                                                                aria-label="Approve"
                                                            >
                                                                <Check className="size-4 text-emerald-600" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="size-7"
                                                                onClick={() =>
                                                                    act(`/admin/reconcile/${c.id}/skip`)
                                                                }
                                                                aria-label="Skip"
                                                            >
                                                                <X className="size-4 text-muted-foreground" />
                                                            </Button>
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </>
    );
}

AdminReconcile.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Reconcile', href: '/admin/reconcile' },
    ],
};
