import { Head, router } from '@inertiajs/react';
import { Check, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

type Props = {
    tier: string;
    isAdmin: boolean;
    renewsAt: string | null;
    cancelScheduled: boolean;
    priceLabel: string;
    premiumScanCap: number;
};

const PERKS = (cap: number) => [
    `${cap.toLocaleString()} scans per month`,
    'Full portfolio analytics',
    'Priority access to new features',
];

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
    priceLabel,
    premiumScanCap,
}: Props) {
    const [busy, setBusy] = useState(false);

    const upgrade = () => {
        setBusy(true);
        router.post(
            '/settings/billing/checkout',
            {},
            { onFinish: () => setBusy(false) },
        );
    };

    const cancel = () => {
        router.delete('/settings/billing', {
            preserveScroll: true,
            onSuccess: () =>
                toast.success('Subscription will cancel at period end.'),
        });
    };

    const premium = isAdmin || tier === 'premium';

    return (
        <>
            <Head title="Billing" />
            <h1 className="sr-only">Billing</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Billing"
                    description="Manage your membership and premium features"
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Current plan
                                </p>
                                <p className="text-lg font-semibold">
                                    {isAdmin
                                        ? 'Admin'
                                        : premium
                                          ? 'Premium'
                                          : 'Free'}
                                </p>
                            </div>
                            <Badge variant={premium ? 'default' : 'secondary'}>
                                {isAdmin
                                    ? 'Unlimited'
                                    : premium
                                      ? 'Active'
                                      : 'Free'}
                            </Badge>
                        </div>

                        {isAdmin ? (
                            <p className="text-sm text-muted-foreground">
                                Admin accounts have unlimited access to every feature.
                            </p>
                        ) : premium ? (
                            <div className="space-y-3">
                                {cancelScheduled ? (
                                    <p className="text-sm text-muted-foreground">
                                        Your premium access cancels on{' '}
                                        <span className="font-medium">
                                            {formatDate(renewsAt)}
                                        </span>
                                        . You'll keep premium until then.
                                    </p>
                                ) : (
                                    <>
                                        {renewsAt && (
                                            <p className="text-sm text-muted-foreground">
                                                Renews on{' '}
                                                <span className="font-medium">
                                                    {formatDate(renewsAt)}
                                                </span>
                                                .
                                            </p>
                                        )}
                                        <Button variant="outline" onClick={cancel}>
                                            Cancel subscription
                                        </Button>
                                    </>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <ul className="space-y-1.5 text-sm">
                                    {PERKS(premiumScanCap).map((perk) => (
                                        <li
                                            key={perk}
                                            className="flex items-center gap-2"
                                        >
                                            <Check className="size-4 text-emerald-600 dark:text-emerald-400" />
                                            {perk}
                                        </li>
                                    ))}
                                </ul>
                                <Button onClick={upgrade} disabled={busy}>
                                    {busy && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}
                                    Upgrade to Premium — {priceLabel}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Billing', href: '/settings/billing' }],
};
