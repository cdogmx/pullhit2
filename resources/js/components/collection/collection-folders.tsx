import { router } from '@inertiajs/react';
import { Check, Copy, Globe, Lock } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { FolderRow } from '@/pages/collection/index';

/**
 * Manage a collection's folders: each folder has its own public/private toggle
 * and, when public, a shareable link — so an owner can expose a single folder
 * without making the whole collection public.
 */
export function CollectionFolders({ folders }: { folders: FolderRow[] }) {
    const [copied, setCopied] = useState<number | null>(null);

    if (folders.length === 0) {
        return null;
    }

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
            <div className="border-b border-border px-4 py-2.5">
                <h2 className="text-sm font-semibold">Folders</h2>
                <p className="text-xs text-muted-foreground">
                    Share a single folder with its own link — independent of the
                    collection's visibility.
                </p>
            </div>
            <ul className="divide-y divide-border/60">
                {folders.map((f) => (
                    <li
                        key={f.id}
                        className="flex flex-wrap items-center gap-2 px-4 py-2.5"
                    >
                        <span className="font-medium">{f.name}</span>
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
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
