import { Link, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Folder,
    FolderPlus,
    Globe,
    Lock,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { FolderRow } from '@/components/collection/holdings-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';

export type { FolderRow };

/**
 * The "Folders" panel for a collection. Folders are ALWAYS scoped to this one
 * collection — the header says so, and each collection keeps its own set. Each
 * folder links to its own page, has an independent public/private toggle + share
 * link, and can be deleted (its cards stay in the collection, just un-filed). A
 * "New folder" creator makes the scoping explicit at creation time.
 */
export function CollectionFolders({
    collectionId,
    collectionName,
    folders,
}: {
    collectionId: number;
    collectionName: string;
    folders: FolderRow[];
}) {
    const [copied, setCopied] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [confirmDelete, setConfirmDelete] = useState<FolderRow | null>(null);

    const create = () => {
        const trimmed = name.trim();

        if (!trimmed) {
            return;
        }

        router.post(
            '/collection/folders',
            { collection_id: collectionId, name: trimmed },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setName('');
                    setCreating(false);
                    toast.success('Folder created.');
                },
            },
        );
    };

    const toggle = (f: FolderRow) =>
        router.patch(
            `/collection/folders/${f.id}`,
            { is_public: !f.is_public },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        f.is_public
                            ? `"${f.name}" is now private.`
                            : `"${f.name}" is now public.`,
                    ),
            },
        );

    const remove = (f: FolderRow) =>
        router.delete(`/collection/folders/${f.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Folder deleted.');
                setConfirmDelete(null);
            },
        });

    const copy = (f: FolderRow) => {
        if (!f.public_url) {
            return;
        }

        navigator.clipboard.writeText(f.public_url).then(() => {
            setCopied(f.id);
            toast.success('Folder link copied.');
            setTimeout(() => setCopied((id) => (id === f.id ? null : id)), 2000);
        });
    };

    return (
        <div className="rounded-xl border border-border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2.5">
                <div>
                    <h2 className="text-sm font-semibold">
                        Folders in {collectionName}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Folders live inside this collection only — each collection
                        keeps its own. Share one with its own link, independent of
                        the collection's visibility.
                    </p>
                </div>
                {creating ? (
                    <span className="inline-flex items-center gap-1">
                        <Input
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    create();
                                } else if (e.key === 'Escape') {
                                    setCreating(false);
                                    setName('');
                                }
                            }}
                            placeholder="Folder name"
                            maxLength={60}
                            className="h-8 w-40"
                        />
                        <Button size="icon" className="size-8" onClick={create}>
                            <Check className="size-4" />
                        </Button>
                    </span>
                ) : (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setCreating(true)}
                    >
                        <FolderPlus className="size-4" />
                        New folder
                    </Button>
                )}
            </div>

            {folders.length === 0 ? (
                <p className="px-4 py-4 text-sm text-muted-foreground">
                    No folders yet. Create one above, or file a card into a folder
                    from its page — folders group cards within this collection.
                </p>
            ) : (
                <ul className="divide-y divide-border/60">
                    {folders.map((f) => (
                        <li
                            key={f.id}
                            className="flex flex-wrap items-center gap-2 px-4 py-2.5"
                        >
                            <Link
                                href={`/collection/folders/${f.id}`}
                                className="inline-flex items-center gap-2 font-medium hover:underline"
                            >
                                <Folder className="size-4 text-muted-foreground" />
                                {f.name}
                            </Link>
                            <Badge variant="secondary" className="text-[10px]">
                                {f.items_count}
                            </Badge>

                            <div className="ml-auto flex items-center gap-1.5">
                                {f.is_public && f.public_url && (
                                    <>
                                        <a
                                            href={f.public_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="max-w-56 truncate text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                        >
                                            {f.public_url.replace(/^https?:\/\//, '')}
                                        </a>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                            onClick={() => copy(f)}
                                            aria-label="Copy folder link"
                                        >
                                            {copied === f.id ? (
                                                <Check className="size-3.5 text-emerald-600" />
                                            ) : (
                                                <Copy className="size-3.5" />
                                            )}
                                        </Button>
                                    </>
                                )}
                                <Button
                                    size="sm"
                                    variant={f.is_public ? 'secondary' : 'outline'}
                                    className="h-7"
                                    onClick={() => toggle(f)}
                                >
                                    {f.is_public ? (
                                        <>
                                            <Globe className="size-3.5" /> Public
                                        </>
                                    ) : (
                                        <>
                                            <Lock className="size-3.5" /> Private
                                        </>
                                    )}
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 text-muted-foreground hover:text-red-600"
                                    onClick={() => setConfirmDelete(f)}
                                    aria-label="Delete folder"
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={confirmDelete !== null}
                onOpenChange={(o) => !o && setConfirmDelete(null)}
                title={
                    confirmDelete
                        ? `Delete folder "${confirmDelete.name}"?`
                        : 'Delete folder?'
                }
                description={`Its cards stay in ${collectionName} — they just lose the folder label.`}
                confirmLabel="Delete folder"
                destructive
                onConfirm={() => confirmDelete && remove(confirmDelete)}
            />
        </div>
    );
}
