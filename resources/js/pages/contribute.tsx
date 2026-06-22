import { Head, Link, useForm } from '@inertiajs/react';
import { Award, Trophy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type Level = {
    level: number;
    name: string;
    points: number;
    next_at: number | null;
    into_level: number;
    to_next: number | null;
};

type Report = {
    id: number;
    kind: 'card' | 'set';
    name: string;
    details: Record<string, string> | null;
    status: 'pending' | 'approved' | 'rejected';
    review_note: string | null;
    created_at: string | null;
};

type Props = {
    reports: Report[];
    points: { missing_card: number; missing_set: number; edit_suggestion: number };
    level: Level;
    monthlyEntries: number;
};

const STATUS_STYLE: Record<string, string> = {
    pending: 'text-amber-600 dark:text-amber-400',
    approved: 'text-emerald-600 dark:text-emerald-400',
    rejected: 'text-red-600 dark:text-red-400',
};

export default function Contribute({
    reports,
    points,
    level,
    monthlyEntries,
}: Props) {
    const [kind, setKind] = useState<'card' | 'set'>('card');
    const form = useForm({
        kind: 'card',
        name: '',
        number: '',
        set: '',
        brand: '',
        language: '',
        notes: '',
        source_url: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, kind }));
        form.post('/contribute', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Report submitted — thanks for contributing!');
                form.reset();
            },
        });
    };

    const reward = kind === 'card' ? points.missing_card : points.missing_set;

    return (
        <>
            <Head title="Contribute" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                {/* Standing */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-lg bg-primary/15 text-primary">
                            <Award className="size-5" />
                        </span>
                        <div>
                            <p className="text-sm font-semibold">
                                {level.name} · {level.points.toLocaleString()} pts
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {monthlyEntries.toLocaleString()} giveaway{' '}
                                {monthlyEntries === 1 ? 'entry' : 'entries'} this
                                month
                                {level.to_next != null &&
                                    ` · ${level.to_next} to next level`}
                            </p>
                        </div>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/rankings">
                            <Trophy className="size-4" /> Rankings
                        </Link>
                    </Button>
                </div>

                <div>
                    <h1 className="text-xl font-bold tracking-tight">
                        Report a missing card or set
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Help us complete the catalog. Accepted reports earn
                        points — {points.missing_card} for a card,{' '}
                        {points.missing_set} for a set. Spotted a wrong detail on
                        a card?{' '}
                        <span className="text-foreground">
                            Use “Suggest an edit” on the card
                        </span>{' '}
                        (+{points.edit_suggestion}).
                    </p>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="inline-flex rounded-md border border-border p-0.5 text-sm">
                                {(['card', 'set'] as const).map((k) => (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={() => setKind(k)}
                                        className={cn(
                                            'rounded px-3 py-1 capitalize transition-colors',
                                            kind === k
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        Missing {k}
                                    </button>
                                ))}
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    {kind === 'card' ? 'Card name' : 'Set name'}
                                </Label>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder={
                                        kind === 'card'
                                            ? 'e.g. Charizard ex'
                                            : 'e.g. Surging Sparks'
                                    }
                                />
                                {form.errors.name && (
                                    <p className="text-xs text-red-600">
                                        {form.errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                {kind === 'card' && (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label className="text-xs">
                                                Number
                                            </Label>
                                            <Input
                                                value={form.data.number}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. 199/191"
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label className="text-xs">Set</Label>
                                            <Input
                                                value={form.data.set}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'set',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. Surging Sparks"
                                            />
                                        </div>
                                    </>
                                )}
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">Brand / game</Label>
                                    <Input
                                        value={form.data.brand}
                                        onChange={(e) =>
                                            form.setData('brand', e.target.value)
                                        }
                                        placeholder="e.g. Pokémon"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">Language</Label>
                                    <Input
                                        value={form.data.language}
                                        onChange={(e) =>
                                            form.setData(
                                                'language',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. English"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Reference link (optional)
                                </Label>
                                <Input
                                    value={form.data.source_url}
                                    onChange={(e) =>
                                        form.setData(
                                            'source_url',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="A link to the card/set so we can verify"
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">Notes (optional)</Label>
                                <Textarea
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                    placeholder="Anything that helps us add it"
                                    maxLength={1000}
                                />
                            </div>

                            <Button type="submit" disabled={form.processing}>
                                Submit report (+{reward} if accepted)
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {reports.length > 0 && (
                    <div>
                        <h2 className="mb-2 text-sm font-semibold">
                            Your reports
                        </h2>
                        <div className="overflow-hidden rounded-xl border border-border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {reports.map((r) => (
                                        <tr
                                            key={r.id}
                                            className="border-b border-border/60 last:border-0"
                                        >
                                            <td className="px-3 py-2">
                                                <span className="font-medium">
                                                    {r.name}
                                                </span>
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2 text-[10px] capitalize"
                                                >
                                                    {r.kind}
                                                </Badge>
                                                {r.details?.number && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {r.details.number}
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right text-xs font-medium capitalize',
                                                    STATUS_STYLE[r.status],
                                                )}
                                            >
                                                {r.status}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Contribute.layout = {
    breadcrumbs: [{ title: 'Contribute', href: '/contribute' }],
};
