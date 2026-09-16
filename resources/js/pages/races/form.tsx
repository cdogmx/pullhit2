import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Trash2, X } from 'lucide-react';
import { useState } from 'react';
import type { CardHit } from '@/components/marketplace/card-picker';
import { CardPicker } from '@/components/marketplace/card-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type PickedCard = {
    id: number;
    name: string;
    number: string | null;
    set: string | null;
    thumb: string | null;
};

type Source =
    | { type: 'set'; slug: string; name?: string }
    | { type: 'series'; name: string; line?: string | null }
    | { type: 'brand'; slug: string; name?: string }
    | { type: 'cards'; ids: number[]; cards?: PickedCard[] }
    | { type: 'collection'; id: number; name?: string | null }
    | { type: 'wishlist'; id: number; name?: string | null };

type Props = {
    race: {
        slug: string;
        name: string;
        description: string | null;
        sources: Source[];
        options: { top?: number; window?: number };
        is_public: boolean;
    } | null;
    prefill?: {
        name: string;
        is_public: boolean;
        sources: Source[];
    } | null;
    options: {
        brands: { slug: string; name: string }[];
        sets: { slug: string; name: string; brand: string | null }[];
        series: { name: string; line: string | null; brand: string | null }[];
    };
};

function sourceLabel(s: Source): string {
    switch (s.type) {
        case 'set':
            return `Set — ${s.name ?? s.slug}`;
        case 'series':
            return `Series — ${s.name}`;
        case 'brand':
            return `Brand — ${s.name ?? s.slug}`;
        case 'cards':
            return `${s.ids.length} hand-picked card${s.ids.length === 1 ? '' : 's'}`;
        case 'collection':
            return `Collection — ${s.name ?? s.id}`;
        case 'wishlist':
            return `Wishlist — ${s.name ?? s.id}`;
    }
}

