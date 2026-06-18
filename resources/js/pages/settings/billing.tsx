import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Zap } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Plan = {
    key: string;
    name: string;
    price_label: string;
    scans: number;
    collections: string;
    wishlists: string;
    alerts: string;
};

type CreditPack = { key: string; credits: number; price_label: string };

type Transaction = {
    id: number;
    type: string;
    status: string;
    description: string | null;
    amount: number | null;
    currency: string | null;
    created_at: string | null;
};

type Usage = {
    used: number;
    cap: number | null;
    credits?: number;
    unlimited: boolean;
};

type Props = {
    tier: string;
    isAdmin: boolean;
    renewsAt: string | null;
    cancelScheduled: boolean;
    plans: Plan[];
    creditPacks: CreditPack[];
    usage: Usage;
    transactions: Transaction[];
};

const POPULAR = 'collector';

const TAGLINE: Record<string, string> = {
    free: 'Get started',
    collector: 'For serious collectors',
    guru: 'For dealers & power users',
};

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          })
        : '';
}

/** "$4.99/mo" -> ["$4.99", "/mo"]; "Free" -> ["Free", ""]. */
function splitPrice(label: string): [string, string] {
    const i = label.indexOf('/');

    return i === -1 ? [label, ''] : [label.slice(0, i), label.slice(i)];
}

/** Cents -> "$4.99", honouring the transaction's currency. */
function formatMoney(amount: number | null, currency: string | null): string {
    if (amount === null) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
        }).format(amount / 100);
    } catch {
        return `$${(amount / 100).toFixed(2)}`;
    }
}

const STATUS_STYLE: Record<string, string> = {
    succeeded: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
    refunded: 'text-amber-600 dark:text-amber-400',
};

