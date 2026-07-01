import { Head } from '@inertiajs/react';
import { Loader2, Search, Send, Swords, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type SearchResult = {
    id: number;
    name: string;
    image: string | null;
    set: string | null;
    sealed_value: number | null;
};

type Dossier = {
    product: {
        id: number;
        name: string;
        image: string | null;
        sealed_type: string | null;
        pack_count: number | null;
    };
    sealed_value: number | null;
    confidence: number | null;
    is_estimated: boolean;
    currency: string;
    trend: { pct: number; days: number; direction: string } | null;
    set: {
        name: string | null;
        released_at: string | null;
        age_years: number | null;
        in_print: boolean;
    };
    chase: {
        top: { name: string; value: number }[];
        single_count: number;
        max_single: number | null;
        median_single: number | null;
        count_over_50: number;
    };
};

type ChatMessage = { role: 'user' | 'assistant'; content: string };

// Quick-reply chips that steer the Sensei's read of the collector.
const NUDGES = [
    "I'm in it for profit",
    'I love ripping packs',
    "It's for my collection",
    'Will this be reprinted?',
];

function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

export default function RipOrKeep() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [searching, setSearching] = useState(false);

    const [dossier, setDossier] = useState<Dossier | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [thinking, setThinking] = useState(false);
    const threadRef = useRef<HTMLDivElement>(null);

    // Debounced sealed-product search. All state changes happen inside the timer
    // (never synchronously in the effect body).
    useEffect(() => {
        const q = query.trim();
        const id = setTimeout(() => {
            if (dossier || q.length < 2) {
                setResults([]);

                return;
            }

            setSearching(true);
            fetch(`/rip-or-keep/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((d: { results: SearchResult[] }) => setResults(d.results))
                .catch(() => setResults([]))
                .finally(() => setSearching(false));
        }, 250);

        return () => clearTimeout(id);
    }, [query, dossier]);

    // Keep the newest message in view.
    useEffect(() => {
        threadRef.current?.scrollTo({ top: threadRef.current.scrollHeight });
    }, [messages, thinking]);

    const ask = async (productId: number, history: ChatMessage[]) => {
        setThinking(true);

        try {
            const res = await fetch(`/rip-or-keep/${productId}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ messages: history }),
            });
            const body = await res.json();
            const reply =
                res.ok && body.reply
                    ? body.reply
                    : (body.message ??
                      'The Sensei is unavailable right now. Try again shortly.');
            setMessages((m) => [...m, { role: 'assistant', content: reply }]);
        } catch {
            setMessages((m) => [
                ...m,
                {
                    role: 'assistant',
                    content: 'The dojo lost connection. Try again in a moment.',
                },
            ]);
        } finally {
            setThinking(false);
        }
    };

    const pick = async (r: SearchResult) => {
        setResults([]);
        setQuery('');
        // Load the stats dossier, then open with the Sensei's first ruling.
        const d: Dossier = await fetch(`/rip-or-keep/${r.id}/dossier`, {
            headers: { Accept: 'application/json' },
        }).then((res) => res.json());
        setDossier(d);
        const opener: ChatMessage = {
            role: 'user',
            content: `I'm looking at ${d.product.name}. Rip it or keep it sealed?`,
        };
        setMessages([opener]);
        void ask(r.id, [opener]);
    };

    const send = (text: string) => {
        const content = text.trim();

        if (!content || !dossier || thinking) {
            return;
        }

        const next: ChatMessage[] = [...messages, { role: 'user', content }];
        setMessages(next);
        setDraft('');
        void ask(dossier.product.id, next);
    };

    const reset = () => {
        setDossier(null);
        setMessages([]);
        setDraft('');
    };

    return (
        <>
            <Head title="Rip or Keep?" />

            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 p-4">
                <div className="text-center">
                    <img
                        src="/ninja.png"
                        alt="The CardFoo Sensei"
                        className="mx-auto mb-2 size-16 rounded-full object-cover ring-2 ring-primary/20"
                    />
                    <h1 className="text-2xl font-bold tracking-tight">
                        Rip or Keep?
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Ask the Sensei. Real market data, zero mercy. Wax on.
                    </p>
                </div>

                {!dossier ? (
                    <Card>
                        <CardContent className="space-y-3 pt-6">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    autoFocus
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search a sealed product (ETB, booster box, bundle…)"
                                    className="pl-9"
                                />
                                {searching && (
                                    <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                                )}
                            </div>

                            <ul className="divide-y divide-border/60">
                                {results.map((r) => (
                                    <li key={r.id}>
                                        <button
                                            type="button"
                                            onClick={() => pick(r)}
                                            className="flex w-full items-center gap-3 py-2 text-left transition-colors hover:bg-accent/40"
                                        >
                                            <div className="flex h-12 w-9 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                                                {r.image && (
                                                    <img
                                                        src={r.image}
                                                        alt=""
                                                        className="size-full object-contain"
                                                        loading="lazy"
                                                    />
                                                )}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {r.name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {r.set}
                                                </p>
                                            </div>
                                            {r.sealed_value != null && (
                                                <span className="shrink-0 text-sm font-medium tabular-nums">
                                                    {formatMoney(r.sealed_value)}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>

                            {query.trim().length >= 2 &&
                                !searching &&
                                results.length === 0 && (
                                    <p className="py-2 text-center text-sm text-muted-foreground">
                                        No sealed products match that.
                                    </p>
                                )}
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <DossierCard dossier={dossier} onReset={reset} />

                        <Card>
                            <CardContent className="flex flex-col gap-3 pt-6">
                                <div
                                    ref={threadRef}
                                    className="flex max-h-[26rem] flex-col gap-3 overflow-y-auto"
                                >
                                    {messages.map((m, i) => (
                                        <Bubble key={i} message={m} />
                                    ))}
                                    {thinking && (
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <img
                                                src="/ninja.png"
                                                alt=""
                                                className="size-6 rounded-full object-cover"
                                            />
                                            <Loader2 className="size-4 animate-spin" />
                                            The Sensei is deliberating…
                                        </div>
                                    )}
                                </div>

                                {/* Quick-reply nudges */}
                                <div className="flex flex-wrap gap-1.5">
                                    {NUDGES.map((n) => (
                                        <button
                                            key={n}
                                            type="button"
                                            disabled={thinking}
                                            onClick={() => send(n)}
                                            className="rounded-full border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:border-primary hover:text-foreground disabled:opacity-50"
                                        >
                                            {n}
                                        </button>
                                    ))}
                                </div>

                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        send(draft);
                                    }}
                                    className="flex items-center gap-2"
                                >
                                    <Input
                                        value={draft}
                                        onChange={(e) => setDraft(e.target.value)}
                                        placeholder="Tell the Sensei your goal…"
                                        maxLength={1000}
                                        disabled={thinking}
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        disabled={thinking || !draft.trim()}
                                        aria-label="Send"
                                    >
                                        <Send className="size-4" />
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </>
                )}

                <p className="text-center text-[11px] text-muted-foreground">
                    The Sensei is for fun, not financial advice. Pull rates are
                    unknown — ripping is always a gamble.
                </p>
            </div>
        </>
    );
}

function DossierCard({
    dossier,
    onReset,
}: {
    dossier: Dossier;
    onReset: () => void;
}) {
    const t = dossier.trend;
    const trendClass =
        t?.direction === 'up'
            ? 'text-emerald-600 dark:text-emerald-400'
            : t?.direction === 'down'
              ? 'text-red-600 dark:text-red-400'
              : 'text-muted-foreground';

    return (
        <Card>
            <CardContent className="flex gap-4 pt-6">
                <div className="flex h-28 w-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                    {dossier.product.image && (
                        <img
                            src={dossier.product.image}
                            alt=""
                            className="size-full object-contain"
                        />
                    )}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                            <p className="truncate font-semibold">
                                {dossier.product.name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {dossier.set.name}
                                {dossier.set.in_print
                                    ? ' · in print'
                                    : ' · out of print'}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 shrink-0"
                            onClick={onReset}
                        >
                            <X className="size-4" /> Change
                        </Button>
                    </div>

                    <div className="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                        <span>
                            <span className="text-xs text-muted-foreground">
                                Sealed{' '}
                            </span>
                            <span className="font-semibold tabular-nums">
                                {dossier.sealed_value != null
                                    ? formatMoney(dossier.sealed_value)
                                    : '—'}
                            </span>
                        </span>
                        {t && (
                            <span className={cn('tabular-nums', trendClass)}>
                                {t.pct > 0 ? '+' : ''}
                                {t.pct}% · {t.days}d
                            </span>
                        )}
                        {dossier.chase.max_single != null && (
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <Swords className="size-3.5" /> top pull{' '}
                                {formatMoney(dossier.chase.max_single)}
                            </span>
                        )}
                    </div>

                    {dossier.chase.top.length > 0 && (
                        <p className="mt-1.5 truncate text-xs text-muted-foreground">
                            Chase:{' '}
                            {dossier.chase.top
                                .slice(0, 3)
                                .map((c) => c.name)
                                .join(', ')}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function Bubble({ message }: { message: ChatMessage }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-primary px-3 py-2 text-sm text-primary-foreground">
                    {message.content}
                </div>
            </div>
        );
    }

    // The Sensei's verdict leads with a "🥋 …" headline; style it apart.
    const [head, ...rest] = message.content.split('\n');
    const isVerdict = head.startsWith('🥋');

    return (
        <div className="flex gap-2">
            <img
                src="/ninja.png"
                alt="The Sensei"
                className="mt-0.5 size-7 shrink-0 rounded-full object-cover"
            />
            <div className="max-w-[85%] rounded-2xl rounded-bl-sm bg-muted px-3 py-2 text-sm">
                {isVerdict ? (
                    <>
                        <p className="font-bold">{head}</p>
                        {rest.join('\n').trim() && (
                            <p className="mt-1 whitespace-pre-wrap">
                                {rest.join('\n').trim()}
                            </p>
                        )}
                    </>
                ) : (
                    <p className="whitespace-pre-wrap">{message.content}</p>
                )}
            </div>
        </div>
    );
}

