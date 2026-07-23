import { Link, router } from '@inertiajs/react';
import { Check, Globe, Lock, MoreHorizontal, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type ListSummary = {
    id: number;
    name: string;
    slug: string;
    is_public: boolean;
    is_default: boolean;
    items_count: number;
};

/**
 * Tabs across a user's named lists (collections / wishlists) with management —
 * create / rename / share / delete — gated by the tier limit. Generic over the
 * list noun and routes so collections and wishlists share one component.
 *
 * @param basePath   page path the tabs link to (e.g. '/collection')
 * @param queryKey   query param selecting the active list (e.g. 'collection')
 * @param entityBase CRUD route base for the list entity (e.g. '/collections')
 */
export function ListTabs({
    lists,
    active,
    limit,
    basePath,
    queryKey,
    entityBase,
    noun,
    createHint,
}: {
    lists: ListSummary[];
    active: string;
    limit: number | null;
    basePath: string;
    queryKey: string;
    entityBase: string;
    noun: string;
    /** Optional helper shown under the name input while creating a new list. */
    createHint?: string;
}) {
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [renaming, setRenaming] = useState(false);
    const [renameValue, setRenameValue] = useState('');
    const [busy, setBusy] = useState(false);
    const atLimit = limit !== null && lists.length >= limit;
    const current = lists.find((x) => x.slug === active);

    const create = () => {
        if (!name.trim()) {
            return;
        }

        router.post(
            entityBase,
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

    const openRename = () => {
        if (!current) {
            return;
        }

        setRenameValue(current.name);
        setRenaming(true);
    };

    const submitRename = (e: React.FormEvent) => {
        e.preventDefault();
        if (!current) {
            return;
        }

        const next = renameValue.trim();
        if (!next || next === current.name) {
            setRenaming(false);

            return;
        }

        setBusy(true);
        router.patch(
            `${entityBase}/${current.id}`,
            { name: next },
            {
                preserveScroll: true,
                onSuccess: () => setRenaming(false),
                onFinish: () => setBusy(false),
            },
        );
    };

    const togglePublic = () => {
        if (!current) {
            return;
        }

        router.patch(
            `${entityBase}/${current.id}`,
            { is_public: !current.is_public },
            { preserveScroll: true },
        );
    };

    const remove = () => {
        if (!current || current.is_default) {
            return;
        }

        setBusy(true);
        router.delete(`${entityBase}/${current.id}`, {
            preserveScroll: true,
            onSuccess: () => setConfirmDelete(false),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            {lists.map((list) => (
                <Link
                    key={list.id}
                    href={`${basePath}?${queryKey}=${list.slug}`}
                    preserveScroll
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors',
                        list.slug === active
                            ? 'border-primary bg-primary/10 font-medium text-foreground'
                            : 'border-border text-muted-foreground hover:bg-accent/40',
                    )}
                >
                    {list.is_public && <Globe className="size-3" />}
                    {list.name}
                    <span className="text-xs text-muted-foreground">
                        {list.items_count}
                    </span>
                </Link>
            ))}

            {current && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem onClick={openRename}>
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
                                onClick={() => setConfirmDelete(true)}
                                className="text-red-600 focus:text-red-600"
                            >
                                <Trash2 className="size-4" /> Delete
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {creating ? (
                <span className="inline-flex flex-col gap-0.5">
                    <span className="inline-flex items-center gap-1">
                        <Input
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && create()}
                            placeholder={`${noun} name`}
                            className="h-8 w-40"
                        />
                        <Button size="icon" className="size-8" onClick={create}>
                            <Check className="size-4" />
                        </Button>
                    </span>
                    {createHint && (
                        <span className="text-[11px] text-muted-foreground">
                            {createHint}
                        </span>
                    )}
                </span>
            ) : atLimit ? (
                <Button asChild variant="outline" size="sm">
                    <Link
                        href="/settings/billing"
                        title={`Your plan allows ${limit} ${noun}s`}
                    >
                        <Plus className="size-4" /> Upgrade for more
                    </Link>
                </Button>
            ) : (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCreating(true)}
                >
                    <Plus className="size-4" /> New
                </Button>
            )}

            <Dialog open={renaming} onOpenChange={setRenaming}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitRename}>
                        <DialogHeader>
                            <DialogTitle>
                                Rename {noun}
                                {current ? ` “${current.name}”` : ''}
                            </DialogTitle>
                            <DialogDescription>
                                Choose a new name for this {noun}.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2 py-4">
                            <Label htmlFor="list-rename">Name</Label>
                            <Input
                                id="list-rename"
                                autoFocus
                                value={renameValue}
                                onChange={(e) => setRenameValue(e.target.value)}
                                maxLength={60}
                                placeholder={`${noun} name`}
                            />
                        </div>
                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRenaming(false)}
                                disabled={busy}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    busy ||
                                    !renameValue.trim() ||
                                    renameValue.trim() === current?.name
                                }
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={
                    current
                        ? `Delete “${current.name}”?`
                        : `Delete ${noun}?`
                }
                description={`Its cards aren't lost — they move to your default ${noun}.`}
                confirmLabel={`Delete ${noun}`}
                destructive
                busy={busy}
                onConfirm={remove}
            />
        </div>
    );
}