export default function Billing({
    tier,
    isAdmin,
    renewsAt,
    cancelScheduled,
    plans,
    creditPacks,
    usage,
    transactions,
}: Props) {
    const [busy, setBusy] = useState<string | null>(null);

    const upgrade = (planKey: string) => {
        setBusy(`plan:${planKey}`);
        router.post(
            '/settings/billing/checkout',
            { tier: planKey },
            { onFinish: () => setBusy(null) },
        );
    };

    const buyCredits = (pack: string) => {
        setBusy(`pack:${pack}`);
        router.post(
            '/settings/billing/credits',
            { pack },
            { onFinish: () => setBusy(null) },
        );
    };

    const cancel = () => {
        router.delete('/settings/billing', {
            preserveScroll: true,
            onSuccess: () =>
                toast.success('Subscription will cancel at period end.'),
        });
    };

    const isPaid = tier === 'collector' || tier === 'guru';

    return (
        <>
            <Head title="Billing" />
            <h1 className="sr-only">Billing</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Plans & billing"
                    description="Pick a plan, or top up scan credits any time."
                />

                {/* Current plan + usage */}
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-4">
                    <div className="flex items-center gap-3">
                        <span
                            className={cn(
                                'flex size-10 items-center justify-center rounded-lg',
                                isAdmin || isPaid
                                    ? 'bg-primary/15 text-primary'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            <Zap className="size-5" />
                        </span>
                        <div>
                            <p className="text-sm font-semibold capitalize">
                                {isAdmin ? 'Admin' : tier} plan
                            </p>
                            {isAdmin ? (
                                <p className="text-xs text-muted-foreground">
                                    Unlimited access to everything.
                                </p>
                            ) : cancelScheduled ? (
                                <p className="text-xs text-muted-foreground">
                                    Cancels {formatDate(renewsAt)}
                                </p>
                            ) : !usage.unlimited ? (
                                <p className="text-xs text-muted-foreground">
                                    {usage.used.toLocaleString()} /{' '}
                                    {usage.cap?.toLocaleString()} scans this month
                                    {(usage.credits ?? 0) > 0 &&
                                        ` · ${usage.credits?.toLocaleString()} credits`}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    {isPaid && !cancelScheduled && (
                        <Button variant="ghost" size="sm" onClick={cancel}>
                            Cancel plan
                        </Button>
                    )}
                </div>

                {!isAdmin && (
                    <>
                        {/* Plans */}
                        <div className="grid items-stretch gap-4 md:grid-cols-3">
                            {plans.map((plan) => {
                                const current = plan.key === tier;
                                const popular = plan.key === POPULAR;
                                const paidPlan =
                                    plan.key === 'collector' ||
                                    plan.key === 'guru';
                                const [amount, period] = splitPrice(
                                    plan.price_label,
                                );

                                return (
                                    <div
                                        key={plan.key}
                                        className={cn(
                                            'relative flex flex-col rounded-xl border bg-card p-5',
                                            current
                                                ? 'border-primary ring-1 ring-primary'
                                                : popular
                                                  ? 'border-primary/40'
                                                  : 'border-border',
                                        )}
                                    >
                                        {popular && !current && (
                                            <span className="absolute -top-2.5 left-5 rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary-foreground">
                                                Most popular
                                            </span>
                                        )}
                                        {current && (
                                            <span className="absolute -top-2.5 left-5 rounded-full bg-foreground px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-background">
                                                Current
                                            </span>
                                        )}

                                        <p className="text-sm font-semibold">
                                            {plan.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {TAGLINE[plan.key]}
                                        </p>

                                        <p className="mt-3 flex items-baseline gap-1">
                                            <span className="text-3xl font-bold tracking-tight">
                                                {amount}
                                            </span>
                                            {period && (
                                                <span className="text-sm text-muted-foreground">
                                                    {period}
                                                </span>
                                            )}
                                        </p>

                                        <ul className="mt-5 space-y-2.5 text-sm">
                                            <Feature>
                                                <strong className="font-semibold">
                                                    {plan.scans.toLocaleString()}
                                                </strong>{' '}
                                                AI scans / month
                                            </Feature>
                                            <Feature>
                                                {plan.collections} collection
                                                {plan.collections === '1'
                                                    ? ''
                                                    : 's'}
                                            </Feature>
                                            <Feature>
                                                {plan.wishlists} wishlist
                                                {plan.wishlists === '1'
                                                    ? ''
                                                    : 's'}
                                            </Feature>
                                            <Feature>
                                                {plan.alerts} price alert
                                                {plan.alerts === '1' ? '' : 's'}
                                            </Feature>
                                        </ul>

                                        <div className="mt-6 pt-2">
                                            {current ? (
                                                <Button
                                                    variant="outline"
                                                    className="w-full"
                                                    disabled
                                                >
                                                    Current plan
                                                </Button>
                                            ) : paidPlan ? (
                                                <Button
                                                    className="w-full"
                                                    variant={
                                                        popular
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        upgrade(plan.key)
                                                    }
                                                    disabled={
                                                        busy ===
                                                        `plan:${plan.key}`
                                                    }
                                                >
                                                    {busy ===
                                                        `plan:${plan.key}` && (
                                                        <Loader2 className="size-4 animate-spin" />
                                                    )}
                                                    Choose {plan.name}
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    className="w-full text-muted-foreground"
                                                    disabled
                                                >
                                                    Free forever
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Credit packs */}
                        <div>
                            <Heading
                                variant="small"
                                title="Scan credits"
                                description="One-time top-ups, used after your monthly allowance. Credits never expire — and cache-recognised scans are always free."
                            />
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                {creditPacks.map((pack) => (
                                    <div
                                        key={pack.key}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-4"
                                    >
                                        <div>
                                            <p className="font-semibold">
                                                {pack.credits.toLocaleString()}{' '}
                                                credits
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {pack.price_label}
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => buyCredits(pack.key)}
                                            disabled={
                                                busy === `pack:${pack.key}`
                                            }
                                        >
                                            {busy === `pack:${pack.key}` ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                'Buy'
                                            )}
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </>
                )}

                {/* Transaction history */}
                <div>
                    <Heading
                        variant="small"
                        title="Transaction history"
                        description="Your subscription charges, credit-pack purchases, and refunds."
                    />
                    {transactions.length === 0 ? (
                        <p className="mt-4 rounded-xl border border-border bg-card p-4 text-sm text-muted-foreground">
                            No transactions yet.
                        </p>
                    ) : (
                        <div className="mt-4 overflow-x-auto rounded-xl border border-border bg-card">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr className="border-b border-border">
                                        <th className="px-4 py-2.5 font-medium">
                                            Date
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Description
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Status
                                        </th>
                                        <th className="px-4 py-2.5 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.map((t) => (
                                        <tr
                                            key={t.id}
                                            className="border-b border-border/60 last:border-0"
                                        >
                                            <td className="px-4 py-2.5 whitespace-nowrap text-muted-foreground">
                                                {formatDate(t.created_at)}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                {t.description ?? t.type}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2.5 capitalize',
                                                    STATUS_STYLE[t.status] ??
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                {t.status}
                                            </td>
                                            <td className="px-4 py-2.5 text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    t.amount,
                                                    t.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

function Feature({ children }: { children: React.ReactNode }) {
    return (
        <li className="flex items-start gap-2">
            <Check className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <span>{children}</span>
        </li>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Billing', href: '/settings/billing' }],
};
