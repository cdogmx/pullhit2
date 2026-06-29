import { Head, router, useForm } from '@inertiajs/react';
import {
    Bell,
    ExternalLink,
    Pause,
    Play,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

type Alert = {
    id: number;
    label: string | null;
    asin: string;
    domain: string;
    geo_location: string | null;
    target_price: number;
    currency: string;
    check_interval_minutes: number;
    is_active: boolean;
    url: string;
    last_checked_at: string | null;
    last_price: number | null;
    last_in_stock: boolean;
    last_status: string | null;
    last_title: string | null;
    last_qualified: boolean;
    last_error: string | null;
    last_tweeted_at: string | null;
};

type Props = { alerts: Alert[]; xConfigured: boolean };

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

export default function AdminStockAlerts({ alerts, xConfigured }: Props) {
    const form = useForm({
        label: '',
        asin: '',
        target_price: '',
        currency: 'USD',
        domain: 'com',
        geo_location: '',
        check_interval_minutes: 15,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/stock-alerts', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Alert added.');
                form.reset('label', 'asin', 'target_price');
            },
        });
    };

    const checkNow = (a: Alert) => {
        router.post(
            `/admin/stock-alerts/${a.id}/check`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Checked.'),
                onError: () => toast.error('Check failed.'),
            },
        );
    };

    const toggle = (a: Alert) => {
        router.post(
            `/admin/stock-alerts/${a.id}/toggle`,
            {},
            { preserveScroll: true },
        );
    };

    const remove = (a: Alert) => {
        if (!confirm(`Delete alert for ${a.asin}?`)) {
            return;
        }

        router.delete(`/admin/stock-alerts/${a.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Deleted.'),
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
                                Set <code>X_ACCESS_TOKEN</code> and{' '}
                                <code>X_ACCESS_TOKEN_SECRET</code> (OAuth 1.0a,
                                Read+Write) to post. Until then, alerts still
                                track stock but won’t tweet.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Create */}
                <Card>
                    <CardContent className="pt-6">
                        <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                            <Bell className="size-4 text-primary" />
                            Watch an Amazon product
                        </h2>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Label (optional)
                                </Label>
                                <Input
                                    value={form.data.label}
                                    onChange={(e) =>
                                        form.setData('label', e.target.value)
                                    }
                                    placeholder="Surging Sparks ETB"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">ASIN</Label>
                                    <Input
                                        value={form.data.asin}
                                        onChange={(e) =>
                                            form.setData(
                                                'asin',
                                                e.target.value
                                                    .toUpperCase()
                                                    .trim(),
                                            )
                                        }
                                        placeholder="B0GWKHNR4K"
                                        maxLength={10}
                                    />
                                    {form.errors.asin && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.asin}
                                        </p>
                                    )}
                                </div>
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
                                        placeholder="5.99"
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
                                    {form.errors.check_interval_minutes && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.check_interval_minutes}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">
                                        Amazon domain
                                    </Label>
                                    <Input
                                        value={form.data.domain}
                                        onChange={(e) =>
                                            form.setData(
                                                'domain',
                                                e.target.value.trim(),
                                            )
                                        }
                                        placeholder="com"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label className="text-xs">
                                        Delivery ZIP (optional)
                                    </Label>
                                    <Input
                                        value={form.data.geo_location}
                                        onChange={(e) =>
                                            form.setData(
                                                'geo_location',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. 90210"
                                    />
                                </div>
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Add alert
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* List */}
                <div className="space-y-3">
                    {alerts.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No alerts yet.
                            </CardContent>
                        </Card>
                    )}
                    {alerts.map((a) => (
                        <Card
                            key={a.id}
                            className={cn(!a.is_active && 'opacity-60')}
                        >
                            <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0 space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {a.label || a.last_title || a.asin}
                                        </span>
                                        {a.last_qualified ? (
                                            <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">
                                                In stock ≤ target
                                            </Badge>
                                        ) : a.last_in_stock ? (
                                            <Badge
                                                variant="secondary"
                                                className="text-xs"
                                            >
                                                In stock (above target)
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                Out of stock
                                            </Badge>
                                        )}
                                        {!a.is_active && (
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                Paused
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        <a
                                            href={a.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 hover:text-foreground"
                                        >
                                            {a.asin}
                                            <ExternalLink className="size-3" />
                                        </a>{' '}
                                        · target{' '}
                                        {money(a.target_price, a.currency)} ·
                                        last {money(a.last_price, a.currency)} ·
                                        every {a.check_interval_minutes}m
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Checked {ago(a.last_checked_at)}
                                        {a.last_status
                                            ? ` · “${a.last_status}”`
                                            : ''}
                                        {a.last_tweeted_at
                                            ? ` · tweeted ${ago(a.last_tweeted_at)}`
                                            : ''}
                                    </p>
                                    {a.last_error && (
                                        <p className="text-xs text-red-600">
                                            {a.last_error}
                                        </p>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => checkNow(a)}
                                        title="Check now (dry — won’t tweet)"
                                    >
                                        <RefreshCw className="size-4" />
                                        Check
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => toggle(a)}
                                    >
                                        {a.is_active ? (
                                            <Pause className="size-4" />
                                        ) : (
                                            <Play className="size-4" />
                                        )}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="text-red-600 hover:text-red-600"
                                        onClick={() => remove(a)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
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
