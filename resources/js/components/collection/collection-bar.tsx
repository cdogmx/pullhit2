import { Link, router } from '@inertiajs/react';
import { Check, Globe, Lock, MoreHorizontal, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type CollectionSummary = {
    id: number;
    name: string;
    slug: string;
    is_public: boolean;
    is_default: boolean;
    items_count: number;
};

/**
 * Tabs across a user's named collections + management (create / rename / share /
 * delete), gated by the tier's collection limit.
 */
export function CollectionBar({
    collections,
    active,
    limit,
}: {
    collections: CollectionSummary[];
    active: string;
    limit: number | null;
}) {
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const atLimit = limit !== null && collections.length >= limit;
    const current = collections.find((x) => x.slug === active);

    const create = () => {
        if (!name.trim()) {
            return;
        }
        router.post(
            '/collections',
            { name: name.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setName('');
                    setCreating(false);
                },
            },
        );
    };

    const rename = () => {
        if (!current) {
            return;
        }
        const next = window.prompt('Rename collection', current.name);
        if (next && next.trim() && next.trim() !== current.name) {
            router.patch(
                `/collections/${current.id}`,
                { name: next.trim() },
                { preserveScroll: true },
            );
        }
    };

    const togglePublic = () => {
        if (!current) {
            return;
        }
        router.patch(
            `/collections/${current.id}`,
            { is_public: !current.is_public },
            { preserveScroll: true },
        );
    };

    const remove = () => {
        if (!current || current.is_default) {
            return;
        }
        if (window.confirm(`Delete “${current.name}”? Its cards move to your default collection.`)) {
            router.delete(`/collections/${current.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            {collections.map((col) => (
                <Link
                    key={col.id}
                    href={`/collection?collection=${col.slug}`}
                    preserveScroll
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors',
                        col.slug === active
                            ? 'border-primary bg-primary/10 font-medium text-foreground'
                            : 'border-border text-muted-foreground hover:bg-accent/40',
                    )}
                >
                    {col.is_public && <Globe className="size-3" />}
                    {col.name}
                    <span className="text-xs text-muted-foreground">
                        {col.items_count}
                    </span>
                </Link>
            ))}

            {/* Manage the active collection */}
            {current && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem onClick={rename}>
                            Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={togglePublic}>
                            {current.is_public ? (
                                <>
                                    <Lock className="size-4" /> Make private
                                </>
                            ) : (
                                <>
                                    <Globe className="size-4" /> Make public
                                </>
                            )}
                        </DropdownMenuItem>
                        {!current.is_default && (
                            <DropdownMenuItem
                                onClick={remove}
                                className="text-red-600 focus:text-red-600"
                            >
                                <Trash2 className="size-4" /> Delete
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {/* New collection */}
            {creating ? (
                <span className="inline-flex items-center gap-1">
                    <Input
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && create()}
                        placeholder="Collection name"
                        className="h-8 w-40"
                    />
                    <Button size="icon" className="size-8" onClick={create}>
                        <Check className="size-4" />
                    </Button>
                </span>
            ) : (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCreating(true)}
                    disabled={atLimit}
                    title={
                        atLimit
                            ? `Your plan allows ${limit} collections — upgrade for more`
                            : undefined
                    }
                >
                    <Plus className="size-4" /> New
                </Button>
            )}
        </div>
    );
}
