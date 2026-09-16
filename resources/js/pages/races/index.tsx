import { Head, Link } from '@inertiajs/react';
import { Eye, Flag, Lock, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';

type RaceCard = {
    slug: string;
    name: string;
    description: string | null;
    owner: string | null;
    is_public: boolean;
    views: number;
    sources: number;
    updated_at: string | null;
};

type Props = { races: RaceCard[]; mine: RaceCard[] };

function RaceRow({ race }: { race: RaceCard }) {
    return (
        <li className="flex items-center gap-3 p-3">
            <Flag className="size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <Link
                    href={`/races/${race.slug}`}
                    className="font-medium hover:underline"
                >
                    {race.name}
                </Link>
                <p className="truncate text-xs text-muted-foreground">
                    {race.description ??
                        `${race.sources} source${race.sources === 1 ? '' : 's'}`}
                    {race.owner ? ` · by ${race.owner}` : ''}
                </p>
            </div>
            {!race.is_public && (
                <Lock
                    className="size-3.5 shrink-0 text-muted-foreground"
                    aria-label="Private"
                />
            )}
            <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground tabular-nums">
                <Eye className="size-3" />
                {race.views}
            </span>
        </li>
    );
}

export default function RacesIndex({ races, mine }: Props) {
    return (
        <>
            <Head title="Price races" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-10 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Price races
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Watch a set, a series, or any cards you pick move
                            against each other, from real sold prices.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/races/new">
                            <Plus className="mr-1 size-4" />
                            Build a race
                        </Link>
                    </Button>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Start here</h2>
                    <ul className="divide-y divide-border rounded-xl border border-border text-sm">
                        <li className="p-3">
                            <Link
                                href="/price-race"
                                className="font-medium hover:underline"
                            >
                                The featured set
                            </Link>
                            <p className="text-xs text-muted-foreground">
                                Whatever is on the home page right now.
                            </p>
                        </li>
                        <li className="p-3">
                            <Link
                                href="/price-race/brand/pokemon"
                                className="font-medium hover:underline"
                            >
                                All Pokémon
                            </Link>
                            <p className="text-xs text-muted-foreground">
                                The most valuable cards that actually trade.
                            </p>
                        </li>
                    </ul>
                </div>

                {mine.length > 0 && (
                    <div>
                        <h2 className="mb-2 text-sm font-semibold">Yours</h2>
                        <ul className="divide-y divide-border rounded-xl border border-border">
                            {mine.map((r) => (
                                <RaceRow key={r.slug} race={r} />
                            ))}
                        </ul>
                    </div>
                )}

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Shared</h2>
                    {races.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
                            Nobody has shared a race yet.
                        </div>
                    ) : (
                        <ul className="divide-y divide-border rounded-xl border border-border">
                            {races.map((r) => (
                                <RaceRow key={r.slug} race={r} />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
