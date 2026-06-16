import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Zap } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

export default function Billing({
    tier,
    isAdmin,
    renewsAt,
    cancelScheduled,
    plans,
    creditPacks,
    usage,
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
                <Card>
                    <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                Current plan
                            </p>
                            <p className="text-lg font-semibold capitalize">
                                {isAdmin ? 'Admin' : tier}
                            </p>
                            {!usage.unlimited && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {usage.used.toLocaleString()} /{' '}
                                    {usage.cap?.toLocaleString()} scans this
                                    month
                                    {(usage.credits ?? 0) > 0 &&
                                        ` · ${usage.credits} credits`}
                                </p>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant={isAdmin || isPaid ? 'default' : 'secondary'}>
                                {isAdmin
                                    ? 'Unlimited'
                                    : isPaid
                                      ? cancelScheduled
                                          ? `Cancels ${formatDate(renewsAt)}`
                                          : 'Active'
                                      : 'Free'}
                            </Badge>
                            {isPaid && !cancelScheduled && (
                                <Button variant="outline" size="sm" onClick={cancel}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {isAdmin ? (
                    <p className="text-sm text-muted-foreground">
                        Admin accounts have unlimited access to every feature.
                    </p>
                ) : (
                    <>
                        {/* Plans */}
                        <div className="grid gap-4 md:grid-cols-3">
                            {plans.map((plan) => {
                                const current = plan.key === tier;
                                const paidPlan =
                                    plan.key === 'collector' ||
                                    plan.key === 'guru';

                                return (
                                    <Card
                                        key={plan.key}
                                        className={cn(
                                            current && 'border-primary ring-1 ring-primary',
                                        )}
                                    >
                                        <CardContent className="space-y-4 pt-6">
                                            <div>
                                                <p className="font-semibold">
                                                    {plan.name}
                                                </p>
                                                <p className="text-2xl font-bold">
                                                    {plan.price_label}
                                                </p>
                                            </div>
                                            <ul className="space-y-1.5 text-sm">
                                                <Feature>
                                                    {plan.scans.toLocaleString()}{' '}
                                                    scans / month
                                                </Feature>
                                                <Feature>
                                                    {plan.collections}{' '}
                                                    collection
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
                                                    {plan.alerts === '1'
                                                        ? ''
                                                        : 's'}
                                                </Feature>
                                            </ul>

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
                                                    onClick={() =>
                                                        upgrade(plan.key)
                                                    }
                                                    disabled={
                                                        busy === `plan:${plan.key}`
                                                    }
                                                >
                                                    {busy ===
                                                    `plan:${plan.key}` ? (
                                                        <Loader2 className="size-4 animate-spin" />
                                                    ) : (
                                                        <Zap className="size-4" />
                                                    )}
                                                    Choose {plan.name}
                                                </Button>
                                            ) : (
                                                <div className="h-9" />
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* Credit packs */}
                        <div>
                            <Heading
                                variant="small"
                                title="Scan credits"
                                description="One-time top-ups. Credits never expire and are used after your monthly allowance. Cache-recognised scans are always free."
                            />
                            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                {creditPacks.map((pack) => (
                                    <Card key={pack.key}>
                                        <CardContent className="flex items-center justify-between gap-2 pt-6">
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
                                                onClick={() =>
                                                    buyCredits(pack.key)
                                                }
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
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function Feature({ children }: { children: React.ReactNode }) {
    return (
        <li className="flex items-center gap-2">
            <Check className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            {children}
        </li>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Billing', href: '/settings/billing' }],
};
