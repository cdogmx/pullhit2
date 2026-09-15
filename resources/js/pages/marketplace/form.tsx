import { Head, router, useForm } from '@inertiajs/react';
import { ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

type CategoryOption = {
    value: string;
    label: string;
    graded: boolean;
    has_condition: boolean;
};

type Existing = {
    id: number;
    title: string;
    description: string | null;
    category: string;
    price_cents: number;
    condition: string | null;
    grading_company_id: number | null;
    grade: string | null;
    cert_number: string | null;
    catalog_item_id: number | null;
    accepts_offers: boolean;
    accepts_direct: boolean;
    accepts_escrow: boolean;
    status: string;
    photos: { id: number; path: string }[];
};

type Props = {
    listing: Existing | null;
    options: {
        categories: CategoryOption[];
        conditions: { value: string; label: string }[];
        grading_companies: { id: number; slug: string; name: string }[];
    };
};

export default function MarketplaceForm({ listing, options }: Props) {
    const editing = listing !== null;

    // Prices are entered as dollars and stored as cents. The form is the only
    // place that conversion happens, so a mistyped decimal cannot reach the db.
    const [dollars, setDollars] = useState(
        listing ? (listing.price_cents / 100).toFixed(2) : '',
    );
    const [keep, setKeep] = useState<number[]>(
        listing?.photos.map((p) => p.id) ?? [],
    );
    const [files, setFiles] = useState<File[]>([]);

    const { data, setData, processing, errors } = useForm({
        category: listing?.category ?? 'raw_single',
        title: listing?.title ?? '',
        description: listing?.description ?? '',
        condition: listing?.condition ?? 'NM',
        grading_company_id: listing?.grading_company_id
            ? String(listing.grading_company_id)
            : '',
        grade: listing?.grade ?? '',
        cert_number: listing?.cert_number ?? '',
        catalog_item_id: listing?.catalog_item_id ?? null,
        accepts_offers: listing?.accepts_offers ?? true,
        accepts_direct: listing?.accepts_direct ?? true,
        accepts_escrow: listing?.accepts_escrow ?? true,
    });

    const category =
        options.categories.find((c) => c.value === data.category) ??
        options.categories[0];

    const submit = (publish: boolean) => {
        // Multipart, because photos are files — so every field goes as a string
        // and the server casts. useForm's transform would not survive the
        // FormData round trip.
        const body = new FormData();

        body.append('category', data.category);
        body.append('title', data.title);
        body.append('description', data.description ?? '');
        body.append('price_cents', String(Math.round(Number(dollars) * 100)));
        body.append('accepts_offers', data.accepts_offers ? '1' : '0');
        body.append('accepts_direct', data.accepts_direct ? '1' : '0');
        body.append('accepts_escrow', data.accepts_escrow ? '1' : '0');
        body.append('publish', publish ? '1' : '0');

        if (category?.has_condition && data.condition) {
            body.append('condition', data.condition);
        }

        if (category?.graded) {
            if (data.grading_company_id) {
                body.append('grading_company_id', data.grading_company_id);
            }

            body.append('grade', data.grade ?? '');
            body.append('cert_number', data.cert_number ?? '');
        }

        if (data.catalog_item_id) {
            body.append('catalog_item_id', String(data.catalog_item_id));
        }

        if (editing) {
            keep.forEach((id) => body.append('keep_photo_ids[]', String(id)));
        }

        files.forEach((f) => body.append('photos[]', f));

        router.post(
            editing ? `/marketplace/${listing.id}` : '/marketplace',
            body,
            { forceFormData: true },
        );
    };

    const photoCount = keep.length + files.length;

    return (
        <>
            <Head title={editing ? 'Edit listing' : 'List a card'} />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 p-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        {editing ? 'Edit listing' : 'List a card'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        You arrange payment and shipping with the buyer. CardFoo
                        is a venue and is not party to the sale.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label>What is it?</Label>
                    <Select
                        value={data.category}
                        onValueChange={(v) => setData('category', v)}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {options.categories.map((c) => (
                                <SelectItem key={c.value} value={c.value}>
                                    {c.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="title">Title</Label>
                    <Input
                        id="title"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="Charizard VMAX Alt Art PSA 10"
                    />
                    {errors.title && (
                        <p className="text-sm text-destructive">
                            {errors.title}
                        </p>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="price">Price (USD)</Label>
                    <Input
                        id="price"
                        inputMode="decimal"
                        value={dollars}
                        onChange={(e) => setDollars(e.target.value)}
                        placeholder="125.00"
                        className="tabular-nums"
                    />
                </div>

                {category?.graded && (
                    <div className="grid gap-3 rounded-lg border border-border p-3">
                        <p className="text-sm font-medium">Slab details</p>
                        <div className="grid gap-2 sm:grid-cols-3">
                            <Select
                                value={data.grading_company_id}
                                onValueChange={(v) =>
                                    setData('grading_company_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Grader" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.grading_companies.map((g) => (
                                        <SelectItem
                                            key={g.id}
                                            value={String(g.id)}
                                        >
                                            {g.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                value={data.grade}
                                onChange={(e) =>
                                    setData('grade', e.target.value)
                                }
                                placeholder="Grade (10)"
                            />
                            <Input
                                value={data.cert_number}
                                onChange={(e) =>
                                    setData('cert_number', e.target.value)
                                }
                                placeholder="Cert number"
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            The cert number lets buyers verify the slab with the
                            grader, and lets us spot the same cert listed twice.
                        </p>
                    </div>
                )}

                {category?.has_condition && (
                    <div className="grid gap-2">
                        <Label>Condition</Label>
                        <Select
                            value={data.condition ?? 'NM'}
                            onValueChange={(v) => setData('condition', v)}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.conditions.map((c) => (
                                    <SelectItem key={c.value} value={c.value}>
                                        {c.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="description">Description</Label>
                    <Textarea
                        id="description"
                        rows={4}
                        value={data.description ?? ''}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="Centering, edges, how it ships…"
                    />
                </div>

                {/* Photos */}
                <div className="grid gap-2">
                    <Label>Photos</Label>
                    <div className="flex flex-wrap gap-2">
                        {listing?.photos
                            .filter((p) => keep.includes(p.id))
                            .map((p) => (
                                <div key={p.id} className="relative">
                                    <img
                                        src={p.path}
                                        alt=""
                                        className="size-20 rounded-md border border-border object-cover"
                                    />
                                    <button
                                        type="button"
                                        aria-label="Remove photo"
                                        onClick={() =>
                                            setKeep(
                                                keep.filter(
                                                    (id) => id !== p.id,
                                                ),
                                            )
                                        }
                                        className="absolute -top-1.5 -right-1.5 rounded-full bg-background p-1 shadow"
                                    >
                                        <Trash2 className="size-3" />
                                    </button>
                                </div>
                            ))}

                        {files.map((f, i) => (
                            <div key={i} className="relative">
                                <img
                                    src={URL.createObjectURL(f)}
                                    alt=""
                                    className="size-20 rounded-md border border-border object-cover"
                                />
                                <button
                                    type="button"
                                    aria-label="Remove photo"
                                    onClick={() =>
                                        setFiles(
                                            files.filter((_, j) => j !== i),
                                        )
                                    }
                                    className="absolute -top-1.5 -right-1.5 rounded-full bg-background p-1 shadow"
                                >
                                    <Trash2 className="size-3" />
                                </button>
                            </div>
                        ))}

                        <label className="flex size-20 cursor-pointer items-center justify-center rounded-md border border-dashed border-border text-muted-foreground hover:text-foreground">
                            <ImagePlus className="size-5" />
                            <input
                                type="file"
                                accept="image/*"
                                multiple
                                hidden
                                onChange={(e) =>
                                    setFiles([
                                        ...files,
                                        ...Array.from(e.target.files ?? []),
                                    ])
                                }
                            />
                        </label>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        At least one photo is required to publish. For a slab,
                        make the first one the front with the cert readable.
                        Location data is stripped from every upload.
                    </p>
                </div>

                <div className="grid gap-3 rounded-lg border border-border p-3">
                    <p className="text-sm font-medium">How buyers can pay</p>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.accepts_escrow}
                            onCheckedChange={(v) =>
                                setData('accepts_escrow', Boolean(v))
                            }
                        />
                        Buy Protected — payment is held until the card arrives
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.accepts_direct}
                            onCheckedChange={(v) =>
                                setData('accepts_direct', Boolean(v))
                            }
                        />
                        Direct — you arrange it with the buyer yourselves
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.accepts_offers}
                            onCheckedChange={(v) =>
                                setData('accepts_offers', Boolean(v))
                            }
                        />
                        Accept offers
                    </label>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        onClick={() => submit(true)}
                        disabled={processing || photoCount === 0}
                    >
                        {processing && (
                            <Loader2 className="mr-2 size-4 animate-spin" />
                        )}
                        {editing ? 'Save and publish' : 'Publish listing'}
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => submit(false)}
                        disabled={processing}
                    >
                        Save draft
                    </Button>
                    {photoCount === 0 && (
                        <span className="text-xs text-muted-foreground">
                            Add a photo to publish.
                        </span>
                    )}
                </div>
            </div>
        </>
    );
}
