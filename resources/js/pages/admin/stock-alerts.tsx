import { Head, router, useForm } from '@inertiajs/react';
import {
    Bell,
    Check,
    ExternalLink,
    Pause,
    Pencil,
    Play,
    Plus,
    RefreshCw,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type RetailerOption = { value: string; label: string };

type Link = {
    id: number;
    retailer: string;
    retailer_label: string;
    url: string;
    external_id: string | null;
    is_active: boolean;
    last_checked_at: string | null;
    last_price: number | null;
    last_in_stock: boolean;
    last_status: string | null;
    last_title: string | null;
    last_qualified: boolean;
    last_error: string | null;
    last_tweeted_at: string | null;
};

type Product = {
    id: number;
    name: string | null;
    catalog_item_id: number | null;
    catalog_name: string | null;
    image_url: string | null;
    own_image_url: string | null;
    target_price: number;
    currency: string;
    check_interval_minutes: number;
    is_active: boolean;
    links: Link[];
};

type Props = {
    products: Product[];
    retailers: RetailerOption[];
    xConfigured: boolean;
};

type CatalogHit = {
    id: number;
    name: string;
    set: string | null;
    image_url: string | null;
};

const CURRENCIES = ['USD', 'GBP', 'EUR', 'CAD', 'JPY'];

const money = (value: number | null, currency: string): string => {
    if (value === null) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        }).format(value);
    } catch {
        return `${value} ${currency}`;
    }
};

const ago = (iso: string | null): string => {
    if (!iso) {
        return 'never';
    }

    const secs = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (secs < 60) {
        return `${secs}s ago`;
    }

    if (secs < 3600) {
        return `${Math.round(secs / 60)}m ago`;
    }

    if (secs < 86400) {
        return `${Math.round(secs / 3600)}h ago`;
    }

    return `${Math.round(secs / 86400)}d ago`;
};

