import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, Folder, FolderOpen, Library, LibraryBig } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';

/** A folder within a collection (scoped to it) — matches the server tree. */
type FolderNode = {
    id: number;
    name: string;
    slug: string;
    is_public: boolean;
    items_count: number;
};

/** A collection with its (own) folders — matches BuildCollectionTree. */
export type CollectionNode = {
    id: number;
    name: string;
    slug: string;
    is_default: boolean;
    is_public: boolean;
    items_count: number;
    folders: FolderNode[];
};

/** The path to a collection: the default lives at /collection, others carry ?collection=. */
function collectionHref(c: CollectionNode): string {
    return c.is_default ? '/collection' : `/collection?collection=${c.slug}`;
}

/**
 * The sidebar "Collections" tree — each of the user's collections, expandable to
 * the folders that live INSIDE it. Makes the collection → folder hierarchy (and
 * the fact that folders belong to one collection) visible in the nav. Data comes
 * from the shared `collectionTree` prop (structural: names + counts).
 */
export function NavCollections() {
    const page = usePage().props as { collectionTree?: CollectionNode[] | null };
    const tree = page.collectionTree ?? [];
    const url = usePage().url; // current path + query

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Collections</SidebarGroupLabel>
            <SidebarMenu>
                {/* Always-present overview entry. */}
                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        isActive={url === '/collection'}
                        tooltip={{ children: 'Collection' }}
                    >
                        <Link href="/collection" prefetch>
                            <LibraryBig />
                            <span>Collection</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>

                {tree.map((c) => {
                    const folderActive = c.folders.some((f) =>
                        url.startsWith(`/collection/folders/${f.id}`),
                    );
                    const collectionActive =
                        (c.is_default && url === '/collection') ||
                        url.includes(`collection=${c.slug}`);
                    const hasFolders = c.folders.length > 0;

                    const row = (
                        <SidebarMenuButton
                            asChild
                            isActive={collectionActive}
                            tooltip={{ children: c.name }}
                        >
                            <Link href={collectionHref(c)} prefetch>
                                <Library />
                                <span className="truncate">{c.name}</span>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {c.items_count}
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    );

                    if (!hasFolders) {
                        return <SidebarMenuItem key={c.id}>{row}</SidebarMenuItem>;
                    }

                    return (
                        <Collapsible
                            key={c.id}
                            asChild
                            defaultOpen={folderActive || collectionActive}
                            className="group/collapsible"
                        >
                            <SidebarMenuItem>
                                {row}
                                <CollapsibleTrigger asChild>
                                    <SidebarMenuAction
                                        showOnHover
                                        className="data-[state=open]:rotate-90"
                                    >
                                        <ChevronRight />
                                        <span className="sr-only">
                                            Toggle folders
                                        </span>
                                    </SidebarMenuAction>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <SidebarMenuSub>
                                        {c.folders.map((f) => {
                                            const active = url.startsWith(
                                                `/collection/folders/${f.id}`,
                                            );

                                            return (
                                                <SidebarMenuSubItem key={f.id}>
                                                    <SidebarMenuSubButton
                                                        asChild
                                                        isActive={active}
                                                    >
                                                        <Link
                                                            href={`/collection/folders/${f.id}`}
                                                            prefetch
                                                        >
                                                            {active ? (
                                                                <FolderOpen />
                                                            ) : (
                                                                <Folder />
                                                            )}
                                                            <span className="truncate">
                                                                {f.name}
                                                            </span>
                                                            <span className="ml-auto text-xs text-muted-foreground">
                                                                {f.items_count}
                                                            </span>
                                                        </Link>
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                            );
                                        })}
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
