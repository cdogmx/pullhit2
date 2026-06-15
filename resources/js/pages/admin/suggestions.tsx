import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Check, X } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

type DiffRow = { field: string; from: string | number | null; to: string | number | null };

type Suggestion = {
    id: number;
    submitted_by: string | null;
    submitted_at: string | null;
    note: string | null;
    diff: DiffRow[];
    catalog_item: {
        id: number | null;
        display_name: string | null;
        number: string | null;
        set: string | null;
        image_url: string | null;
    };
};

type Props = { suggestions: Suggestion[] };

const humanize = (s: string) =>
    s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const show = (v: string | number | null) =>
    v === null || v === '' ? '—' : String(v);

export default function AdminSuggestions({ suggestions }: Props) {
    const act = (id: number, action: 'approve' | 'reject') =>
        router.post(
            `/admin/suggestions/${id}/${action}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        action === 'approve'
                            ? 'Edit applied to the catalog.'
                            : 'Suggestion rejected.',
                    ),
            },
        );

    return (
        <>
            <Head title="Admin · Suggestions" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <p className="text-sm text-muted-foreground">
                    {suggestions.length.toLocaleString()} user-submitted edit
                    {suggestions.length === 1 ? '' : 's'} awaiting review.
                </p>

                {suggestions.length === 0 && (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            Nothing to review. 🎉
                        </CardContent>
                    </Card>
                )}

                {suggestions.map((s) => (
                    <Card key={s.id}>
                        <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row">
                            <div className="h-24 w-[72px] shrink-0 overflow-hidden rounded bg-muted">
                                {s.catalog_item.image_url && (
                                    <img
                                        src={s.catalog_item.image_url}
                                        alt={s.catalog_item.display_name ?? ''}
                                        className="size-full object-contain"
                                    />
                                )}
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={`/catalog/${s.catalog_item.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {s.catalog_item.display_name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {s.catalog_item.set}
                                            {s.catalog_item.number
                                                ? ` · ${s.catalog_item.number}`
                                                : ''}{' '}
                                            · by{' '}
                                            <span className="font-medium">
                                                {s.submitted_by ?? 'someone'}
                                            </span>{' '}
                                            {s.submitted_at}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() => act(s.id, 'approve')}
                                        >
                                            <Check className="size-4" /> Approve
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => act(s.id, 'reject')}
                                        >
                                            <X className="size-4" /> Reject
                                        </Button>
                                    </div>
                                </div>

                                {s.diff.length > 0 && (
                                    <div className="mt-3 space-y-1">
                                        {s.diff.map((d) => (
                                            <div
                                                key={d.field}
                                                className="flex flex-wrap items-center gap-2 text-sm"
                                            >
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {humanize(d.field)}
                                                </Badge>
                                                <span className="text-muted-foreground line-through">
                                                    {show(d.from)}
                                                </span>
                                                <ArrowRight className="size-3 text-muted-foreground" />
                                                <span className="font-medium">
                                                    {show(d.to)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {s.note && (
                                    <p className="mt-3 rounded-md bg-muted/50 p-2 text-sm text-muted-foreground">
                                        “{s.note}”
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

AdminSuggestions.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Suggestions', href: '/admin/suggestions' },
    ],
};