export default function RaceForm({ race, prefill, options }: Props) {
    const editing = race !== null;

    // A prefill only ever starts a new race; editing one must not be rewritten
    // by a stray ?collection= in the address bar.
    const start = editing ? null : prefill;

    const [sources, setSources] = useState<Source[]>(
        race?.sources ?? start?.sources ?? [],
    );
    const [picked, setPicked] = useState<PickedCard[]>(
        (
            race?.sources.find((s) => s.type === 'cards') as
                | { cards?: PickedCard[] }
                | undefined
        )?.cards ?? [],
    );

    const [confirming, setConfirming] = useState(false);

    // sources is not a useForm field (it is assembled on submit), so its
    // validation error arrives on the page rather than on the form.
    const pageErrors = (usePage().props.errors ?? {}) as Record<string, string>;

    const { data, setData, processing, errors } = useForm({
        name: race?.name ?? start?.name ?? '',
        description: race?.description ?? '',
        is_public: race?.is_public ?? start?.is_public ?? true,
        top: String(race?.options.top ?? 30),
        window: String(race?.options.window ?? 7),
    });

    const addCard = (card: CardHit | null) => {
        if (!card || picked.some((p) => p.id === card.id)) {
            return;
        }

        setPicked([
            ...picked,
            {
                id: card.id,
                name: card.name,
                number: card.number,
                set: card.set,
                thumb: card.thumb,
            },
        ]);
    };

    const submit = () => {
        // The hand-picked cards are one source among the rest, rebuilt on save
        // so the list on screen is the list that gets stored.
        const payload: Source[] = [
            ...sources.filter((s) => s.type !== 'cards'),
            ...(picked.length > 0
                ? [{ type: 'cards' as const, ids: picked.map((p) => p.id) }]
                : []),
        ];

        const body = {
            name: data.name,
            description: data.description,
            is_public: data.is_public,
            sources: payload,
            options: { top: Number(data.top), window: Number(data.window) },
        };

        if (editing) {
            router.put(`/races/${race.slug}`, body);
        } else {
            router.post('/races', body);
        }
    };

    return (
        <>
            <Head title={editing ? 'Edit race' : 'Build a race'} />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-end justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">
                        {editing ? 'Edit race' : 'Build a race'}
                    </h1>
                    {editing && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setConfirming(true)}
                                aria-label="Delete this race"
                            >
                                <Trash2 className="size-4" />
                            </Button>
                            <ConfirmDialog
                                open={confirming}
                                onOpenChange={setConfirming}
                                title="Delete this race?"
                                description="The link stops working for anyone you shared it with."
                                confirmLabel="Delete"
                                destructive
                                onConfirm={() =>
                                    router.delete(`/races/${race.slug}`)
                                }
                            />
                        </>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="Chase cards of the 30th"
                    />
                    {errors.name && (
                        <p className="text-xs text-red-600">{errors.name}</p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">Description</Label>
                    <Textarea
                        id="description"
                        rows={2}
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="What this race is about."
                    />
                </div>

                <div className="grid gap-2">
                    <Label>What is racing</Label>

                    {sources.filter((s) => s.type !== 'cards').length > 0 && (
                        <ul className="flex flex-wrap gap-2">
                            {sources
                                .filter((s) => s.type !== 'cards')
                                .map((s, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-1 rounded-full border border-border px-3 py-1 text-xs"
                                    >
                                        {sourceLabel(s)}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setSources(
                                                    sources.filter(
                                                        (x) => x !== s,
                                                    ),
                                                )
                                            }
                                            aria-label={`Remove ${sourceLabel(s)}`}
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </li>
                                ))}
                        </ul>
                    )}

                    <div className="grid gap-2 sm:grid-cols-3">
                        <Select
                            value=""
                            onValueChange={(slug) => {
                                const set = options.sets.find(
                                    (s) => s.slug === slug,
                                );
                                setSources([
                                    ...sources,
                                    { type: 'set', slug, name: set?.name },
                                ]);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Add a set" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.sets.map((s) => (
                                    <SelectItem key={s.slug} value={s.slug}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value=""
                            onValueChange={(name) => {
                                const series = options.series.find(
                                    (s) => s.name === name,
                                );
                                setSources([
                                    ...sources,
                                    {
                                        type: 'series',
                                        name,
                                        line: series?.line ?? null,
                                    },
                                ]);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Add a series" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.series.map((s) => (
                                    <SelectItem
                                        key={`${s.line}-${s.name}`}
                                        value={s.name}
                                    >
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value=""
                            onValueChange={(slug) => {
                                const brand = options.brands.find(
                                    (b) => b.slug === slug,
                                );
                                setSources([
                                    ...sources,
                                    { type: 'brand', slug, name: brand?.name },
                                ]);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Add a brand" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.brands.map((b) => (
                                    <SelectItem key={b.slug} value={b.slug}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {pageErrors.sources && (
                        <p className="text-xs text-red-600">
                            {pageErrors.sources}
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label>Add individual cards</Label>
                    {picked.length > 0 && (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {picked.map((c) => (
                                <li
                                    key={c.id}
                                    className="flex items-center gap-2 p-2"
                                >
                                    {c.thumb ? (
                                        <img
                                            src={c.thumb}
                                            alt=""
                                            className="size-8 rounded object-cover"
                                        />
                                    ) : (
                                        <div className="size-8 rounded bg-muted" />
                                    )}
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {c.name}
                                        {c.number && (
                                            <span className="ml-1 text-muted-foreground">
                                                #{c.number}
                                            </span>
                                        )}
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            {c.set}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setPicked(
                                                picked.filter(
                                                    (p) => p.id !== c.id,
                                                ),
                                            )
                                        }
                                        aria-label={`Remove ${c.name}`}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <CardPicker seed="" selected={null} onSelect={addCard} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="top">Bars per frame</Label>
                        <Input
                            id="top"
                            type="number"
                            min={3}
                            max={50}
                            value={data.top}
                            onChange={(e) => setData('top', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="window">Median window (days)</Label>
                        <Input
                            id="window"
                            type="number"
                            min={1}
                            max={30}
                            value={data.window}
                            onChange={(e) => setData('window', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Fewer days reacts faster and jumps more. One sale is
                            not a price.
                        </p>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={data.is_public}
                        onCheckedChange={(v) =>
                            setData('is_public', v === true)
                        }
                    />
                    Anyone with the link can watch it
                </label>
                {sources.some(
                    (s) => s.type === 'collection' || s.type === 'wishlist',
                ) && (
                    <p className="-mt-3 text-xs text-muted-foreground">
                        This race follows a list of yours, so sharing it shows
                        what is on that list.
                    </p>
                )}

                <div className="flex items-center gap-2">
                    <Button onClick={submit} disabled={processing}>
                        {editing ? 'Save changes' : 'Build it'}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href="/races">Cancel</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
