import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    LineChart,
    ScanLine,
    ShieldCheck,
    Store,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

/**
 * Marketing landing page. Chrome (header/footer/mobile tabs) is provided by the
 * AppShell layout — this page renders content only.
 */
const features: { title: string; description: string; icon: LucideIcon }[] = [
    {
        title: 'Distributions, not points',
        description:
            'Every value ships as median, range, sample size, and a confidence score — never a lone number.',
        icon: LineChart,
    },
    {
        title: 'Scan to add',
        description:
            'Identify cards by set, number, and language. You confirm the exact variant before anything is added.',
        icon: ScanLine,
    },
    {
        title: 'Graded & raw',
        description:
            'Price sealed product, raw singles by condition, and graded items by service and grade.',
        icon: BadgeCheck,
    },
    {
        title: 'First-party marketplace',
        description:
            'List from your collection and sell to other collectors, with cost-basis tracking built in.',
        icon: Store,
    },
];

const steps: { title: string; description: string }[] = [
    {
        title: 'Build your catalog',
        description:
            'Sealed product, singles, and graded items across every collectible vertical.',
    },
    {
        title: 'Value with confidence',
        description:
            'Robust stats reject outliers and weight recent sales by velocity.',
    },
    {
        title: 'Track and trade',
        description:
            'Portfolio analytics and a marketplace over one API — web today, native app next.',
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />

            {/* Hero */}
            <section className="mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
                <div className="mx-auto max-w-3xl text-center">
                    <span className="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground">
                        <ShieldCheck className="size-3.5" />
                        Wax on.
                    </span>
                    <h1 className="mt-6 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Know what your collection is really worth.
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                        Track, value, and sell TCG, sports cards, and other
                        collectibles — with market values that carry a range, a
                        sample size, and a confidence score.
                    </p>
                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        {auth.user ? (
                            <Button asChild size="lg">
                                <Link href={dashboard()}>Go to dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild size="lg">
                                    <Link href={register()}>
                                        Start your collection
                                    </Link>
                                </Button>
                                <Button asChild size="lg" variant="outline">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </section>

            {/* Features */}
            <section
                id="features"
                className="scroll-mt-16 border-t border-border bg-card/40"
            >
                <div className="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map((feature) => {
                            const Icon = feature.icon;

                            return (
                                <div
                                    key={feature.title}
                                    className="rounded-xl border border-border bg-card p-6"
                                >
                                    <span className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <Icon className="size-5" />
                                    </span>
                                    <h3 className="mt-4 font-semibold">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* How it works */}
            <section
                id="how-it-works"
                className="scroll-mt-16 border-t border-border"
            >
                <div className="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        How it works
                    </h2>
                    <ol className="mt-8 grid gap-6 sm:grid-cols-3">
                        {steps.map((step, index) => (
                            <li
                                key={step.title}
                                className="rounded-xl border border-border bg-card p-6"
                            >
                                <span className="flex size-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                    {index + 1}
                                </span>
                                <h3 className="mt-4 font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {step.description}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>
        </>
    );
}
