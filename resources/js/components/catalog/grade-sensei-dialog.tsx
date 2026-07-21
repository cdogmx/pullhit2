import { Loader2, Send, Swords } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Advice = {
    raw: number;
    ev_grade: number;
    ev_raw: number;
    advantage: number;
    breakeven_p10: number | null;
    verdict: 'grade' | 'sell' | 'toss_up';
    fee: number;
};

type Dossier = {
    card: { id: number; name: string; set: string | null };
    raw: { value: number | null } | null;
    graded: Record<string, { value: number | null; estimated: boolean }>;
    advice: Advice | null;
};

type ChatMessage = { role: 'user' | 'assistant'; content: string };

function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

/**
 * The Sensei's "grade or sell?" verdict for a raw single, in a dialog launched
 * from the card page. Loads the grading dossier (real raw + graded values and the
 * modeled EV / break-even), opens with the Sensei's ruling, then lets the user
 * describe the card's condition to sharpen it. Same engine as Rip or Keep.
 */
export function GradeSenseiDialog({
    itemId,
    open,
    onOpenChange,
}: {
    itemId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [dossier, setDossier] = useState<Dossier | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [thinking, setThinking] = useState(false);
    const scrollRef = useRef<HTMLDivElement>(null);
    // Guards the one-time load without a synchronous setState in the effect.
    const startedRef = useRef(false);

    const ask = async (history: ChatMessage[]) => {
        setThinking(true);

        try {
            const res = await fetch(`/grade/${itemId}/chat`, {
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

    // Load the dossier + opening ruling the first time the dialog opens. The ref
    // guard (not a state flag) keeps this out of a synchronous setState in render.
    useEffect(() => {
        if (!open || startedRef.current) {
            return;
        }

        startedRef.current = true;
        fetch(`/grade/${itemId}/dossier`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d: Dossier) => {
                setDossier(d);
                const opener: ChatMessage = {
                    role: 'user',
                    content: 'Should I grade this or sell it raw?',
                };
                setMessages([opener]);
                void ask([opener]);
            })
            .catch(() => {
                setMessages([
                    {
                        role: 'assistant',
                        content: 'The dojo is closed right now. Try again shortly.',
                    },
                ]);
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [messages, thinking]);

    const send = () => {
        const content = draft.trim();

        if (!content || thinking) {
            return;
        }

        const next: ChatMessage[] = [...messages, { role: 'user', content }];
        setMessages(next);
        setDraft('');
        void ask(next);
    };

    const advice = dossier?.advice ?? null;
    const psa10 = dossier?.graded?.['10']?.value ?? null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
                <DialogHeader className="border-b border-border px-4 py-3">
                    <DialogTitle className="flex items-center gap-2 text-base">
                        <Swords className="size-4 text-primary" />
                        Grade or sell? — ask the Sensei
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        The CardFoo Sensei's grade-or-sell verdict.
                    </DialogDescription>
                </DialogHeader>

                {/* Stats strip from the dossier */}
                {advice && (
                    <div className="grid grid-cols-3 gap-px border-b border-border bg-border text-center text-sm">
                        <Stat label="Raw (NM)" value={formatMoney(advice.raw)} />
                        <Stat
                            label="PSA 10"
                            value={psa10 != null ? formatMoney(psa10) : '—'}
                        />
                        <Stat
                            label="Break-even 10"
                            value={
                                advice.breakeven_p10 != null
                                    ? `${Math.round(advice.breakeven_p10 * 100)}%`
                                    : '—'
                            }
                        />
                    </div>
                )}

                {/* Chat transcript */}
                <div
                    ref={scrollRef}
                    className="flex-1 space-y-3 overflow-y-auto px-4 py-3"
                >
                    {messages.map((m, i) => (
                        <div
                            key={i}
                            className={cn(
                                'flex',
                                m.role === 'user'
                                    ? 'justify-end'
                                    : 'justify-start',
                            )}
                        >
                            <div
                                className={cn(
                                    'max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap',
                                    m.role === 'user'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted',
                                )}
                            >
                                {m.content}
                            </div>
                        </div>
                    ))}
                    {thinking && (
                        <div className="flex justify-start">
                            <div className="rounded-2xl bg-muted px-3 py-2 text-sm text-muted-foreground">
                                <Loader2 className="size-4 animate-spin" />
                            </div>
                        </div>
                    )}
                </div>

                {/* Input */}
                <div className="border-t border-border p-3">
                    <div className="flex items-center gap-2">
                        <Input
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && send()}
                            placeholder="Describe the condition (centering, corners…)"
                            disabled={thinking || !dossier}
                        />
                        <Button
                            size="icon"
                            onClick={send}
                            disabled={thinking || !dossier || !draft.trim()}
                            aria-label="Send"
                        >
                            <Send className="size-4" />
                        </Button>
                    </div>
                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                        Sensei reasons from real comps. Grades aren't guaranteed —
                        this is guidance, not financial advice.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="bg-background px-2 py-2">
            <div className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="font-semibold tabular-nums">{value}</div>
        </div>
    );
}
