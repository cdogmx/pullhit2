import { Loader2, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Target = {
    id: number;
    name: string;
    is_default: boolean;
    folders: string[];
};

type TargetsResponse = {
    targets: Target[];
    can_create: boolean;
    limit: number | null;
};

/** What the picker reports up: an existing collection id OR a new name, + folder. */
export type CollectionFolderChoice = {
    collectionId: number | null;
    newCollectionName: string | null;
    folder: string | null;
};

const NONE = '__none__';

/**
 * Picks a target collection and (optionally) a folder within it — dropdowns for
 * each, with a "+" to create a new one inline. Folders are scoped to the chosen
 * collection: the folder dropdown always lists that collection's own folders,
 * and switching collection resets the folder. Self-fetches `/collection/targets`
 * (collections + their folders + create allowance) once opened.
 */
export function CollectionFolderPicker({
    open,
    initialCollectionId = null,
    initialFolder = null,
    includeFolder = true,
    allowNewCollection = true,
    onChange,
}: {
    /** Fetch only once true (the parent dialog is open). */
    open: boolean;
    initialCollectionId?: number | null;
    initialFolder?: string | null;
    includeFolder?: boolean;
    /** The edit/move flow can't create collections — hide the collection "+". */
    allowNewCollection?: boolean;
    onChange: (choice: CollectionFolderChoice) => void;
}) {
    const [data, setData] = useState<TargetsResponse | null>(null);

    const [collectionId, setCollectionId] = useState<number | null>(
        initialCollectionId,
    );
    const [creatingCollection, setCreatingCollection] = useState(false);
    const [newCollection, setNewCollection] = useState('');

    const [folder, setFolder] = useState<string | null>(initialFolder);
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [newFolder, setNewFolder] = useState('');

    // Load targets once the dialog opens; seed the collection to the default.
    useEffect(() => {
        if (!open || data) {
            return;
        }

        let active = true;
        fetch('/collection/targets', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d: TargetsResponse) => {
                if (!active) {
                    return;
                }

                setData(d);

                if (initialCollectionId == null) {
                    const def =
                        d.targets.find((t) => t.is_default) ?? d.targets[0];

                    if (def) {
                        setCollectionId(def.id);
                    }
                }
            })
            .catch(() => {});

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Report the current choice upward whenever it changes.
    useEffect(() => {
        onChange({
            collectionId: creatingCollection ? null : collectionId,
            newCollectionName: creatingCollection
                ? newCollection.trim() || null
                : null,
            folder: creatingFolder ? newFolder.trim() || null : folder,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        collectionId,
        creatingCollection,
        newCollection,
        folder,
        creatingFolder,
        newFolder,
    ]);

    const selected = data?.targets.find((t) => t.id === collectionId);
    // A brand-new collection has no folders yet; otherwise use the collection's.
    const folderOptions = creatingCollection ? [] : (selected?.folders ?? []);

    // Switching collection clears the folder (folders belong to one collection).
    const chooseCollection = (id: number) => {
        setCollectionId(id);
        setFolder(null);
        setCreatingFolder(false);
        setNewFolder('');
    };

    if (!data) {
        return (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Loading collections…
            </div>
        );
    }

    return (
        <div className="grid gap-4">
            {/* Collection */}
            <div className="grid gap-2">
                <Label>Collection</Label>
                {creatingCollection ? (
                    <div className="flex items-center gap-1">
                        <Input
                            autoFocus
                            value={newCollection}
                            onChange={(e) => setNewCollection(e.target.value)}
                            placeholder="New collection name"
                            maxLength={60}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-9 shrink-0"
                            aria-label="Cancel new collection"
                            onClick={() => {
                                setCreatingCollection(false);
                                setNewCollection('');
                            }}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>
                ) : (
                    <div className="flex items-center gap-1">
                        <Select
                            value={collectionId ? String(collectionId) : ''}
                            onValueChange={(v) => chooseCollection(Number(v))}
                        >
                            <SelectTrigger className="flex-1">
                                <SelectValue placeholder="Choose a collection" />
                            </SelectTrigger>
                            <SelectContent>
                                {data.targets.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}
                                        {t.is_default ? ' (default)' : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {allowNewCollection && data.can_create && (
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-9 shrink-0"
                                aria-label="New collection"
                                title="New collection"
                                onClick={() => setCreatingCollection(true)}
                            >
                                <Plus className="size-4" />
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {/* Folder (scoped to the chosen collection) */}
            {includeFolder && (
                <div className="grid gap-2">
                    <Label>Folder</Label>
                    {creatingFolder ? (
                        <div className="flex items-center gap-1">
                            <Input
                                autoFocus
                                value={newFolder}
                                onChange={(e) => setNewFolder(e.target.value)}
                                placeholder="New folder name"
                                maxLength={60}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-9 shrink-0"
                                aria-label="Cancel new folder"
                                onClick={() => {
                                    setCreatingFolder(false);
                                    setNewFolder('');
                                }}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    ) : (
                        <div className="flex items-center gap-1">
                            <Select
                                value={folder ?? NONE}
                                onValueChange={(v) =>
                                    setFolder(v === NONE ? null : v)
                                }
                            >
                                <SelectTrigger className="flex-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>No folder</SelectItem>
                                    {folderOptions.map((name) => (
                                        <SelectItem key={name} value={name}>
                                            {name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-9 shrink-0"
                                aria-label="New folder"
                                title="New folder"
                                onClick={() => {
                                    setCreatingFolder(true);
                                    setFolder(null);
                                }}
                            >
                                <Plus className="size-4" />
                            </Button>
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Folders live inside the chosen collection.
                    </p>
                </div>
            )}
        </div>
    );
}
