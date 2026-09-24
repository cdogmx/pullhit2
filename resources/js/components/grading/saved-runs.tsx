import { router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Link2,
    Link2Off,
    Sparkles,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { csrf } from '@/lib/csrf';
import { cn } from '@/lib/utils';

export type SavedRun = {
    id: number;
    label: string | null;
    created_at: string | null;
    score: number | null;
    probs: Record<string, number>;
    observed: string[];
    guides_source: string | null;
    actual_company: string | null;
    actual_grade: number | null;
    actual_cert: string | null;
    probability_of_actual: number | null;
    notes: string | null;
    share_url: string | null;
};

const pct = (n: number) => `${Math.round(n * 100)}%`;

/**
 * Saved runs, and how well each one called it.
 *
 * The column that matters is the last: on a card that came back a 10, what
 * probability did we put on a 10? A pipeline that is any good puts most of its
 * weight on the grade that arrives, and nothing else here proves that.
 */
export function SavedRuns({ runs }: { runs: SavedRun[] }) {
    if (runs.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">
                    Saved runs
                    <span className="ml-2 font-normal text-muted-foreground">
                        {runs.filter((r) => r.actual_grade !== null).length} of{' '}
                        {runs.length} have a real grade
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr className="border-b border-border">
                            <th className="py-2 pr-4">card</th>
                            <th className="py-2 pr-4">guides</th>
                            <th className="py-2 pr-4">our call</th>
                            <th className="py-2 pr-4">graded</th>
                            <th className="py-2 pr-4">we gave it</th>
                            <th className="py-2">link</th>
                        </tr>
                    </thead>
                    <tbody>
                        {runs.map((run) => (
                            <SavedRow key={run.id} run={run} />
                        ))}
                    </tbody>
                </table>
            </CardContent>
        </Card>
    );
}

function SavedRow({ run }: { run: SavedRun }) {
    const [company, setCompany] = useState(run.actual_company ?? 'TAG');
    const [grade, setGrade] = useState(
        run.actual_grade !== null ? String(run.actual_grade) : '',
    );
    const [cert, setCert] = useState(run.actual_cert ?? '');
    const [busy, setBusy] = useState(false);
    const [sharing, setSharing] = useState(false);
    const [copied, setCopied] = useState(false);

    async function toggleShare(revoke: boolean) {
        setSharing(true);

        try {
            await fetch(`/admin/grade-predictor/predictions/${run.id}/share`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ revoke }),
            });

            router.reload({ only: ['saved'] });
        } finally {
            setSharing(false);
        }
    }

    // The grade we put most weight on — what the bench would have told you.
    const top = Object.entries(run.probs).sort((a, b) => b[1] - a[1])[0];

    async function record() {
        setBusy(true);

        try {
            await fetch(`/admin/grade-predictor/predictions/${run.id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    actual_company: company || null,
                    actual_grade: grade === '' ? null : Number(grade),
                    actual_cert: cert || null,
                }),
            });

            router.reload({ only: ['saved'] });
        } finally {
            setBusy(false);
        }
    }

    return (
        <tr className="border-b border-border/50 align-middle">
            <td className="py-2 pr-4">
                <div className="font-medium">{run.label ?? 'Untitled'}</div>
                <div className="text-xs text-muted-foreground">
                    {run.created_at} · saw{' '}
                    {run.observed.length > 0
                        ? run.observed.join(', ')
                        : 'nothing'}
                </div>
            </td>

            <td className="py-2 pr-4">
                {run.guides_source === 'ai' ? (
                    // Flagged, because an accepted proposal is not a
                    // measurement and is excluded from calibration.
                    <Badge variant="outline" className="gap-1 text-amber-600">
                        <Sparkles className="size-3" />
                        AI, unchecked
                    </Badge>
                ) : (
                    <Badge variant="secondary" className="gap-1">
                        <UserRound className="size-3" />
                        {run.guides_source === 'ai-adjusted'
                            ? 'AI, adjusted'
                            : 'placed'}
                    </Badge>
                )}
            </td>

            <td className="py-2 pr-4 tabular-nums">
                {top ? (
                    <>
                        <span className="font-medium">{top[0]}</span>
                        <span className="ml-1 text-muted-foreground">
                            {pct(top[1])}
                        </span>
                    </>
                ) : (
                    '—'
                )}
                {run.score !== null && (
                    <div className="text-xs text-muted-foreground">
                        score {run.score}
                    </div>
                )}
            </td>

            <td className="py-2 pr-4">
                <div className="flex flex-wrap items-center gap-1.5">
                    <Input
                        value={company}
                        onChange={(e) => setCompany(e.target.value)}
                        aria-label="Grading company"
                        className="h-8 w-20"
                    />
                    <Input
                        value={grade}
                        onChange={(e) => setGrade(e.target.value)}
                        placeholder="10"
                        aria-label="Grade"
                        className="h-8 w-16"
                    />
                    <Input
                        value={cert}
                        onChange={(e) => setCert(e.target.value)}
                        placeholder="cert"
                        aria-label="Certificate number"
                        className="h-8 w-28"
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={record}
                    >
                        <Check className="size-3.5" />
                        Save
                    </Button>
                </div>
            </td>

            <td className="py-2 pr-4 tabular-nums">
                {run.probability_of_actual !== null ? (
                    <span
                        className={cn(
                            'font-medium',
                            run.probability_of_actual >= 0.5
                                ? 'text-[#047857] dark:text-emerald-400'
                                : run.probability_of_actual >= 0.25
                                  ? 'text-[#b45309] dark:text-amber-400'
                                  : 'text-[#b91c1c] dark:text-red-400',
                        )}
                    >
                        {pct(run.probability_of_actual)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        {run.actual_grade !== null ? 'not priced' : '—'}
                    </span>
                )}
            </td>

            <td className="py-2">
                {run.share_url ? (
                    <div className="flex items-center gap-1">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard.writeText(
                                    run.share_url!,
                                );
                                setCopied(true);
                                setTimeout(() => setCopied(false), 1500);
                            }}
                        >
                            {copied ? (
                                <Check className="size-3.5" />
                            ) : (
                                <Copy className="size-3.5" />
                            )}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={sharing}
                            aria-label="Stop sharing"
                            onClick={() => toggleShare(true)}
                        >
                            <Link2Off className="size-3.5" />
                        </Button>
                    </div>
                ) : (
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={sharing}
                        onClick={() => toggleShare(false)}
                    >
                        <Link2 className="size-3.5" />
                        Share
                    </Button>
                )}
            </td>
        </tr>
    );
}
