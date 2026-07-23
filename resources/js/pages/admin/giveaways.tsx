import { Head, router, useForm } from '@inertiajs/react';
import { Gift, Sparkles, Trophy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type Giveaway = {
    id: number;
    period: string;
    period_label: string;
    title: string;
    prize: string;
    image: string | null;
    status: 'open' | 'drawn';
    winner: string | null;
    winner_entries: number | null;
    total_entries: number | null;
    entrant_count: number | null;
    drawn_at: string | null;
};

type Props = {
    giveaways: Giveaway[];
    currentMonth: string;
    currentPool: { entrants: number; entries: number };
    hasCurrent: boolean;
};

export default function AdminGiveaways({
    giveaways,
    currentMonth,
    currentPool,
    hasCurrent,
}: Props) {
    const form = useForm({
        period: currentMonth,
        title: '',
        prize: '',
        image_path: '',
        description: '',
    });
    const [confirmDraw, setConfirmDraw] = useState<Giveaway | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<Giveaway | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/giveaways', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Giveaway created.');
                form.reset('title', 'prize', 'image_path', 'description');
            },
        });
    };

    const draw = (g: Giveaway) => {
        setBusy(true);
        router.post(
            `/admin/giveaways/${g.id}/draw`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Winner drawn.');
                    setConfirmDraw(null);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const remove = (g: Giveaway) => {
        setBusy(true);
        router.delete(`/admin/giveaways/${g.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Deleted.');
                setConfirmDelete(null);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Head title="Admin · Giveaways" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                {/* Current month pool */}
                <Card className="border-primary/30 bg-primary/5">
                    <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 items-center justify-center rounded-lg bg-primary/15 text-primary">
                                <Sparkles className="size-5" />
                            </span>
                            <div>
                                <p className="text-sm font-semibold">
                                    {currentMonth} pool
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {currentPool.entrants.toLocaleString()}{' '}
                                    entrants ·{' '}
                                    {currentPool.entries.toLocaleString()} total
                                    entries (points)
                                </p>
                            </div>
                        </div>
                        {hasCurrent && (
                            <Badge variant="secondary" className="text-xs">
                                Giveaway exists for {currentMonth}
                            </Badge>
                        )}
                    </CardContent>
                </Card>

                {/* Create */}
                <Card>
                    <CardContent className="pt-6">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                            <Gift className="size-4 text-primary" />
                            New giveaway
                        </h2>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">
                                        Period (YYYY-MM)
                                    </Label>
                                    <Input
                                        value={form.data.period}
                                        onChange={(e) =>
                                            form.setData(
                                                'period',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="2026-06"
                                    />
                                    {form.errors.period && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.period}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">Title</Label>
                                    <Input
                                        value={form.data.title}
                                        onChange={(e) =>
                                            form.setData('title', e.target.value)
                                        }
                                        placeholder="June 2026 Giveaway"
                                    />
                                    {form.errors.title && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.title}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-xs">Prize</Label>
                                <Input
                                    value={form.data.prize}
                                    onChange={(e) =>
                                        form.setData('prize', e.target.value)
                                    }
                                    placeholder="Surging Sparks booster box"
                                />
                                {form.errors.prize && (
                                    <p className="text-xs text-red-600">
                                        {form.errors.prize}
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Prize image (optional)
                                </Label>
                                <ImageUploadField
                                    value={form.data.image_path}
                                    onChange={(url) =>
                                        form.setData('image_path', url)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Description (optional)
                                </Label>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Anything entrants should know"
                                    maxLength={1000}
                                />
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Create giveaway
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* List */}
                <div className="space-y-3">
                    {giveaways.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No giveaways yet.
                            </CardContent>
                        </Card>
                    )}
                    {giveaways.map((g) => (
                        <Card key={g.id}>
                            <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row sm:items-start sm:justify-between">
                                <div className="flex min-w-0 gap-3">
                                    {g.image && (
                                        <img
                                            src={g.image}
                                            alt={g.prize}
                                            className="size-14 shrink-0 rounded-md border border-border bg-muted object-contain"
                                        />
                                    )}
                                    <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {g.title}
                                        </span>
                                        <span
                                            className={cn(
                                                'text-xs font-medium capitalize',
                                                g.status === 'drawn'
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-amber-600 dark:text-amber-400',
                                            )}
                                        >
                                            {g.status}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {g.period_label} · {g.prize}
                                    </p>
                                    {g.status === 'drawn' && (
                                        <p className="mt-1 inline-flex items-center gap-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                            <Trophy className="size-3.5" />
                                            {g.winner ?? 'no eligible entrants'}
                                            {g.winner && (
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    · {g.winner_entries} of{' '}
                                                    {g.total_entries} entries (
                                                    {g.entrant_count} entrants)
                                                </span>
                                            )}
                                        </p>
                                    )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    {g.status === 'open' && (
                                        <Button
                                            size="sm"
                                            onClick={() => setConfirmDraw(g)}
                                        >
                                            Draw winner
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="text-red-600 hover:text-red-600"
                                        onClick={() => setConfirmDelete(g)}
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDraw !== null}
                onOpenChange={(o) => !o && setConfirmDraw(null)}
                title={
                    confirmDraw
                        ? `Draw a winner for ${confirmDraw.period_label}?`
                        : 'Draw winner?'
                }
                description="This notifies the winner and can't be undone."
                confirmLabel="Draw winner"
                busy={busy}
                onConfirm={() => confirmDraw && draw(confirmDraw)}
            />

            <ConfirmDialog
                open={confirmDelete !== null}
                onOpenChange={(o) => !o && setConfirmDelete(null)}
                title={
                    confirmDelete
                        ? `Delete the ${confirmDelete.period_label} giveaway?`
                        : 'Delete giveaway?'
                }
                description="This cannot be undone."
                confirmLabel="Delete"
                destructive
                busy={busy}
                onConfirm={() => confirmDelete && remove(confirmDelete)}
            />
        </>
    );
}

AdminGiveaways.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Giveaways', href: '/admin/giveaways' },
    ],
};