/** Typeahead that searches the catalog and reports the chosen item up. */
function CatalogPicker({
    value,
    onSelect,
    onClear,
}: {
    value: string | null;
    onSelect: (item: CatalogHit) => void;
    onClear: () => void;
}) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<CatalogHit[]>([]);
    const [open, setOpen] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        const query = q.trim();

        timer.current = setTimeout(async () => {
            if (value || query.length < 2) {
                setHits([]);

                return;
            }

            const res = await fetch(
                `/admin/stock-alerts/catalog-search?q=${encodeURIComponent(query)}`,
                { headers: { Accept: 'application/json' } },
            );
            setHits(res.ok ? await res.json() : []);
            setOpen(true);
        }, 250);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [q, value]);

    if (value) {
        return (
            <div className="flex items-center justify-between rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                <span className="truncate">Attached: {value}</span>
                <button
                    type="button"
                    onClick={onClear}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Detach catalog item"
                >
                    <X className="size-4" />
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <Input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Search the catalog to attach…"
                onFocus={() => hits.length && setOpen(true)}
            />
            {open && hits.length > 0 && (
                <div className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-popover shadow-md">
                    {hits.map((h) => (
                        <button
                            key={h.id}
                            type="button"
                            onClick={() => {
                                onSelect(h);
                                setQ('');
                                setOpen(false);
                            }}
                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                        >
                            {h.image_url && (
                                <img
                                    src={h.image_url}
                                    alt=""
                                    className="size-8 shrink-0 rounded object-contain"
                                />
                            )}
                            <span className="min-w-0">
                                <span className="block truncate">{h.name}</span>
                                {h.set && (
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {h.set}
                                    </span>
                                )}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** The "add a retailer link" mini-form on each product card. */
function AddLink({
    productId,
    retailers,
}: {
    productId: number;
    retailers: RetailerOption[];
}) {
    const form = useForm({
        retailer: retailers[0]?.value ?? 'amazon',
        url: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/admin/stock-alerts/${productId}/links`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Link added.');
                form.reset('url');
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
            <Select
                value={form.data.retailer}
                onValueChange={(v) => form.setData('retailer', v)}
            >
                <SelectTrigger size="sm" className="w-36">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {retailers.map((r) => (
                        <SelectItem key={r.value} value={r.value}>
                            {r.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Input
                value={form.data.url}
                onChange={(e) => form.setData('url', e.target.value)}
                placeholder="Paste the product URL"
                className="h-8 min-w-[14rem] flex-1"
            />
            <Button
                type="submit"
                size="sm"
                variant="outline"
                disabled={form.processing}
            >
                <Plus className="size-4" />
                Add link
            </Button>
        </form>
    );
}

function LinkRow({ link }: { link: Link }) {
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [busy, setBusy] = useState(false);

    const check = () =>
        router.post(
            `/admin/stock-alerts/links/${link.id}/check`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Checked.'),
                onError: () => toast.error('Check failed.'),
            },
        );

    const toggle = () =>
        router.post(
            `/admin/stock-alerts/links/${link.id}/toggle`,
            {},
            { preserveScroll: true },
        );

    const remove = () => {
        setBusy(true);
        router.delete(`/admin/stock-alerts/links/${link.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Removed.');
                setConfirmRemove(false);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div
            className={cn(
                'flex flex-col gap-1 rounded-md border border-border p-2 sm:flex-row sm:items-center sm:justify-between',
                !link.is_active && 'opacity-60',
            )}
        >
            <div className="min-w-0 space-y-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">
                        {link.retailer_label}
                    </span>
                    {link.last_qualified ? (
                        <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">
                            ≤ target
                        </Badge>
                    ) : link.last_in_stock ? (
                        <Badge variant="secondary" className="text-xs">
                            in stock
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="text-xs">
                            out
                        </Badge>
                    )}
                    {link.last_price !== null && (
                        <span className="text-xs text-muted-foreground">
                            {money(link.last_price, 'USD')}
                        </span>
                    )}
                    <a
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        link
                        <ExternalLink className="size-3" />
                    </a>
                </div>
                <p className="text-xs text-muted-foreground">
                    checked {ago(link.last_checked_at)}
                    {link.last_tweeted_at
                        ? ` · tweeted ${ago(link.last_tweeted_at)}`
                        : ''}
                    {link.last_status ? ` · ${link.last_status}` : ''}
                </p>
                {link.last_error && (
                    <p className="text-xs text-red-600">{link.last_error}</p>
                )}
            </div>
            <div className="flex shrink-0 gap-1">
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={check}
                    title="Check now (dry)"
                >
                    <RefreshCw className="size-4" />
                </Button>
                <Button size="sm" variant="ghost" onClick={toggle}>
                    {link.is_active ? (
                        <Pause className="size-4" />
                    ) : (
                        <Play className="size-4" />
                    )}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="text-red-600 hover:text-red-600"
                    onClick={() => setConfirmRemove(true)}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>

            <ConfirmDialog
                open={confirmRemove}
                onOpenChange={setConfirmRemove}
                title={`Remove the ${link.retailer_label} link?`}
                description="This stops checking that retailer URL for this product."
                confirmLabel="Remove link"
                destructive
                busy={busy}
                onConfirm={remove}
            />
        </div>
    );
}

/** Edit a product's details + image in a dialog. */
function EditProduct({ product }: { product: Product }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: product.name ?? '',
        catalog_item_id: product.catalog_item_id,
        image_url: product.own_image_url ?? '',
        target_price: String(product.target_price),
        currency: product.currency,
        check_interval_minutes: product.check_interval_minutes,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/stock-alerts/${product.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Saved.');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost" aria-label="Edit product">
                    <Pencil className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit product</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Name</Label>
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Surging Sparks Elite Trainer Box"
                        />
                        {form.errors.name && (
                            <p className="text-xs text-red-600">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="text-xs">Image</Label>
                        <ImageUploadField
                            value={form.data.image_url}
                            onChange={(url) => form.setData('image_url', url)}
                        />
                    </div>

                    <div className="grid grid-cols-3 gap-3">
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Target price</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={form.data.target_price}
                                onChange={(e) =>
                                    form.setData('target_price', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Currency</Label>
                            <Select
                                value={form.data.currency}
                                onValueChange={(v) =>
                                    form.setData('currency', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CURRENCIES.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Every (min)</Label>
                            <Input
                                type="number"
                                min="5"
                                max="1440"
                                value={form.data.check_interval_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'check_interval_minutes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ProductCard({
    product,
    retailers,
}: {
    product: Product;
    retailers: RetailerOption[];
}) {
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [busy, setBusy] = useState(false);

    const toggle = () =>
        router.post(
            `/admin/stock-alerts/${product.id}/toggle`,
            {},
            { preserveScroll: true },
        );

    const remove = () => {
        setBusy(true);
        router.delete(`/admin/stock-alerts/${product.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Deleted.');
                setConfirmRemove(false);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card className={cn(!product.is_active && 'opacity-60')}>
            <CardContent className="space-y-3 pt-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 gap-3">
                        {product.image_url && (
                            <img
                                src={product.image_url}
                                alt=""
                                className="size-14 shrink-0 rounded-md border border-border bg-muted object-contain"
                            />
                        )}
                        <div className="min-w-0">
                            <p className="font-medium">
                                {product.name ||
                                    product.catalog_name ||
                                    `Product #${product.id}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                target{' '}
                                {money(product.target_price, product.currency)}{' '}
                                · every {product.check_interval_minutes}m
                                {product.catalog_item_id ? (
                                    <>
                                        {' · '}
                                        <a
                                            href={`/catalog/${product.catalog_item_id}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-0.5 text-primary hover:underline"
                                        >
                                            📇 {product.catalog_name ?? 'catalog item'}
                                            <ExternalLink className="size-3" />
                                        </a>
                                    </>
                                ) : (
                                    ''
                                )}
                                {!product.is_active ? ' · paused' : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-1">
                        <EditProduct product={product} />
                        <Button size="sm" variant="ghost" onClick={toggle}>
                            {product.is_active ? (
                                <Pause className="size-4" />
                            ) : (
                                <Play className="size-4" />
                            )}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="text-red-600 hover:text-red-600"
                            onClick={() => setConfirmRemove(true)}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>

                <div className="space-y-2">
                    {product.links.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            No retailer links yet — add one below.
                        </p>
                    )}
                    {product.links.map((l) => (
                        <LinkRow key={l.id} link={l} />
                    ))}
                </div>

                <AddLink productId={product.id} retailers={retailers} />

                <ConfirmDialog
                    open={confirmRemove}
                    onOpenChange={setConfirmRemove}
                    title="Delete this product?"
                    description="Removes the product and all of its retailer links. This cannot be undone."
                    confirmLabel="Delete product"
                    destructive
                    busy={busy}
                    onConfirm={remove}
                />
            </CardContent>
        </Card>
    );
}

export default function AdminStockAlerts({
    products,
    retailers,
    xConfigured,
}: Props) {
    const form = useForm<{
        name: string;
        catalog_item_id: number | null;
        image_url: string;
        target_price: string;
        currency: string;
        check_interval_minutes: number;
        retailer: string;
        url: string;
    }>({
        name: '',
        catalog_item_id: null,
        image_url: '',
        target_price: '',
        currency: 'USD',
        check_interval_minutes: 15,
        retailer: retailers[0]?.value ?? 'amazon',
        url: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/stock-alerts', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Product added.');
                form.reset(
                    'name',
                    'catalog_item_id',
                    'image_url',
                    'target_price',
                    'url',
                );
            },
        });
    };

    return (
        <>
            <Head title="Admin · Stock alerts" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                {!xConfigured && (
                    <Card className="border-amber-500/40 bg-amber-500/5">
                        <CardContent className="pt-6 text-sm">
                            <p className="font-medium text-amber-700 dark:text-amber-400">
                                X posting isn’t fully configured.
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Set the OAuth 1.0a access token + secret to
                                post. Until then, alerts track stock but won’t
                                tweet.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Create product */}
                <Card>
                    <CardContent className="pt-6">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                            <Bell className="size-4 text-primary" />
                            Track a product
                        </h2>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Name (or attach a catalog item below)
                                </Label>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Surging Sparks Elite Trainer Box"
                                />
                                {form.errors.name && (
                                    <p className="text-xs text-red-600">
                                        {form.errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Attach catalog item (optional)
                                </Label>
                                <CatalogPicker
                                    value={
                                        form.data.catalog_item_id
                                            ? form.data.name || 'catalog item'
                                            : null
                                    }
                                    onSelect={(item) => {
                                        form.setData((d) => ({
                                            ...d,
                                            catalog_item_id: item.id,
                                            name: d.name || item.name,
                                            image_url:
                                                d.image_url ||
                                                item.image_url ||
                                                '',
                                        }));
                                    }}
                                    onClear={() =>
                                        form.setData('catalog_item_id', null)
                                    }
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Image (optional)
                                </Label>
                                <ImageUploadField
                                    value={form.data.image_url}
                                    onChange={(url) =>
                                        form.setData('image_url', url)
                                    }
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">
                                        Target price
                                    </Label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        value={form.data.target_price}
                                        onChange={(e) =>
                                            form.setData(
                                                'target_price',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="49.99"
                                    />
                                    {form.errors.target_price && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.target_price}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">Currency</Label>
                                    <Select
                                        value={form.data.currency}
                                        onValueChange={(v) =>
                                            form.setData('currency', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CURRENCIES.map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {c}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">
                                        Check every (min)
                                    </Label>
                                    <Input
                                        type="number"
                                        min="5"
                                        max="1440"
                                        value={form.data.check_interval_minutes}
                                        onChange={(e) =>
                                            form.setData(
                                                'check_interval_minutes',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    First retailer link (optional)
                                </Label>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select
                                        value={form.data.retailer}
                                        onValueChange={(v) =>
                                            form.setData('retailer', v)
                                        }
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {retailers.map((r) => (
                                                <SelectItem
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Input
                                        value={form.data.url}
                                        onChange={(e) =>
                                            form.setData('url', e.target.value)
                                        }
                                        placeholder="Paste the product URL"
                                        className="min-w-[14rem] flex-1"
                                    />
                                </div>
                                {form.errors.url && (
                                    <p className="text-xs text-red-600">
                                        {form.errors.url}
                                    </p>
                                )}
                            </div>

                            <Button type="submit" disabled={form.processing}>
                                <Check className="size-4" />
                                Add product
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Products */}
                <div className="space-y-3">
                    {products.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No tracked products yet.
                            </CardContent>
                        </Card>
                    )}
                    {products.map((p) => (
                        <ProductCard
                            key={p.id}
                            product={p}
                            retailers={retailers}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

AdminStockAlerts.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Stock alerts', href: '/admin/stock-alerts' },
    ],
};
