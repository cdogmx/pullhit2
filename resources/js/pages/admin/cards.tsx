import { Head, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EditCardDialog } from '@/components/admin/edit-card-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { AdminCard } from '@/types';

type Props = {
    items: AdminCard[];
    pagination: { page: number; last_page: number; total: number };
    sets: { slug: string; name: string }[];
    filters: { q: string; set: string };
};

export default function AdminCards({ items, pagination, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const [editing, setEditing] = useState<AdminCard | null>(null);

    const apply = (extra: Record<string, string | number> = {}) =>
        router.get('/admin/cards', { q, set: filters.set, ...extra }, { preserveState: true, preserveScroll: true });

    const remove = (card: AdminCard) => {
        if (!confirm(`Delete ${card.name} ${card.number ?? ''}?`)) {
            return;
        }

        router.delete(`/admin/cards/${card.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Card deleted.'),
        });
    };

    return (
        <>
            <Head title="Admin · Cards" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex gap-2">
                    <Input
                        placeholder="Search by name or number"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && apply()}
                    />
                    <Button onClick={() => apply()} variant="secondary">
                        Search
                    </Button>
                </div>

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <p className="mb-2 text-xs text-muted-foreground">
                            {pagination.total.toLocaleString()} cards
                        </p>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">Card</th>
                                    <th className="py-2 pr-3 font-medium">Set</th>
                                    <th className="py-2 pr-3 font-medium">Rarity</th>
                                    <th className="py-2 pr-3 font-medium">Variant</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((c) => (
                                    <tr key={c.id} className="border-b border-border/60 last:border-0">
                                        <td className="py-2 pr-3">
                                            <div className="flex items-center gap-2">
                                                {c.image_url && (
                                                    <img
                                                        src={c.image_url}
                                                        alt=""
                                                        className="h-10 w-auto rounded"
                                                        loading="lazy"
                                                    />
                                                )}
                                                <div className="min-w-0">
                                                    <p className="font-medium">{c.name}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {c.number}
                                                        {c.language ? ` · ${c.language.toUpperCase()}` : ''}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-2 pr-3 text-muted-foreground">{c.set}</td>
                                        <td className="py-2 pr-3">{c.rarity}</td>
                                        <td className="py-2 pr-3">
                                            {c.variant && (
                                                <Badge variant="secondary" className="text-[10px]">
                                                    {c.variant}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setEditing(c)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => remove(c)}
                                                className="text-muted-foreground hover:text-red-600"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {pagination.last_page > 1 && (
                            <div className="mt-3 flex items-center justify-between text-sm">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page <= 1}
                                    onClick={() => apply({ page: pagination.page - 1 })}
                                >
                                    Previous
                                </Button>
                                <span className="text-muted-foreground">
                                    Page {pagination.page} of {pagination.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page >= pagination.last_page}
                                    onClick={() => apply({ page: pagination.page + 1 })}
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <EditCardDialog
                card={editing}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />
        </>
    );
}

AdminCards.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Cards', href: '/admin/cards' },
    ],
};
