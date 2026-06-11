import { Head, Link, router, usePage } from '@inertiajs/react';
import { BadgeCheck, LineChart, ScanLine, Search, Store } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, register } from '@/routes';

/**
 * Marketing landing page. Chrome (header/footer/mobile tabs) is provided by the
 * AppShell layout — this page renders content only. The hero is a full-bleed
 * gold band with the "Wax on." slogan and a catalog search that submits to
 * /browse.
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

const popularSearches = ['Charizard', 'Pikachu', 'PSA 10', 'Booster box'];

export default function Welcome() {
    const { auth } = usePage().props;
    const [query, setQuery] = useState('');

    const onSearch = (event: React.FormEvent) => {
        event.preventDefault();
        const term = query.trim();
        router.get('/browse', term ? { q: term } : {});
    };

    return (
        <>
            <Head title="Wax on." />

            {/* Hero */}
            <section className="relative overflow-hidden bg-primary text-primary-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-y-0 left-0 hidden w-40 md:block lg:w-60"
                    style={{
                        backgroundImage:
                            'radial-gradient(rgba(17,19,23,0.16) 1.5px, transparent 1.6px)',
                        backgroundSize: '18px 18px',
                        maskImage:
                            'linear-gradient(to right, black, transparent)',
                        WebkitMaskImage:
                            'linear-gradient(to right, black, transparent)',
                    }}
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-y-0 right-0 hidden w-40 md:block lg:w-60"
                    style={{
                        backgroundImage:
                            'radial-gradient(rgba(17,19,23,0.16) 1.5px, transparent 1.6px)',
                        backgroundSize: '18px 18px',
                        maskImage:
                            'linear-gradient(to left, black, transparent)',
                        WebkitMaskImage:
                            'linear-gradient(to left, black, transparent)',
                    }}
                />
                <div className="relative mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
                    <div className="mx-auto max-w-2xl text-center">
                        <AppLogoIcon className="mx-auto block size-24 fill-current sm:size-28" />
                        <h1 className="mt-2 font-script text-7xl leading-tight sm:text-8xl">
                            Wax on.
                        </h1>
                        <p className="mx-auto mt-5 max-w-xl text-lg font-medium text-primary-foreground/80">
                            Search any card and see what it&rsquo;s really worth
                            — sealed, raw, or graded, with a confidence score.
                        </p>

                        <form
                            onSubmit={onSearch}
                            className="mx-auto mt-8 flex w-full max-w-xl items-center gap-2 rounded-full bg-white p-1.5 shadow-xl ring-1 ring-black/5"
                        >
                            <Search className="ml-3 size-5 shrink-0 text-neutral-400" />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search cards, sets, or players…"
                                aria-label="Search the catalog"
                                className="min-w-0 flex-1 bg-transparent py-2 text-base text-neutral-900 outline-none placeholder:text-neutral-500"
                            />
                            <button
                                type="submit"
                                className="shrink-0 rounded-full bg-[#111317] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#111317]/90"
                            >
                                Search
                            </button>
                        </form>

                        <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                            <span className="text-primary-foreground/70">
                                Popular:
                            </span>
                            {popularSearches.map((term) => (
                                <Link
                                    key={term}
                                    href={`/browse?q=${encodeURIComponent(term)}`}
                                    className="rounded-full bg-black/10 px-3 py-1 font-medium transition-colors hover:bg-black/20"
                                >
                                    {term}
                                </Link>
                            ))}
                        </div>

                        <div className="mt-8">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="text-sm font-semibold underline-offset-4 hover:underline"
                                >
                                    Go to your dashboard &rarr;
                                </Link>
                            ) : (
                                <Link
                                    href={register()}
                                    className="text-sm font-semibold underline-offset-4 hover:underline"
                                >
                                    or start your collection &rarr;
                                </Link>
                            )}
                        </div>
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
